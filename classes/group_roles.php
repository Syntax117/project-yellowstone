<?php
class group_roles extends base
{
    protected function _inputValidation(array $parameters, &$errors, $id = null)
    {
        /* This class doesn't support input so validation isn't necessary */
        return null;
    }
}