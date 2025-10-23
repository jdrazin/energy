<?php
namespace Src;
use DateMalformedStringException;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Octopus extends Root
{
    const string    URL_BASE_PRODUCTS = 'https://api.octopus.energy/v1/products/',
                    ELECTRICITY_TARIFFS = 'electricity-tariffs/';
    const array     DIRECTIONS = [
                                   'import' => [
                                        'tariffs'       => 'tariff_imports',
                                        'rates'         => 'tariff_rates_import',
                                        'slot_pers'     => ['KWH' => 'import_gbp_per_kwh',
                                                            'DAY' => 'import_gbp_per_day']
                                    ],
                                    'export' => [
                                        'tariffs'       => 'tariff_exports',
                                        'rates'         => 'tariff_rates_export',
                                        'slot_pers'     => ['KWH' => 'export_gbp_per_kwh',
                                                            'DAY' => 'export_gbp_per_day']
                                    ]
                                ],
                    RATE_PERS = [
                                    'KWH' => 'standard-unit-rates/',
                                    'DAY' => 'standing-charges/',
                                ],
                    ENTITIES = [
                                    'grid_kw'                 => [['GRID_W',            +1000.0],
                                                                  ['LOAD_EV_W',         +1000.0]],
                                    'solar_gross_kw'          => [['SOLAR_W',           +1000.0]],
                                    'load_house_kw'           => [['LOAD_HOUSE_W',      +1000.0]],
                                    'battery_level_start_kwh' => [['BATTERY_LEVEL_KWH', +1.0]]
                                ];
    const int MAX_WAIT_TO_NEXT_SLOT_SECONDS = 180;
    const ?int SINGLE_TARIFF_COMBINATION_ID = null;
    private array $api;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->use_local_config();
        $this->class = $this->strip_namespace(__NAMESPACE__, __CLASS__);
        $this->api = $this->apis[$this->class];
        $this->requestTariffs();                                                // get latest tariff data
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function traverseTariffs($cron): void {
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'START', '', null, 'NOTICE');
        if (!DEBUG_MINIMISER) {                                                   // bypass empirical data if in DEBUG mode
            $db_slots  = new Slot();                                              // make day slots
            $values    = new Values();
            $givenergy = new GivEnergy();
            $givenergy->getData();                                                // grid, load_house, solar (yesterday, today) > `values`
            (new EmonCms())->getData();                                           // home heating and temperature > `values`
            $values->makeHeatingPowerLookupDaySlotExtTemp();                      // make heating power look up table vs dayslot and external temperature
            (new Solcast())->getSolarActualForecast();                            // solar actuals & forecasts > 'powers'
            (new MetOffice())->forecast();                                        // get temperature forecast

            // traverse each tariff combination starting with active combination, which controls battery on completion of countdown to next slot
            $tariff_combinations = $this->tariffCombinations();                   // get tariff combinations of interest, starting with active combination
            foreach ($tariff_combinations as $tariff_combination) {
                if (($tariff_combination['active']) || !ACTIVE_TARIFF_COMBINATION_ONLY) {
                    if (is_null(self::SINGLE_TARIFF_COMBINATION_ID) || ($tariff_combination['id'] == self::SINGLE_TARIFF_COMBINATION_ID)) {
                        $db_slots->makeDbSlotsNext24hrs($tariff_combination);                           // make slots for this tariff combination
                        $next_day_slots = $db_slots->getDbNextDaySlots($tariff_combination);
                        $this->makeSlotRates($db_slots->slots, $tariff_combination, false);        // make tariffs
                        $values->estimatePowers($db_slots, $tariff_combination);                        // forecast slot solar, heating, non-heating and load powers

                        // fetch battery state of charge immediately prior to optimisation for active tariff, extrapolating to beginning of next slot
                        $timestamp_start = (new DateTime($next_day_slots[0]['start']))->getTimestamp(); // beginning of slot 0
                        $batteryLevelInitialKwh = $batteryLevelInitialKwh ?? $givenergy->batteryLevelSlotBeginExtrapolateKwh($timestamp_start); // initial level at beginning of slot 0
                        $parameters = [
                                        'type'                   => 'slots',
                                        'batteryLevelInitialKwh' => $batteryLevelInitialKwh,
                                        'tariff_combination'     => $tariff_combination
                                      ];
                        $slot_solution = (new EnergyCost($parameters))->minimise(); // minimise energy cost
                        $this->makeActiveTariffCombinationDbSlotsLast24hrs($tariff_combination);    // make historic slots for last 24 hours
                        if ($tariff_combination['active']) {                                        // make battery command
                            $this->log($slot_solution);                                             // log slot command
                            $this->slots_make_cubic_splines();                                      // generate cubic splines
                        }
                    }
                }
            }
        } else {
            $parameters = [
                'type'                   => 'slots',
                'batteryLevelInitialKwh' => null,
                'tariff_combination'     => null
            ];
            (new EnergyCost($parameters))->minimise();      // minimise energy cost
        }
        $this->trimSlotSolutions();
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'STOP', null,  null,'NOTICE');
    }

    /**
     * @throws Exception
     */
    private function trimSlotSolutions(): void {
        $sql = 'DELETE FROM `slot_solutions`
                    WHERE `timestamp` + INTERVAL ' . self::SLOT_SOLUTIONS_DB_MAX_AGE_DAY . ' DAY < NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function log($slot_solution): void {
        $sql = 'INSERT INTO `slot_solutions` (`start`, `stop`, `mode`, `abs_charge_w`, `target_level_percent`, `message`) 
                                     VALUES  (?,       ?,       ?,     ?,              ?,                     ?         )
                           ON DUPLICATE KEY UPDATE `mode`                 = ?,
                                                   `abs_charge_w`         = ?,
                                                   `target_level_percent` = ?,
                                                   `message`              = ?';
        $start                  = $slot_solution['start'];
        $stop                   = $slot_solution['stop'];
        $mode                   = $slot_solution['mode'];
        $abs_charge_w           = $slot_solution['abs_charge_w'];
        $target_level_percent   = $slot_solution['target_level_percent'];
        $message                = $slot_solution['message'];
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssiissiis',$start,$stop, $mode, $abs_charge_w, $target_level_percent, $message, $mode, $abs_charge_w, $target_level_percent, $message) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function requestTariffs(): void { // get tariffs for both directions
        $made_successful_request = false;
        if ($this->skipRequest()) {  // skip request if called recently
            $this->requestResult(false);
            return;
        }
        try {
            foreach (self::DIRECTIONS as $tariffs_rates) {
                $this->getTariff($tariffs_rates);
            }
            $made_successful_request = true;
        }
        catch (exception $e) { // log warning if request fails
            $this->logDb('MESSAGE', $e->getMessage(),  null, 'WARNING');
        }
        $this->requestResult($made_successful_request); // update timestamp
    }

    /**
     * @throws Exception
     */
    private function getTariff($tariffs_rates): void {
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
                try {
                    $tariffs = json_decode($this->request($url_tariff_prefix . $endpoint), true)['results'];
                    $this->insert($tariffs_rates['rates'], $tariff_id, $rate_per, $tariffs);
                }
                catch (GuzzleException $e) {
                    $message = $e->getMessage();
                    (new Root())->logDb('MESSAGE', $message,  null,'WARNING');
                    if (DEBUG) {
                        echo $message . PHP_EOL;
                    }
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function insert($rates_table, $tariff_id, $per, $tariffs): void
    {
        // insert rates
        $sql = 'INSERT INTO `' . $rates_table . '` (`tariff`, `start`, `stop`, `rate`, `per`) 
                                            VALUES (?,         ?,       ?,      ?,      ?)
                    ON DUPLICATE KEY UPDATE `rate`      = ?,
                                            `timestamp` = NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('issdsd', $tariff_id, $start, $stop, $rate, $per, $rate)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
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
    public function makeSlotRates($slots, $tariff_combination, $final): void        // get future rates for tariff combination
    {
        $sql = 'UPDATE      `slots`
                    SET     `import_gbp_per_kwh` = ?,
                            `export_gbp_per_kwh` = ?,
                            `import_gbp_per_day` = ?,
                            `export_gbp_per_day` = ?
                    WHERE   `slot`               = ? AND
                            `tariff_combination` = ? AND
                            `final`              = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ddddiii', $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day, $slot, $tariff_combination['id'], $final)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        foreach ($slots as $slot => $v) {
            $start = $v['start'];
            $stop  = $v['stop'];
            $ratesPer = ['start' => $start, 'stop' => $stop];
            foreach (self::DIRECTIONS as $direction => $x) {
                $tariff_table = self::DIRECTIONS[$direction]['rates'];
                foreach (self::RATE_PERS as $unit => $y) {
                    if (is_null($ratesPer[$unit][$direction] = $this->ratePerUnit($unit, $start, $stop, $tariff_combination[$direction], $tariff_table, 0))) {      // get rate
                        if (is_null($ratesPer[$unit][$direction] = $this->ratePerUnit($unit, $start, $stop, $tariff_combination[$direction], $tariff_table, -1))) { // if none, try same slot in previous date
                            throw new \Exception('no ' . $direction . ' tariff between ' . $start . ' and ' . $stop);                                                // otherwise throw exception
                        }
                    }
                }
                if (!is_null($rate_extraordinary = $this->rateExtraordinary($direction, $start, $stop))) {  // extraordinary KWH rate overrides
                    $ratesPer['KWH'][$direction] = $rate_extraordinary;
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

    private function rateExtraordinary($direction, $start, $stop): float|null {
        $sql = 'SELECT `' . $direction . '_rate`
                    FROM    `tariff_extraordinaries_per_kwh`
                    WHERE   ? BETWEEN `start` AND `stop` AND
                            ? BETWEEN `start` AND `stop` AND
                            `status` IN(\'CURRENT\', \'TO_DROP\')
                    ORDER BY `timestamp` DESC';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ss', $start, $stop) ||
            !$stmt->bind_result($rate) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        $stmt->fetch();
        return $rate;
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
                    $this->logDb('MESSAGE', $message, null, 'ERROR');
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
                    $this->logDb('MESSAGE', $message, null, 'ERROR');
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
            $this->logDb('MESSAGE', $message, null, 'ERROR');
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
    public function slots_make_cubic_splines(): void {
        $sql = 'SELECT      `n`.`slot`,
                            UNIX_TIMESTAMP(`n`.`start`)             AS `unix_timestamp`,
                            ROUND(`n`.`load_house_kw`, 3)           AS `load_house_kw`,
                            ROUND(`p`.`load_house_kw`, 3)           AS `previous_load_house_kw`,
                            ROUND(`n`.`grid_kw`, 3)                 AS `grid_kw`,
                            ROUND(`p`.`grid_kw`, 3)                 AS `previous_grid_kw`,
                            ROUND(`n`.`solar_gross_kw`, 3)          AS `solar_gross_kw`,
                            ROUND(`p`.`solar_gross_kw`, 3)          AS `previous_gross_solar_kw`,
                            ROUND(`n`.`battery_level_start_kwh`, 3) AS `battery_level_kwh`,
                            ROUND(`p`.`battery_level_start_kwh`, 3) AS `previous_battery_kwh`
                  FROM      `slots` `n`
                  LEFT JOIN (SELECT     `slot`,
                                        `start`,
                                        `load_house_kw`,
                                        `grid_kw`,
                                        `solar_gross_kw`,
                                        `battery_level_start_kwh`
                                FROM    `slots`
                                WHERE   `final`) `p` ON `p`.`slot`+48 = `n`.`slot`
                  WHERE     `n`.`slot` >= 0 AND `n`.`final`
                  ORDER BY  `n`.`slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
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
            $vector_cubic_splines = [];
            foreach ($slots as $slot => $column_values) {
                $vector_cubic_splines[$slot] = $column_values[$index];
            }
            if (!$index) { // generate time array
                $t_min = $slots[0][0];
                $t_max = $slots[$number_slots-1][0];
                $t_duration = $t_max - $t_min;
                for ($k=0; $k < $number_slots_cubic_spline; $k++) {
                    $slots_cubic_splines[$k][$index] = (int) round($t_min + $t_duration * ($k / ($number_slots_cubic_spline-1)));
                }
            }
            else {
                $vector_cubic_splines = $cubic_spline->cubic_spline_y($vector_cubic_splines);
                foreach ($vector_cubic_splines as $k => $v) {
                    $slots_cubic_splines[$k][$index] = round($vector_cubic_splines[$k], 3);
                }
            }
        }
        $sql = 'DELETE FROM `slots_cubic_splines`';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->execute()) {
                    $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $message, null, 'ERROR');
                    throw new Exception($message);
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
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        foreach ($slots_cubic_splines as $slot => $slots_cubic_spline) {
            $unix_timestamp              = $slots_cubic_spline[0];
            $load_house_kw               = $slots_cubic_spline[1];
            $previous_load_house_kw      = $slots_cubic_spline[2];
            $grid_kw                     = $slots_cubic_spline[3];
            $previous_grid_kw            = $slots_cubic_spline[4];
            $solar_kw                    = $slots_cubic_spline[5];
            $previous_solar_kw           = $slots_cubic_spline[6];
            $battery_level_kwh           = $slots_cubic_spline[7];
            $previous_battery_level_kwh  = $slots_cubic_spline[8];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    public function tariffCombinations(): array
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
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $tariff_combinations_active_first = [];
        while ($stmt->fetch()) {
            $tariff_combinations_active_first[] = [
                                                    'id'     => $id,
                                                    'name'   => $name,
                                                    'active' => $active,
                                                    'import' => $import,
                                                    'export' => $export
                                                  ];
        }
        return $tariff_combinations_active_first;
    }

    /**
     * @throws Exception
     */
    public function makeActiveTariffCombinationDbSlotsLast24hrs($tariff_combination): void {
        // make previous day times slots from next day slots
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
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$slot] = [
                'start' => $start,
                'mid'   => $this->mid($start, $stop),
                'stop'  => $stop
            ];
         }
        $next_slot_start = $stop; // next slot starts at end of last previous slot

        // make time slot rows for previous day
        $sql = 'INSERT IGNORE INTO `slots` (`slot`, `start`, `stop`, `tariff_combination`)
                    SELECT `slot`  - 48,
                           `start` - INTERVAL 24 HOUR,
                           `stop`  - INTERVAL 24 HOUR,
                           ?
                      FROM `slots`
                      WHERE `slot` >= 1 AND
                            `tariff_combination` = ? AND
                             NOT `final`
                      ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ii', $tariff_combination['id'], $tariff_combination['id']) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $values = new Values();
        foreach (self::ENTITIES as $column => $power_elements) {
            foreach ($slots as $slot => $v) {       // returns averages for graphing purposes, about START times (i.e. slot_start_time - 15mins TO slot_start_time + 15mins
                $power_w = 0.0;
                foreach ($power_elements as $power_element) {
                    $power_w += $values->average($power_element[0], 'MEASURED', $v['start'], $v['stop'], -Slot::DURATION_MINUTES / 2) / $power_element[1];
                }
                $slots[$slot][$column] = $power_w;
            }
            $sql = 'UPDATE  `slots` 
                      SET   `' . $column . '` = ?
                      WHERE `slot`            = ? AND
                            NOT `final`';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('di', $value, $slot)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            foreach ($slots as $slot => $v) {
                if (!is_null($value = $v[$column])) {
                    $stmt->execute();
                }
            }
        }
        $this->makeSlotRates($slots, $tariff_combination, false);  // make tariffs
        // copy non final previous & next slots for tariff combination into final rows
        $sql = 'INSERT INTO `slots` (`final`, `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_gross_kw`, `battery_level_start_kwh`, `battery_charge_kw`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`) 
                       (SELECT      TRUE,     `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_gross_kw`, `battery_level_start_kwh`, `battery_charge_kw`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`
                                    FROM `slots` `s`
                                    WHERE NOT `final` AND
                                              `tariff_combination` = ?)
                    ON DUPLICATE KEY UPDATE   `start`                     = `s`.`start`,
                                              `stop`                      = `s`.`stop`,
                                              `load_house_kw`             = `s`.`load_house_kw`,
                                              `grid_kw`                   = `s`.`grid_kw`,
                                              `solar_gross_kw`            = `s`.`solar_gross_kw`,
                                              `battery_level_start_kwh`   = `s`.`battery_level_start_kwh`,
                                              `battery_charge_kw`         = `s`.`battery_charge_kw`,
                                              `import_gbp_per_kwh`        = `s`.`import_gbp_per_kwh`,
                                              `export_gbp_per_kwh`        = `s`.`export_gbp_per_kwh`,
                                              `import_gbp_per_day`        = `s`.`import_gbp_per_day`,
                                              `export_gbp_per_day`        = `s`.`export_gbp_per_day`,
                                              `load_non_heating_kw`       = `s`.`load_non_heating_kw`,
                                              `load_heating_kw`           = `s`.`load_heating_kw`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination['id']) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        // sleep until beginning of next slot start, then commit
        $wait_to_next_slot_start_seconds = (new DateTime($next_slot_start))->getTimestamp() - (new DateTime())->getTimestamp();
        if ($wait_to_next_slot_start_seconds < 0) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'sleep to next slot is negative: ' . $wait_to_next_slot_start_seconds . 's');
            $this->logDb('MESSAGE', $message, null, 'WARNING');
            $wait_to_next_slot_start_seconds = 0;
        }
        elseif($wait_to_next_slot_start_seconds > self::MAX_WAIT_TO_NEXT_SLOT_SECONDS) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'sleep to next slot too high: ' . $wait_to_next_slot_start_seconds . 's');
            $this->logDb('MESSAGE', $message, null, 'WARNING');
        }
        if (!DEBUG) {
           sleep($wait_to_next_slot_start_seconds);
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function truncateSlotNextDayCostEstimates(): void     {
        $sql = 'TRUNCATE `slot_next_day_cost_estimates`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }
}
