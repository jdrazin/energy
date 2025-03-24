<?php

namespace Src;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MetOffice extends Root
{
    private array $api, $location;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->api = $this->apis[$this->strip_namespace(__NAMESPACE__,__CLASS__)];
        $this->location = $this->config['location'];
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */

    public function forecast(): void
    {
        if ($this->skipRequest(__NAMESPACE__, __CLASS__)) { // skip request if called recently
            $this->requestResult(__CLASS__, false); // update timestamp for failed request
            return;
        }
        $forecast = $this->getForecast();
        $this->insertPoints($forecast['features'][0]['properties']['timeSeries']);
        $this->requestResult(__CLASS__, true); // update timestamp for successful request
    }

    /**
     * @throws GuzzleException
     */
    private function getForecast(): array
    {
        $url = $this->api['endpoint'] .
            '?latitude=' . $this->location['coordinates']['latitude_degrees'] .
            '&longitude=' . $this->location['coordinates']['longitude_degrees'] .
            '&excludeParameterMetadata=true&includeLocationName=false';
        $headers = ['apikey' => $this->api['apikey'],
            'Accept' => 'application/json',
        ];
        $client = new Client();
        $response = $client->get($url, ['headers' => $headers]);
//        return json_decode(file_get_contents('/var/www/html/energy/test/metoffice.json'), true);  // todo
        return json_decode((string)$response->getBody(), true);
    }

    /**
     * @throws Exception
     */
    private function insertPoints($points): void
    {
        $sql = 'INSERT INTO `values` (`entity`, `type`, `datetime`, `value`) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE `value` = ?, `timestamp` = NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssdd', $entity, $type, $datetime, $value, $value)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $entity = 'TEMPERATURE_EXTERNAL_C';
        $type = 'FORECAST';
        foreach ($points as $point) {
            $datetime = rtrim($point['time'], 'Z');
            $value = (float)$point['screenTemperature'];
            if (!$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
        }
        $this->mysqli->commit();
    }
}