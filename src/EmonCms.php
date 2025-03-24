<?php

namespace Src;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EmonCms extends Root
{
    private array $api;
    private const        array FEED_IDS = ['temperature_external_c' => 505525,
        'electric_power_w' => 505527,
        'electric_energy_kwh' => 505528,
        'thermal_power_w' => 505529,
        'thermal_energy_kwh' => 505530,
        'temperature_flow_c' => 505531,
        'temperature_return_c' => 505532,
        'flow_rate_l_per_min' => 505533,
        'temperature_internal_c' => 505534,
        'diverter_valve' => 505535,
        'dhw_flag' => 505536];


    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->api = $this->apis[$this->strip_namespace(__NAMESPACE__,__CLASS__)];
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     * @throws \Exception
     */
    public function getData(): void
    {
        if ($this->skipRequest(__NAMESPACE__, __CLASS__)) {  // skip request if called recently
            $this->requestResult(__CLASS__, false); // update timestamp for failed request
            return;
        }
        $this->getHeating();
        $this->getTempExternal();
        $this->requestResult(__CLASS__, true); // update timestamp for successful request
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function getHeating(): void
    {
        $type = 'MEASURED';
        $sql = 'INSERT INTO `values` (`entity`, `type`, `datetime`, `value`) 
                              VALUES (?,        ?,      ?,          ?      )
                        ON DUPLICATE KEY UPDATE `value` = ?, `timestamp` = NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssdd', $entity, $type, $mid, $power_w, $power_w)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $entities = [
            'LOAD_HEATING_ELECTRIC_W' => 'electric_energy_kwh',
            'LOAD_HEATING_THERMAL_W'  => 'thermal_energy_kwh'
        ];
        $now = (new DateTime())->format(Root::MYSQL_FORMAT_DATETIME);
        foreach ($entities as $entity => $entity_id) {
            $slots = new Slots($this->latestValueDatetime($entity, $type, self::EARLIEST_DATE), $now);
            while (true) { // keep loading until last slot before now
                if (is_null($slot = $slots->next_slot())) {
                    break;
                }
                if (!is_null($power_w = $this->powerW($slot, $entity_id))) {
                    $mid = $slot['mid'];
                    if (!$stmt->execute()) {
                        $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                        $this->logDb('MESSAGE', $message, null, 'ERROR');
                        throw new Exception($message);
                    }
                }
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function getTempExternal(): void
    {
        $sql = 'INSERT INTO `values` (`entity`, `type`, `datetime`, `value`) VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE `value` = ?, `timestamp` = NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssdd', $entity, $type, $mid, $temperature_c, $temperature_c)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $entity = 'TEMPERATURE_EXTERNAL_C';
        $type = 'MEASURED';
        $now = (new DateTime())->format(Root::MYSQL_FORMAT_DATETIME);
        $slots = new Slots($this->latestValueDatetime($entity, $type, self::EARLIEST_DATE), $now);
        while (true) { // keep loading until last slot before now
            if (is_null($slot = $slots->next_slot())) {
                break;
            }
            $temperature_c = $this->temperatureExternalC($slot);
            $mid = $slot['mid'];
            if (!$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws GuzzleException
     */
    private function temperatureExternalC($slot): float
    {
        $query = [
            'apikey' => $this->api['apikey'],
            'id' => EmonCms::FEED_IDS['temperature_external_c'],
            'start' => $slot['start_unix_timestamp'],
            'end' => $slot['stop_unix_timestamp'],
            'interval' => DbSlots::SLOT_DURATION_MIN * self::SECONDS_PER_MINUTE
        ];
        $client = new Client();
        $get_response = $client->get($this->api['base_url'], ['query' => $query]);
        $response = json_decode($get_response->getBody(), true);
        return (((float)$response[0][1]) + ((float)$response[1][1])) / 2.0;
    }

    /**
     * @throws GuzzleException
     */
    private function powerW($slot, $entity_id): ?float
    {
        $query = [
            'apikey' => $this->api['apikey'],
            'id' => self::FEED_IDS[$entity_id],
            'start' => ($start = $slot['start_unix_timestamp']),
            'end' => ($stop = $slot['stop_unix_timestamp']),
            'interval' => DbSlots::SLOT_DURATION_MIN * self::SECONDS_PER_MINUTE
        ];
        $client = new Client();
        $get_response = $client->get($this->api['base_url'], ['query' => $query]);
        $response = json_decode($get_response->getBody(), true);
        if (!is_null($energy_start_j = $response[1][1] ?? null) &&
            !is_null($energy_stop_j = $response[0][1] ?? null)) {
            return round(1000.0 * ((float)($energy_start_j - $energy_stop_j) * ((float)self::SECONDS_PER_HOUR) / ((float)($stop - $start))), 1);
        } else {
            return null;
        }
    }
}