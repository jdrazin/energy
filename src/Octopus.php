<?php
namespace Src;
use DateMalformedStringException;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Octopus extends Root
{
    const int       CUBIC_SPLINE_MULTIPLE   = 8;
    const string    URL_BASE_PRODUCTS       = 'https://api.octopus.energy/v1/products/',
                    ELECTRICITY_TARIFFS     = 'electricity-tariffs/';
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
                                    'GRID_W'                    => ['grid_kw',                  1000.0],
                                    'SOLAR_W'                   => ['solar_kw',                 1000.0],
                                    'LOAD_HOUSE_W'              => ['load_house_kw',            1000.0],
                                    'BATTERY_LEVEL_PERCENT'     => ['battery_level_percent',       1.0]
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
        $this->tariffCombinationsActiveFirst();                                 // get tariff combinations of interest, starting with active combination
        $this->requestTariffs();                                                // get latest tariff data
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function traverseTariffs($cron): void {
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'START', null, 'NOTICE');
        if (!EnergyCost::DEBUG_MINIMISER) {                                       // bypass empirical data if in DEBUG mode
            $db_slots  = new DbSlots();                                           // make day slots
            $values    = new Values();
            $givenergy = new GivEnergy();
            // $givenergy->initialise();
            $givenergy->getData();                                                // grid, load_house, solar (yesterday, today) > `values`
            (new EmonCms())->getData();                                           // home heating and temperature > `values`
            $values->makeHeatingPowerLookupDaySlotExtTemp();                      // make heating power look up table vs dayslot and external temperature
            (new Solcast())->getSolarActualForecast();                            // solar actuals & forecasts > 'powers'
            (new MetOffice())->forecast();                                        // get temperature forecast

            // traverse each tariff combination starting with active combination, which controls battery on completion of countdown to next slot
            foreach ($this->tariff_combinations as $tariff_combination) {
                $active_tariff = $tariff_combination['active'];
                if (is_null(self::SINGLE_TARIFF_COMBINATION_ID) || ($tariff_combination['id'] == self::SINGLE_TARIFF_COMBINATION_ID)) {
                    $db_slots->makeDbSlotsNext24hrs($tariff_combination);        // make slots for this tariff combination
                    $this->makeSlotRates($db_slots);                             // make tariffs
                    $values->estimatePowers($db_slots);                          // forecast slot solar, heating, non-heating and load powers

                    // fetch battery state of charge immediately prior to optimisation for active tariff, extrapolating to beginning of next slot
                    $batteryInitialKwh = $batteryInitialKwh ?? $givenergy->batteryLevel($db_slots)['effective_stored_kwh'];
                    $slot_command = (new EnergyCost($db_slots, $batteryInitialKwh))->minimise(); // minimise energy cost
                    if ($active_tariff) {                                        // make battery command
                        //   $giv_energy->control($slot_command);                // control battery for active combination on completion of countdown to next slot
                        $this->makeDbSlotsLast24hrs($tariff_combination);        // make historic slots for last 24 hours
                        $this->slots_make_cubic_splines();                       // generate cubic splines
                    }
                }
            }
        }
        else {
            (new EnergyCost(null, null))->minimise();     // minimise energy cost
        }
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'STOP', null, 'NOTICE');
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function requestTariffs(): void  // get tariffs for both directions
    {
        if ($this->skip_request(__NAMESPACE__, __CLASS__)) {  // skip request if called recently
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
    private function tariffCombinationsActiveFirst(): void
    {
        // select tariff combinations, active first
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
                  ORDER BY `tc`.`active` DESC';
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
    private function makeDbSlotsLast24hrs($tariff_combination): void {
        $tariff_combination_id = $tariff_combination['id'];
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
                            `tariff_combination` = ? AND
                             NOT `final`
                      ORDER BY `slot`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $values = new Values();
        foreach (self::ENTITIES as $entity => $properties) {
            foreach ($slots as $slot => $v) {       // returns averages for graphing purposes, about START times (i.e. slot_start_time - 15mins TO slot_start_time + 15mins
                $slots[$slot][$properties[0]] = $values->average($entity, 'MEASURED', $v['start'], $v['stop'], -DbSlots::SLOT_DURATION_MIN/2)/$properties[1];
            }
            $sql = 'UPDATE  `slots` 
                      SET   `' . $properties[0] . '` = ?
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
                if (!is_null($value = $v[$properties[0]])) {
                     $stmt->execute();
                }
            }
        }
        $this->mysqli->commit();
        $sql = 'INSERT INTO `slots` (`final`, `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_kw`, `mode`, `target_level_percent`, `abs_charge_power_w`, `battery_charge_kw`, `battery_level_kwh`, `battery_level_percent`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`) 
                       (SELECT      TRUE,     `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_kw`, `mode`, `target_level_percent`, `abs_charge_power_w`, `battery_charge_kw`, `battery_level_kwh`, `battery_level_percent`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`
                                    FROM `slots` `s`
                                    WHERE NOT `final` AND
                                              `tariff_combination` IN (0, ?))  
                    ON DUPLICATE KEY UPDATE   `start`                     = `s`.`start`,
                                              `stop`                      = `s`.`stop`,
                                              `load_house_kw`             = `s`.`load_house_kw`, 
                                              `grid_kw`                   = `s`.`grid_kw`,
                                              `solar_kw`                  = `s`.`solar_kw`,
                                              `mode`                      = `s`.`mode`,
                                              `target_level_percent`      = `s`.`target_level_percent`,
                                              `abs_charge_power_w`        = `s`.`abs_charge_power_w`, 
                                              `battery_charge_kw`         = `s`.`battery_charge_kw`, 
                                              `battery_level_kwh`         = `s`.`battery_level_kwh`,
                                              `battery_level_percent`     = `s`.`battery_level_percent`,
                                              `import_gbp_per_kwh`        = `s`.`import_gbp_per_kwh`, 
                                              `export_gbp_per_kwh`        = `s`.`export_gbp_per_kwh`, 
                                              `import_gbp_per_day`        = `s`.`import_gbp_per_day`, 
                                              `export_gbp_per_day`        = `s`.`export_gbp_per_day`, 
                                              `load_non_heating_kw`       = `s`.`load_non_heating_kw`, 
                                              `load_heating_kw`           = `s`.`load_heating_kw`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if ($battery_capacity_kwh = $this->config['battery']['initial_raw_capacity_kwh']) {
            $sql = 'UPDATE  `slots`
                      SET   `battery_level_kwh` = ROUND(IFNULL(`battery_level_kwh`, ? * `battery_level_percent` /100.0),  1)
                      WHERE `final` AND 
                            `battery_level_percent` IS NOT NULL';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('d', $battery_capacity_kwh) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
            $sql = 'UPDATE  `slots`
                      SET   `battery_level_percent` = ROUND(IFNULL(`battery_level_percent`, 100.0 * `battery_level_kwh` / ?), 1)
                      WHERE `final` AND
                            `battery_level_kwh` IS NOT NULL';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('d', $battery_capacity_kwh) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function slots_make_cubic_splines(): void
    {
        $sql = 'SELECT      `n`.`slot`,
                            UNIX_TIMESTAMP(`n`.`start`)             AS `unix_timestamp`,
                            ROUND(`n`.`load_house_kw`, 3)           AS `load_house_kw`,
                            ROUND(`p`.`load_house_kw`, 3)           AS `previous_load_house_kw`,
                            ROUND(`n`.`grid_kw`, 3)                 AS `grid_kw`,
                            ROUND(`p`.`grid_kw`, 3)                 AS `previous_grid_kw`,
                            ROUND(`n`.`solar_kw`, 3)                AS `solar_kw`,
                            ROUND(`p`.`solar_kw`, 3)                AS `previous_solar_kw`,
                            ROUND(`n`.`battery_level_kwh`, 3)       AS `battery_level_kwh`,
                            ROUND(`p`.`battery_level_kwh`, 3)       AS `previous_battery_kwh`
                  FROM      `slots` `n`
                  LEFT JOIN (SELECT     `slot`,
                                        `start`,
                                        `load_house_kw`,
                                        `grid_kw`,
                                        `solar_kw`,
                                        `battery_level_kwh`
                                FROM    `slots`
                                WHERE   `final`) `p` ON `p`.`slot`+48 = `n`.`slot`
                  WHERE     `n`.`slot` >= 0 AND `n`.`final`
                  ORDER BY  `n`.`slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$slot] = [$unix_timestamp,  $load_house_kw,  $previous_load_house_kw, $grid_kw, $previous_grid_kw,   $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh];
        }
        $number_slots = count($slots);
        $number_slots_cubic_spline = $number_slots*self::CUBIC_SPLINE_MULTIPLE;
        $cubic_spline = new CubicSpline($number_slots_cubic_spline);
        $columns = ['unix_timestamp', 'load_house_kw', 'previous_load_kw', 'grid_kw', 'previous_grid_kw', 'solar_kw', 'previous_solar_kw', 'battery_level_kwh', 'previous_battery_level_kwh'];
        foreach ($columns as $index => $column) {
            $y = [];
            foreach ($slots as $k => $slot) {
                $y[$k] = $slot[$index];
            }
            if (!$index) { // generate x-array
                $t_min = $slots[1][0];
                $t_max = $slots[$number_slots-1][0];
                $t_duration = $t_max - $t_min;
                for ($k=0; $k < $number_slots_cubic_spline; $k++) {
                    $slots_cubic_splines[$k+1][$index] = (int) round($t_min + $t_duration * ($k / ($number_slots_cubic_spline-1)));
                }
            }
            else {
                $y = $cubic_spline->cubic_spline_y($y);
                foreach ($y as $k => $v) {
                    $slots_cubic_splines[$k+1][$index] = round($y[$k], 3);
                }
            }
        }
        $sql = 'INSERT INTO `slots_cubic_splines` (`slot`,  `unix_timestamp`,   `load_house_kw`,     `previous_load_house_kw`,    `grid_kw`, `previous_grid_kw`, `solar_kw`, `previous_solar_kw`, `battery_level_kwh`, `previous_battery_level_kwh`) 
                                           VALUES (?,       ?,                  ?,                  ?,                          ?,          ?,                 ?,          ?,                      ?,                       ?                              )
                               ON DUPLICATE KEY UPDATE `unix_timestamp`                 = ?,
                                                       `load_house_kw`                  = ?,
                                                       `previous_load_house_kw`         = ?,
                                                       `grid_kw`                        = ?,
                                                       `previous_grid_kw`               = ?,
                                                       `solar_kw`                       = ?,
                                                       `previous_solar_kw`              = ?,
                                                       `battery_level_kwh`              = ?,
                                                       `previous_battery_level_kwh`     = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('iiddddddddidddddddd', $slot, $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh,
                                                                            $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($slots_cubic_splines as $slot => $slots_cubic_spline) {
            $unix_timestamp                 = $slots_cubic_spline[0];
            $load_house_kw                  = $slots_cubic_spline[1];
            $previous_load_house_kw         = $slots_cubic_spline[2];
            $grid_kw                        = $slots_cubic_spline[3];
            $previous_grid_kw               = $slots_cubic_spline[4];
            $solar_kw                       = $slots_cubic_spline[5];
            $previous_solar_kw              = $slots_cubic_spline[6];
            $battery_level_kwh              = $slots_cubic_spline[7];
            $previous_battery_level_kwh     = $slots_cubic_spline[8];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }
}