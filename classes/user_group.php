<?php
use \Slim\Http as HTTP;

class user_group extends base
{
    protected $recognisedFields = array(
        "name" => 10,
        "roles" => false
    );
    protected $mandatoryFields = array(
        "name",
        "roles"
    );

    protected function _inputValidation(array $parameters, &$errors, $id = null)
    {
        if (isset($parameters['name']) && !ctype_alnum($parameters['name']))
            $errors['name'] = 'Name must be alphanumeric with no spaces.';
        if (isset($parameters['roles'])) {
            /* Ensure the roles are in JSON format by attempting a decode and checking for errors */
            $validateRoles = json_decode($parameters['roles']);
            if (json_last_error() != JSON_ERROR_NONE)
                $errors['roles'] = 'Roles is not in JSON format.';
        }
    }
}