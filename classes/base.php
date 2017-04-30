<?php
use \Slim\Http\Request as Request;
use \Slim\Http\Response as Response;

/* Abstract CRUD-type base class */

abstract class base
{
    /** SLIM Container Interface
     * @var \Interop\Container\ContainerInterface
     */
    protected $ci;
    /** Authorization Scope */
    private $scope;
    /** Database Connection
     * @var \PDO
     */
    protected $db_connection;
    /** Fields recognised by the class as valid database columns */
    protected $recognisedFields; /* It is imperative that "id" is not a recognised field as it should never be updated. */
    /** Fields that must be present when creating resources */
    protected $mandatoryFields;
    /** The table acted upon when automatically generating queries */
    protected $database_table;
    /** @var \QueryBuilder */
    protected $query_builder;
    protected $searchKeys;

    /**
     * Preliminary code to be executed when the class is initialized.
     * @param \Interop\Container\ContainerInterface $ci
     * @param bool $scope
     */
    public final function __construct(\Interop\Container\ContainerInterface $ci, bool $scope = true)
    {
        /* Moves container interface to class visibility  */
        $this->ci = $ci;
        /* If scope is provided, move to class visibility */
        if ($scope)
            $this->scope = (array)$this->ci->get("jwt")->scope;
        /* Move database connection to class visibility from container interface */
        $this->db_connection = $this->ci->get("db");
        /* By default, our table matches the class name so set that as default (prevent names of mysql) */
        $this->database_table = (static::class != "mysql") ? static::class : null;
        /* Move query builder to class visibility */
        $this->query_builder = $this->ci->get("query_builder");
    }

    /**
     * Handles the delegation of tasks when the class is called as an object.
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function __invoke(Request $request, Response $response)
    {
        /* Switch between different code paths depending on the method used in the request */
        switch ($request->getMethod()) {
            case "GET":
                /* Only return the result of base/subclass get if that action is within their scope, otherwise return unauthorised status.*/
                return $this->__isAuthorized("get", $this->database_table) ? $this::get($request, $response) : $response->withStatus(401);
                break;
            case "POST":
                /* If the 'ID' sent is actually 'searches' then call the search function, otherwise perform a standard create. */
                if (!is_null($request->getAttribute('id')) && $request->getAttribute('id') == 'searches')
                    return $this->__isAuthorized("get", $this->database_table) ? $this::search($request, $response) : $response->withStatus(401);
                else
                    return $this->__isAuthorized("create", $this->database_table) ? $this::create($request, $response) : $response->withStatus(401);
                break;
            case "PUT":
                /* If the ID provided is numerically valid should the database check if it is within their scope and act accordingly. */
                if ($this->__validateID($request->getParam("id")))
                    return $this->__isAuthorized("update", $this->database_table) ? $this::update($request, $response) : $response->withStatus(401);
                break;
            case "DELETE":
                if ($this->__validateID($request->getParam("id")))
                    return $this->__isAuthorized("delete", $this->database_table) ? $this::delete($request, $response) : $response->withStatus(401);
                break;
            default:
                /* Unknown/unsupported method used in request, return a bad request status. */
                return $response->withStatus(400);
                break;
        }
        /*Only in the event of error should this return statement be reached.*/
        return $response->withStatus(404);
    }

    /**
     * Handles authorization of restricted actions.
     * @param $action
     * @param $category
     * @return bool
     */
    protected final function __isAuthorized($action, $category)
    {
        /* Return the result of a check to see if they have that category in their scope *and* the action within that category. */
        return (isset($this->scope[$category]->$action) && $this->scope[$category]->$action == true);
    }

    /**
     * Generates a standard search query, calling _customSearchTriggers for class-specific searches and filters.
     * @param array $parameters
     * @return string
     */
    protected function __generateSearchQuery(array $parameters, array $surplus_parameters, \QueryBuilder $builder)
    {
        $not_exact = (isset($parameters['custom_filters']['not_exact']));
        /* Call the custom search triggers to allow subclasses to set their own filters/triggers without overriding default search filters */
        $this->_customSearchTriggers(array_merge($parameters,$surplus_parameters), $builder);
        /* Remove custom filters otherwise it will confuse the later code which works literally on the remaining items */
        unset($parameters['custom_filters']);
        if (count($parameters) > 0) {
            if ($not_exact) {
                /* Loop through the parameters and wrap them in standard LIKE wrapping (allowing for differences on the left and right) */
                foreach ($parameters as $key => $value)
                    $parameters[$key] = "%$value%";
                /* Add the new parameters to the query, ensuring they use the LIKE operator rather than exact match. */
                $builder->where(array(
                    'LIKE' => array(
                        $this->database_table => $parameters
                    )
                ));
            } else {
                /* Add the parameters to the query using an exact match operator. */
                $builder->where(array(
                    'EXACT' => array(
                        $this->database_table => $parameters
                    )
                ));
            }
        }
        /* Return nothing as this function acts directly upon parameters and the builder, not returning any single thing. */
        return null;
    }

    /**
     * An optional function called in __generateSearchQuery to allow for class-specific searches and filters.
     * @param array $custom_filters
     * @param QueryBuilder $builder
     * @return null
     */
    protected function _customSearchTriggers(array $custom_filters, \QueryBuilder $builder)
    {
        /* Do nothing as this function should be overridden in the subclass if it requires custom filters. */
        return null;
    }

    /* Main Functions */

    /**
     * Gets single/multiple entries from the database
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function get(Request $request, Response $response)
    {
        $id = $request->getAttribute("id");
        $request_parameters = $request->getParams();
        /* Initialise the query builder in SELECT mode */
        $this->query_builder->initialise('SELECT');
        /* Set it to grab all fields from the current database table with no custom parameters by default */
        $this->query_builder->select(array($this->database_table => array('*')), array(), $this->database_table, array());

        if (isset($request_parameters['custom_filters']['limit']) && is_numeric($request_parameters['custom_filters']['limit'])) {
            if (isset($request_parameters['custom_filters']['offset']) && is_numeric($request_parameters['custom_filters']['offset'])) {
                /* Add limit and offset to the query if the user has both in their custom filters */
                $this->query_builder->limit($request_parameters['custom_filters']['limit'], $request_parameters['custom_filters']['offset']);
            } else {
                /* Just add limit to the query as offset is not provided. */
                $this->query_builder->limit($request_parameters['custom_filters']['limit']);
            }
        }

        if (empty($id)) {
            /* User hasn't provided an ID, assume all resources are requested.*/
            $getRecords = $this->db_connection->query($this->query_builder->generateQuery());
            /* Return the result in the universally understood JSON format. */
            return $response->withJson($getRecords->fetchAll(PDO::FETCH_ASSOC));
        } else {
            /* Add the WHERE clause to search for records which match the provided ID */
            $this->query_builder->where(array('EXACT'=>array($this->database_table=>array('id'=>$id))));
            /* Prepare the query for sending over to the database using the automatically generated query */
            $getRecords = $this->db_connection->prepare($this->query_builder->generateQuery());
            /* Bind the ID to the statement */
            $this->query_builder->bindValues($getRecords);
            /* Execute and return the result, with a 404 if there is not a single result as we expect only one. */
            $getRecords->execute();
            if ($getRecords->rowCount() == 1)
                return $response->withJson($getRecords->fetch(PDO::FETCH_ASSOC));
            else
                return $response->withStatus(404);
        }
    }

    /**
     * Searches entries in the database
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function search(Request $request, Response $response)
    {
        /* Turn around.... running out of time.*/
        $request_parameters = $request->getParsedBody();
        $surplus_parameters = array ();
        /* Validate the input parameters to filter out any anomalies/mistakes */
        if (isset($this->searchKeys) && is_array($this->searchKeys)) {
            foreach ($this->searchKeys as $key=>$value) {
                if (isset($request_parameters[$key]))
                    $surplus_parameters[$key] = $request_parameters[$key];
            }
        }
        $searchParameters = $this->__validateInput((is_array($request_parameters)) ? $request_parameters : array(), $errors, null, false);
        $this->query_builder->initialise('SELECT');
        $this->query_builder->select(array($this->database_table => array('*')), array(), $this->database_table);
        /* Make changes to the query_builder using the searchParameters and custom filters specified in subclasses */
        /* Add search keys to search parameters */
        $this->__generateSearchQuery($searchParameters, $surplus_parameters,  $this->query_builder);
        if (isset($request_parameters['limit']) && is_numeric($request_parameters['limit'])) {
            if (isset($request_parameters['offset']) && is_numeric($request_parameters['offset'])) {
                $this->query_builder->limit($request_parameters['limit'], $request_parameters['offset']);
            } else {
                $this->query_builder->limit($request_parameters['limit']);
            }
        }
        /* Since the ID is removed during standard sanitise operations, check for it and add manually if it exists. */
        if (isset($request_parameters['id']) && is_numeric($request_parameters['id']))
            $this->query_builder->where(array('EXACT' => array($this->database_table => array('id' => $request_parameters['id']))));
        /* If there are no errors in the input.. */
        if (count($errors) == 0) {
            $searchDatabase = $this->db_connection->prepare($this->query_builder->generateQuery());
            $this->query_builder->bindValues($searchDatabase);
            $searchDatabase->execute();
            /* Return the results */
            return $response->withStatus(200)->withJson($searchDatabase->fetchAll(PDO::FETCH_ASSOC));
        } else
            /* Return the errors */
            return $response->withStatus(500)->withJson(json_encode($errors));
    }

    /**
     * Creates entries in the database
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function create(Request $request, Response $response)
    {
        $createFields = $this->__validateInput($request->getParsedBody(), $errors, null, true);

        if (count($errors) == 0) {
            $this->query_builder->initialise('INSERT');
            $this->query_builder->insert($createFields, $this->database_table);
            $createRecord = $this->db_connection->prepare($this->query_builder->generateQuery());
            $this->query_builder->bindValues($createRecord);
            $createRecord->execute();
            if ($createRecord->rowCount() > 0)
                return $response->withStatus(200)->withJson(array($this->db_connection->lastInsertId('id')));
            else
                return $response->withStatus(500);
        } else {
            return $response->withStatus(500)->withJson(json_encode($errors));
        }
    }

    /**
     * Updates entries in the database
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function update(Request $request, Response $response)
    {
        $id = $request->getParam("id");
        $updateFields = $this::__validateInput($request->getQueryParams(), $errors, $id, false);

        /* If there are no fields to update then return a bad request status */
        if (count($updateFields) == 0)
            return $response->withStatus(400);
        if (count($errors) == 0) {
            if ($this->__exists($id)) {
                $this->query_builder->initialise('UPDATE');
                $this->query_builder->update($updateFields, $this->database_table, array ('EXACT' => array ($this->database_table=> array ('id' => $id))));
                $updateRecord = $this->db_connection->prepare($this->query_builder->generateQuery());
                $this->query_builder->bindValues($updateRecord);
                $updateRecord->execute();
            } else
                return $response->withStatus(404);
        } else {
            return $response->withStatus(500)->withJson(json_encode($errors));
        }
    }

    /**
     * Deletes entries from the database
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    protected function delete(Request $request, Response $response)
    {
        $id = $request->getParam('id');
        if ($this::__exists($id)) {
            $this->query_builder->initialise('DELETE');
            $this->query_builder->delete($this->database_table, array('EXACT'=>array($this->database_table=>array('id'=>$id))));
            $deleteRecord = $this->db_connection->prepare($this->query_builder->generateQuery());
            $this->query_builder->bindValues($deleteRecord);
            $deleteRecord->execute();
            if ($deleteRecord->rowCount() == 0)
                return $response->withStatus(500);
            else
                return $response->withStatus(200);
        } else
            return $response->withStatus(404);
    }

    /* Validation Functions */

    /**
     * Handles the input validation used throughout the main functions.
     * @param array $parameters
     * @param $errors
     * @param $id
     * @param bool $searchValid
     * @return array $errors
     */
    abstract protected function _inputValidation(array $parameters, &$errors, $id = null);

    /**
     * Combines multiple sub-functions to standardize input validation.
     * @param array $parameters
     * @param $errors
     * @param null $id
     * @param bool $enforceMandatory
     * @return array
     */
    protected function __validateInput(array $parameters, &$errors, $id = null, bool $enforceMandatory = false)
    {
        /* Overwrite the parameters with recognised ones and check their lengths */
        $parameters = $this->__sanitiseParameters($parameters, $this->recognisedFields, $errors);
        /* Check all mandatory fields exist if enforceMandatory is enabled and mandatory fields are available. */
        if ($enforceMandatory && !empty($this->mandatoryFields))
            $this->__checkMandatory($parameters, $this->mandatoryFields, $errors);
        /* Validate the contents of the remaining parameters and dump errors in the $errors array */
        $this->_inputValidation($parameters, $errors, $id);
        return $parameters;
    }

    /**
     * Basic input validation (is numeric and not empty)
     * @param $id
     * @return bool
     */
    private function __validateID($id)
    {
        return (!empty($id) && is_numeric($id));
    }

    /**
     * Checks if the row exists.
     * @param int $id
     * @return boolean;
     */
    protected function __exists($id)
    {
        if ($this->__validateID($id)) {
            $query = "SELECT EXISTS(SELECT * FROM {$this->database_table} WHERE id = ?)";
            $checkExists = $this->db_connection->prepare($query);
            $checkExists->execute(array($id));
            /* Returns the output (should be true/false) */
            return $checkExists->fetchColumn();
        }
        return false;
    }

    /**
     * Checks the input array for missing keys from the required array
     * Used typically to check if the user has filled all mandatory fields.
     * @param array $input
     * @param array $required
     * @param array $errors
     * @return boolean
     */
    private function __checkMandatory(array $input, array $required, &$errors)
    {
        $count = 0;
        /* Loop through input and ensure errors are thrown if user provides empty input on mandatory fields */
        foreach ($required as $value) {
            if (!isset($input[$value]) || empty($input[$value])) {
                $errors[$value] = "'$value' is mandatory";
            }
            $count++;
        }
        /* Returns false if there are more than zero 'mandatory' errors */
        return ($count == 0);
    }

    /**
     * Checks if the parameters recognised and match the supposed lengths
     * @param array $parameters
     * @param array $recognisedFields
     * @param $errors
     * @return array $outputParams
     */
    private function __sanitiseParameters (array $parameters, array $recognisedFields, &$errors)
    {
        /* Create temporary storage for new parameter list */
        $outputParams = array();
        foreach ($parameters as $parameter => $value) {
            /* If the parameter is recognised */
            if (isset($this->recognisedFields[$parameter])) {
                $max_length = $recognisedFields[$parameter];
                /* If the length is enforced (not false), check the length against its value and add error if problematic. */
                if ($max_length != false && strlen($value) > $max_length)
                    $errors[$parameter] = ucfirst($parameter) . " must be less than or equal to $max_length characters.";
                /* Add the parameter to the temporary storage as it is a recognised field. */
                $outputParams[$parameter] = $value;
            }
        }
        /* Return all the recognised parameters. */
        return $outputParams;
    }

}