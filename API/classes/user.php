<?php
use \Slim\Http as HTTP;
class user extends base
{
    protected $recognisedFields = array(
        'forename' => 20,
        'surname' => 30,
        'password' => 255,
        'email' => 255,
        'group_id' => 11
    );
    protected $mandatoryFields = array(
        'forename',
        'surname',
        'password',
        'email',
        'group_id'
    );
    public $group_scope, $forename, $id;

    protected function _inputValidation (array $parameters, &$errors, $id = null) {
        /* Email: is actually an email... */
        if(isset($parameters['email'])) {
            if (filter_var($parameters['email'], FILTER_VALIDATE_EMAIL)) {
                /* Ensure the email is unique. */
                $checkEmail = $this->db_connection->prepare('SELECT id FROM user WHERE email = ?');
                $checkEmail->execute(array($parameters['email']));
                if ($checkEmail->rowCount() > 0) {
                    $returnedID = $checkEmail->fetchColumn();
                    if (is_numeric($returnedID) && $returnedID != $id)
                        $errors['email'] = 'EMail already exists in the database.';
                }
            } else {
                $errors['email'] = 'EMail doesn\'t match recognised format or is empty.';
            }
        }
        if(isset($parameters['group_id'])) {
            if (is_numeric($parameters['group_id'])) {
                /* If they're not authorised to update group IDs, are they changing it to what it already is? */
                if(!$this->__isAuthorized('update', 'user_group')) {
                    $getCurrGID = $this->db_connection->prepare('SELECT group_id FROM user WHERE id = ?');
                    $getCurrGID->execute(array($id));
                    /* It is different and they do not have permission to change it. */
                    if($getCurrGID->fetchColumn() != $parameters['group_id'])
                        $errors['group_id'] = 'Insufficient permissions to change group ID.';
                }
            } else
                $errors['group_id'] = 'Group ID is not numeric.';
        }
        /* Password: length > 8 */
        if(isset($parameters['password']) && !empty($parameters['password']) && strlen($parameters['password']) < 8)
            $errors['password'] = 'Password doesn\'t match parameters (greater than or equal to than 8 characters).';
    }

    protected function _customSearchTriggers(array $custom_filters, \QueryBuilder $builder)
    {
        /* Informs SQL to overwrite the password field with NULL. */
        $builder->select_custom(array(array('user'), array('NULL AS password')));
    }

    protected function create(HTTP\Request $request, HTTP\Response $response)
    {
        $errors = array();
        $createFields = $this::__validateInput($request->getParsedBody(), $errors, null, true);

        if(count($errors) == 0) {
            /* Hash password using standard PHP hashing function */
            $createFields["password"] = password_hash($createFields["password"], PASSWORD_DEFAULT);
            $this->query_builder->initialise('INSERT');
            $this->query_builder->insert(array('email' => $createFields['email'], 'forename' => $createFields['forename'], 'surname' => $createFields['surname'], 'password' => $createFields['password'], 'group_id' => $createFields['group_id']), 'user');
            $insertUser = $this->db_connection->prepare($this->query_builder->generateQuery());
            $this->query_builder->bindValues($insertUser);
            $insertUser->execute();
            /* Return OK if we have added a row */
            if($insertUser->rowCount() == 1)
                return $response->withStatus(200)->withJson(array($this->db_connection->lastInsertId()));
            else
                return $response->withStatus(500);
        } else {
            return $response->withStatus(400)->withJson(json_encode($errors));
        }
    }

    protected function update(HTTP\Request $request, HTTP\Response $response)
    {
        $errors = array();
        $id = $request->getParam("id");
        $updateFields = $this->__validateInput($request->getQueryParams(), $errors, $id);

        /* Ensure we actually have fields to update.. */
        if(count($updateFields) == 0)
            return $response->withStatus(500);

        if(count($errors) == 0) {
            if ($this->__exists($id)) {
                /* If the user has provided a password and it is not empty, hash it. */
                if (isset($updateFields['password']) && !empty($updateFields['password']))
                    $updateFields['password'] = password_hash($updateFields["password"], PASSWORD_DEFAULT);
                else
                    unset($updateFields['password']);
                $this->query_builder->initialise('UPDATE');
                $this->query_builder->update($updateFields, 'user', array ('EXACT' => array ('user' => array ('id' => $id))));
                $updateStatement = $this->db_connection->prepare($this->query_builder->generateQuery());
                $this->query_builder->bindValues($updateStatement);
                $updateStatement->execute();
            } else
                return $response->withStatus(404);
        } else {
            return $response->withStatus(500)->withJson(json_encode($errors));
        }
    }

    protected function get(HTTP\Request $request, HTTP\Response $response)
    {
        /* Fetch the user if he exists and return his *safe* data */
        $id = $request->getAttribute('id');
        $this->query_builder->initialise('SELECT');
        /* Select all fields from user but override the password as NULL.*/
        $this->query_builder->select(array('user' => array('*')), array(array('user'), array('NULL AS password')), 'user');
        if(empty($id)) {
            $getCustomers = $this->db_connection->query($this->query_builder->generateQuery());
            return $response->withJson($getCustomers->fetchAll(PDO::FETCH_ASSOC));
        } else {
            $this->query_builder->where(array('EXACT'=>array('user'=>array('id'=>$id))));
            $getCustomers = $this->db_connection->prepare($this->query_builder->generateQuery());
            $this->query_builder->bindValues($getCustomers);
            $getCustomers->execute();
            if ($getCustomers->rowCount() == 1)
                return $response->withJson($getCustomers->fetch(PDO::FETCH_ASSOC));
            else
                return $response->withStatus(404);
        }
    }

    /**
     * Converts the (previously) JSON roles array into a scope
     * @param array $roles
     * @var \PDO $database
     * @return array $scope
     */
    function _roles_to_scope (array $roles, &$database) {
        $scope = array();
        /* Gets all known roles */
        $fetchUser = $database->query('SELECT * FROM group_roles');
        while($group = $fetchUser->fetch()) {
            /* If the role exists in the provided roles, add it to the scope. */
            if(in_array($group['id'], $roles))
                $scope[$group['category']][$group['action']] = true;
        }
        return $scope;
    }

    public function validateCredentials (string $email, string $password) {
        if(empty($email) || empty($password))
            throw new ErrorException('Invalid call to validateCredentials function.');
        $getPassword = $this->db_connection->prepare('SELECT user.group_id, user.forename, user.password, user_group.roles, user.id FROM user LEFT JOIN user_group ON user_group.id = user.group_id WHERE email = ?');
        $getPassword->execute(array($email));
        if($getPassword->rowCount() == 1) {
            $details = $getPassword->fetchObject();
            /* If the password is valid, move the details into accessible global and return true. */
            if (password_verify($password, $details->password)) {
                $this->group_scope = $this->_roles_to_scope(json_decode($details->roles), $this->db_connection);
                $this->forename = $details->forename;
                $this->id = $details->id;
                return true;
            } else
                return false;
        } else
            return false;
    }
}