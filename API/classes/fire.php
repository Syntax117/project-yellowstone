<?php
use \Slim\Http as HTTP;

class fire extends base
{
    protected $recognisedFields = array(
        'latitude' => 10,
        'longitude' => 10,
        'confidence' => 3,
        'temperature' => 6,
        'user_submitted' => 1
    );
    protected $searchKeys = array (
        'user_latitude' => true,
        'user_longitude' =>  true,
        'user_proximity' => true
    );
    protected $mandatoryFields = array(
        'latitude',
        'longitude'
    );
    protected function _inputValidation(array $parameters, &$errors, $id = null)
    {
        /* Turn away... this doesn't look pretty.
        TODO: Optimise (fire) input validation */
        if (isset($parameters['latitude']) && !is_numeric($parameters['latitude']))
            $errors['latitude'] = 'Latitude must be numeric.';
        if (isset($parameters['longitude']) && !is_numeric($parameters['longitude']))
            $errors['longitude'] = 'Longitude must be numeric.';
        if (isset($parameters['confidence']) && !is_numeric($parameters['confidence']))
            $errors['longitude'] = 'Confidence must be numeric.';
        if (isset($parameters['temperature']) && !is_numeric($parameters['temperature']))
            $errors['temperature'] = 'Temperature must be numeric.';
        if (isset($parameters['user_submitted']) && !(is_numeric($parameters['user_submitted']) && in_array($parameters['user_submitted'], array(0, 1)))) {
            $errors['user_submitted'] = 'User_submitted must be a binary digit.';
        }
    }

    protected function _customSearchTriggers(array $custom_filters, \QueryBuilder $builder)
    {
        if (isset($custom_filters['user_latitude']) && isset($custom_filters['user_longitude'])) {
            if (is_numeric($custom_filters['user_latitude']) && is_numeric($custom_filters['user_longitude'])) {
                /* Haversine formula */
                $builder->select_custom(array(array('fire'), array('6371 * acos( cos( radians(:latDec) ) * cos( radians( fire.latitude ) ) * cos( radians( fire.longitude ) - radians(:lonDec) ) + sin( radians(:latDec) ) * sin(radians(fire.latitude)) )  AS distance')));
                $builder->bind_parameters[':latDec'] = $custom_filters['user_latitude'];
                $builder->bind_parameters[':lonDec'] = $custom_filters['user_longitude'];
                $builder->bind_parameters[':proximity'] = (isset($custom_filters['user_proximity']) && is_numeric($custom_filters['user_proximity'])) ? $custom_filters['user_proximity'] : 50;
                /* Inject the distance into HAVING clause, mandating that all found moorings be below the provided proximity distance.*/
                $builder->inject('HAVING', 'distance < :proximity');
            }
        }
        return null;
    }
}