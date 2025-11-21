<?php

namespace Src;

use DateMalformedStringException;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Solcast extends Solar
{
    private array $api;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct(null, null);
        $this->use_local_config();
        $this->class = $this->strip_namespace(__NAMESPACE__, __CLASS__);
        $this->api = $this->apis[$this->class];
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     * @throws \Exception
     */
    public function getSolarActualForecast($slots): void {
        $made_successful_request = false;
        if ($this->skipRequest()) { // skip request if called recently
            $this->requestResult(false); // update timestamp for failed request
            return;
        }
        try {
            $this->insertResults();
            $made_successful_request = true;
        }
        catch (exception $e) { // fallback to estimate from average historic actuals for same time of day and year
            $this->logDb('MESSAGE', $e->getMessage() . ': estimating from historic seasonal',  null, 'WARNING');
            // delete all previous estimates
            $sql = 'DELETE FROM `values` 
                    WHERE `entity` = \'SOLAR_W\'  AND
                          `type`   = \'ESTIMATE\'';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            unset($stmt);
            $powers = [];
            foreach ($slots as $slot) {
                $mid      = $slot['mid'];
                $power_w  = (new Solar(null, null))->db_historic_average_power_w($mid);
                $powers[] = [
                    'datetime' => $mid,
                    'type'     => 'ESTIMATE',
                    'power_w'  => $power_w
                ];
            }
            $this->insertPowers($powers);
            (new Root())->logDb('MESSAGE', $this->class . ': estimating in absence of forecast',  null,'WARNING');
        }
        $this->requestResult($made_successful_request); // update whether request succeeded
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception|GuzzleException
     */
    private function insertResults(): void {
        switch (Types::FORECAST->value) {
            case 'ACTUAL':
            {
                $data_type = 'estimated_actuals';
                break;
            }
            case 'FORECAST':
            {
                $data_type = 'forecasts';
                break;
            }
            default:
                throw new Exception('unknown Solcast data type');
        }
        $response_json = $this->request($data_type);
        $responses = json_decode($response_json, true)[$data_type];
        $powers = [];
        $time_earliest = $time_latest = null;
        foreach ($responses as $response) {
            if ($response['period'] == 'PT30M') {
                $period_duration_mins = 30;
                $period_end = $response['period_end'];
                $datetime = new DateTime($period_end);
                $datetime->modify('-' . $period_duration_mins / 2 . ' minute');
                $powers[] = [
                    'datetime'  => $datetime->format(Root::MYSQL_FORMAT_DATETIME),
                    'type'      => Types::FORECAST->value,
                    'power_w'   => 1000.0 * (float)$response['pv_estimate']  // convert to mks
                ];
                if ($time_earliest) {
                    if ($datetime < $time_earliest) {
                        $time_earliest = $datetime;
                    }
                } else {
                    $time_earliest = $datetime;
                }
                if ($time_latest) {
                    if ($datetime > $time_latest) {
                        $time_latest = $datetime;
                    }
                } else {
                    $time_latest = $datetime;
                }
            } else {
                throw new Exception('bad Solcast value(s)');
            }
        }
        $this->insertPowers($powers);
    }

    /*
     * update powers to database
     */
    /**
     * @throws Exception
     */
    private function insertPowers($powers): void    {
        $sql = 'INSERT INTO `values`     (`entity`,     `type`, `value`,    `status`, `datetime`, `forecast`)
                                 VALUES  (\'SOLAR_W\',       ?,       ?, \'CURRENT\',         ?,           ?)
                   ON DUPLICATE KEY UPDATE  `value`     = ?,
                                            `forecast`  = ?,
                                            `timestamp` = CURRENT_TIMESTAMP';

        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sdssds', $type, $power, $datetime, $forecast, $power, $forecast)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        foreach ($powers as $power) {
            $datetime = $power['datetime'];
            $type     = $power['type'];
            $power    = $power['power_w'];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws GuzzleException
     */
    private function request($data_type): string
    {
        $url_pre = $this->api['base'] . $this->api['site_id'] . '/';
        $url_post = '?format=json';
        $url = $url_pre . $data_type . $url_post;
        $path = Root::PATH_PROJECT . Root::FOLDER_TEST . strtolower($this->class) . '.' . $data_type . '.txt';
        if (DEBUG_MINIMISER || file_exists($path)) {
            $response = file_get_contents($path);
        } else {
            $client = new Client();
            $headers = ['Authorization' => 'Bearer ' . $this->api['bearer_token']];
            $get_response = $client->get($url, ['headers' => $headers]);
            $response = (string)$get_response->getBody();
        }
        return $response;
    }
}