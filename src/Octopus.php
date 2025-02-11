<?php
namespace Src;
use DateMalformedStringException;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Octopus extends Root
{
    const string    URL_BASE_PRODUCTS   = 'https://api.octopus.energy/v1/products/',
                    ELECTRICITY_TARIFFS = 'electricity-tariffs/';
    const array     DIRECTIONS = [
                                    'import' => [
                                        'tariffs' => 'tariff_imports',
                                        'rates' => 'tariff_rates_import'
                                    ],
                                    'export' => [
                                        'tariffs' => 'tariff_exports',
                                        'rates' => 'tariff_rates_export'
                                    ]
                                ],
                    RATE_PERS = [
                                    'KWH' => 'standard-unit-rates/',
                                    'DAY' => 'standing-charges/',
                                ],
                    ENTITIES = [
                                    'GRID_W'                    => 'grid_kw',
                                    'SOLAR_W'                   => 'solar_kw',
                                    'TOTAL_LOAD_W'              => 'total_load_kw'
                               ];

    const ?int SINGLE_TARIFF_COMBINATION_ID = null;
    private array $api, $tariff_combinations;

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function __construct()
    {
        parent::__construct();
        $this->api = $this->apis[$this->strip_namespace(__NAMESPACE__,__CLASS__)];
        $this->combinations();
        $this->requestTariffs();
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function traverseTariffCombinations(): void
    {
        $db_slots   = new DbSlots();                                         // make day slots
        $giv_energy = new GivEnergy();
        $powers     = new Powers();
        $emoncms    = new EmonCms();
        $metoffice  = new MetOffice();
        // $giv_energy->initialise();
        $giv_energy->getData();                                              // grid, total_load, solar (yesterday, today) > `values`
        $emoncms->getData();                                                 // home heating and temperature > `values`
        $powers->makeHeatingPowerLookupDaySlotExtTemp();                     // make heating power look up table vs dayslot and external temperature
        $metoffice->forecast();                                              // get temperature forecast
        foreach ($this->tariff_combinations as $tariff_combination) {
            if (is_null(self::SINGLE_TARIFF_COMBINATION_ID) || ($tariff_combination['id'] == self::SINGLE_TARIFF_COMBINATION_ID)) {
                (new Root())->LogDb('OPTIMISING', $tariff_combination['name'], 'NOTICE');
                $db_slots->makeDbSlotsNext24hrs($tariff_combination);         // make slots for this tariff combination
                $this->makeSlotRates($db_slots);                              // make tariffs
                $powers->estimatePowers($db_slots);                           // forecast slot solar, heating, non-heating and load powers
                $energy_cost = new EnergyCost($db_slots);
                $slot_command = $energy_cost->optimise();
                if (!EnergyCost::DEBUG) {
                    if ($tariff_combination['active']) {                       // make battery command
                        //   $giv_energy->control($slot_command);
                    }
                    $this->makeDbSlotsLast24hrs();                             // make historic slots for last 24 hours
                }
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function requestTariffs(): void  // get tariffs for both directions
    {
        if ($this->skip_request(__CLASS__)) {  // skip request if called recently
            return;
        }
        foreach (self::DIRECTIONS as $tariffs_rates) {
            $this->getTariff($tariffs_rates);
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function getTariff($tariffs_rates): void
    {
        $region_code = $this->api['region_code'];
        $energy_type_prefix = $this->api['energy_type_prefix'];
        $energy_type_postfix = $this->api['energy_type_postfix'];
        $tariff_codes = $this->tariffCodes($tariffs_rates['tariffs']);
        foreach ($tariff_codes as $tariff_id => $tariff_code) {
            $url_tariff_prefix = self::URL_BASE_PRODUCTS .
                $tariff_code . '/' .
                self::ELECTRICITY_TARIFFS .
                $energy_type_prefix . '-' . $energy_type_postfix . '-' . $tariff_code . '-' . $region_code . '/';
            foreach (self::RATE_PERS as $rate_per => $endpoint) {
                $tariffs = json_decode($this->request($url_tariff_prefix . $endpoint), true)['results'];
                $this->insert($tariffs_rates['rates'], $tariff_id, $rate_per, $tariffs);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function combinations(): void
    {
        $sql = 'SELECT     `tc`.`id`,
                            CONCAT(`ti`.`code`, \', \', `te`.`code`, IF(`tc`.`active` IS NULL, \'\', \' *ACTIVE*\')),
                           `tc`.`import`,
                           `tc`.`export`,
                           `tc`.`active`
                  FROM     `tariff_combinations` `tc`
                  JOIN     `tariff_imports` `ti` ON `tc`.`import` = `ti`.`id`
                  JOIN     `tariff_exports` `te` ON `tc`.`export` = `te`.`id`
                  WHERE    `tc`.`status` = \'CURRENT\' AND 
                            NOT `tc`.`ignore`
                  ORDER BY `tc`.`active`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($id, $name, $import, $export, $active) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->tariff_combinations = [];
        while ($stmt->fetch()) {
            $this->tariff_combinations[] = ['id' => $id,
                'name' => $name,
                'active' => $active,
                'import' => $import,
                'export' => $export];
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */

    /**
     * @throws Exception
     */
    private function insert($rates_table, $tariff_id, $per, $tariffs): void
    {
        // insert rates
        $sql = 'INSERT INTO `' . $rates_table . '` (`tariff`, `start`, `stop`, `rate`, `per`) 
                                            VALUES (?,         ?,       ?,      ?,      ?)
                    ON DUPLICATE KEY UPDATE `rate` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('issdsd', $tariff_id, $start, $stop, $rate, $per, $rate)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($tariffs as $tariff) {
            $rate = $tariff['value_inc_vat'] / 100.0;   // convert from pence to GBP
            $start = $this->timeToDatetime($tariff['valid_from']);
            $stop = $this->timeToDatetime($tariff['valid_to']);
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws DateMalformedStringException
     */
    private function timeToDatetime($time): ?string
    {
        return $time ? $this->dateTime($time) : '1970-01-01 00:00:00';
    }

    /**
     * @throws DateMalformedStringException
     * @throws \DateMalformedStringException
     */
    private function dateTime($datetime_string): string|null
    {
        return $datetime_string ? (new DateTime($datetime_string))->format(Root::MYSQL_FORMAT_DATETIME) : null;
    }

    /**
     * @throws GuzzleException
     */
    private function request($url): string
    {
        $client = new Client(['auth' => [$this->api['basic_auth_user'], $this->api['basic_auth_pw']]]);
        $response = $client->get($url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [],
            ]
        );
        return $response->getBody();
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     */
    public function makeSlotRates($db_slots): void        // get future rates for tariff combination
    {
        $tariff_combination_id = $db_slots->tariff_combination['id'];
        $sql = 'UPDATE      `slots`
                    SET     `import_gbp_per_kwh` = ?,
                            `export_gbp_per_kwh` = ?,
                            `import_gbp_per_day` = ?,
                            `export_gbp_per_day` = ?
                    WHERE   `slot` = ? AND
                            `tariff_combination` = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ddddii', $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day, $slot, $tariff_combination_id)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($db_slots->slots as $slot => $v) {
            $start    = $v['start'];
            $stop     = $v['stop'];
            $ratesPer = ['start' => $start, 'stop' => $stop];
            foreach (self::DIRECTIONS as $direction => $x) {
                $tariff_table = self::DIRECTIONS[$direction]['rates'];
                foreach (self::RATE_PERS as $unit => $y) {
                    if (is_null($ratesPer[$unit][$direction] = $this->ratePerUnit($unit, $start, $stop, $db_slots->tariff_combination[$direction], $tariff_table, 0))) {      // get rate
                        if (is_null($ratesPer[$unit][$direction] = $this->ratePerUnit($unit, $start, $stop, $db_slots->tariff_combination[$direction], $tariff_table, -1))) { // if none, try same slot in previous date
                            throw new \Exception('no ' . $direction . ' tariff between ' . $start . ' and ' . $stop);                                                          // otherwise throw exception
                        }
                    }
                }
            }
            // insert rate into slot
            $import_gbp_per_kwh = $ratesPer['KWH']['import'];
            $export_gbp_per_kwh = $ratesPer['KWH']['export'];
            $import_gbp_per_day = $ratesPer['DAY']['import'];
            $export_gbp_per_day = $ratesPer['DAY']['export'];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function ratePerUnit($unit, $start, $stop, $tariff, $rates_table, $day_offset): float|null
    {
        switch ($unit) {
            case 'KWH':
            {
                $sql = 'SELECT   `rate`
                          FROM   `' . $rates_table . '`
                          WHERE  `tariff` = ? AND 
                                 `start` <= (? + INTERVAL ? DAY) AND
                                 `stop`  >= (? + INTERVAL ? DAY) AND
                                 `per`    = ?';
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_param('isisis', $tariff, $start, $day_offset, $stop, $day_offset, $unit) ||
                    !$stmt->bind_result($rate) ||
                    !$stmt->execute()) {
                    $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $message, 'ERROR');
                    throw new Exception($message);
                }
                break;
            }
            case 'DAY':
            {
                $sql = 'SELECT   `rate`
                          FROM   `' . $rates_table . '`
                          WHERE  `tariff` = ? AND 
                                 `per`    = ?
                          LIMIT 1';
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_param('is', $tariff, $unit) ||
                    !$stmt->bind_result($rate) ||
                    !$stmt->execute()) {
                    $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $message, 'ERROR');
                    throw new Exception($message);
                }
                break;
            }
            default:
                $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Bad unit: ' . $unit);
                throw new Exception($message);
        }
        $stmt->fetch();
        return $rate;
    }

    /**
     * @throws Exception
     */
    private function tariffCodes($tariff_code_table): array
    {
        $sql = 'SELECT `id`, `code`
                  FROM `' . $tariff_code_table . '`
                  WHERE `status` = \'CURRENT\'';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($id, $tariff_code) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $tariff_codes = [];
        while ($stmt->fetch()) {
            $tariff_codes[$id] = $tariff_code;
        }
        return $tariff_codes;
    }

    /**
     * @throws Exception
     */
    private function makeDbSlotsLast24hrs(): void {
        $sql = 'SELECT `slot`  - 48,
                       `start` - INTERVAL 24 HOUR,
                       `stop`  - INTERVAL 24 HOUR
                  FROM `slots`
                  WHERE `slot` >= 1 AND
                         NOT `final`
                  ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $start, $stop) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$slot] = [
                            'start' => $start,
                            'stop'  => $stop
                            ];
        }
        $sql = 'INSERT INTO `slots` (`slot`, `start`, `stop`)
                    SELECT `slot`  - 48,
                           `start` - INTERVAL 24 HOUR,
                           `stop`  - INTERVAL 24 HOUR
                      FROM `slots`
                      WHERE `slot` >= 1 AND
                             NOT `final`
                      ORDER BY `slot`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $powers = new Powers();
        foreach (self::ENTITIES as $entity => $column) {
            foreach ($slots as $slot => $v) {
                $slots[$slot][$column] = $powers->powersKwAverage($entity, 'MEASURED', $v['start'], $v['stop']);
            }
            $sql = 'UPDATE  `slots` 
                      SET   `' . $column . '` = ?
                      WHERE `slot`            = ? AND
                            NOT `final`';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('di', $value, $slot)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
            foreach ($slots as $slot => $v) {
                if (!is_null($value = $v[$column])) {
                     $stmt->execute();
                }
            }
        }
        $sql = 'UPDATE      `slots` 
                  SET       `final` = TRUE
                  WHERE NOT `final`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
    }
}