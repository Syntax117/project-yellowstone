<?php
use \Slim\Http as HTTP;

class scrape extends base
{
    protected $recognisedFields = array();
    protected $mandatoryFields = array();

    private function UTCtoUK (string $acq_date, int $acq_time):DateTime
    {
        $acq_time = sprintf("%04d", $acq_time);
        $acq_time = substr($acq_time,0,2).':'.substr($acq_time,2,2);
        $date = DateTime::createFromFormat('Y-m-d H:i', "$acq_date $acq_time");
        return (strlen($acq_date) == 10) ? $date : new DateTime('now');
    }

    protected function _inputValidation(array $parameters, &$errors, $id = null)
    {
        return null;
    }

    protected function get(\Slim\Http\Request $request, \Slim\Http\Response $response)
    {
        $hour_interval = new DateInterval('PT1H');
        try {
            $raw_csv = file_get_contents('http://firms.modaps.eosdis.nasa.gov/active_fire/viirs/text/VNP14IMGTDL_NRT_Global_24h.csv');
        } catch (Exception $e) {
            return $response->withStatus(500, ('Unable to gather global_24h VIIRS data. ' . $e->getMessage()));
        }
        $csv = !empty($raw_csv) ? explode('
', $raw_csv) : null;
        $fail_count = 0;
        $success_count = 0;
        if (!is_null($csv) && is_array($csv)) {
            unset($csv[0]);
            foreach ($csv as $fire) {
                $fire = explode(',', $fire);
                $date_acquired = ($this->UTCtoUK($fire[5], (int)$fire[6]))->add($hour_interval);
                $confidence = null;
                switch ($fire[8]) {
                    case 'high':
                        $confidence = 90;
                        break;
                    case 'nominal':
                        $confidence = 60;
                        break;
                    case 'low':
                        $confidence = 30;
                        break;
                    default:
                        $confidence = 50;
                        break;
                }
                try {
                    $insert_fire = $this->db_connection->prepare('INSERT INTO fire (latitude, longitude, confidence, temperature, user_submitted, date_acquired) VALUES (?, ?, ?, ?, ?, ?)');
                    $insert_fire->execute(array((float)$fire[0], (float)$fire[1], $confidence, $fire[2], 0, $date_acquired->format('Y-m-d H:i:s')));
                    $success_count++;
                } catch (PDOException $e) {
                    $fail_count++;
                }
            }
        }
        return $response->withJson(array('success_count' => $success_count, 'failure_count' => $fail_count));
    }
}