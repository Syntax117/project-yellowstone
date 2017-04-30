<?php
use Firebase\JWT\JWT;
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
spl_autoload_register(function ($classname) {
    require("../classes/" . $classname . ".php");
});

$app = new \Slim\App(["settings" => $config]);
$container = $app->getContainer();
$container["jwt"] = function ($container) {
    return new StdClass;
};

$app->add(new \Slim\Middleware\JwtAuthentication([
    "secure" => false,
    "rules" => [
        new \Slim\Middleware\JwtAuthentication\RequestPathRule([
            "path" => "/",
            "passthrough" => ["/token"]
        ])
    ],
    "secret" => supersecret,
    "callback" => function ($request, $response, $arguments) use ($container) {
        $container["jwt"] = $arguments["decoded"];
    }
]));

$query_builder = new querybuilder("SELECT");
$container['query_builder'] = $query_builder;

$container['db'] = new PDO("mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['dbname'],
    $config['db']['user'], $config['db']['pass']);
$container['db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$container['db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$app->post("/token", function (\Slim\Http\Request $request, \Slim\Http\Response $response) {
    /* Gathers the email and password from the request parameters */
    $email = $request->getParam("email");
    $password = $request->getParam("password");

    if(!empty($email) && !empty($password)) {
        /* Initialises the user class and instructs it to temporarily disregard the lack of an active scope (they are not logged in yet) */
        $user = new user($this, false);

        if ($user->validateCredentials($email, $password)) {
            /* Gets a current datetime and future datetime (6 hours from now) */
            $now = new DateTime();
            $future = new DateTime("now +2 days");
            /* Loads up the token payload with expiry parameters, scope and user id. */
            $payload = [
                "iat" => $now->getTimestamp(),
                "exp" => $future->getTimestamp(),
                "scope" => $user->group_scope,
                "id" => $user->id
            ];
            /* Encodes it using the supersecret key */
            $token = JWT::encode($payload, supersecret, "HS256");

            $data["status"] = "ok";
            /* Includes details about the user for the initial session to prevent unnecessary requests. */
            $data['user_details'] = json_encode(array(
                "id" => $user->id,
                "forename" => $user->forename,
                "scope" => json_encode($user->group_scope)
            ));
            $data["token"] = $token;
            /* Returns the token to the user, equipped with the signed payload and user_details. */
            return $response->withStatus(201)
                ->withHeader("Content-Type", "application/json")
                ->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            return $response->withStatus(401);
        }
    } else {
        return $response->withStatus(401);
    }
});

$app->map(['GET', 'POST', 'PUT', 'DELETE'],'/fires[/{id}]', '\fire');

$app->get('/scrape', '\scrape');

$app->map(['GET', 'POST', 'PUT', 'DELETE'],'/users[/{id}]', '\user');

$app->map(['GET'], '/group_roles[/{id}]', '\group_roles');

$app->run();