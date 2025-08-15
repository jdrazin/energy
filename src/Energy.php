<?php
namespace Src;
use DateMalformedIntervalStringException;
use DateMalformedStringException;
use DateTime;
use DateTimeZone;
use DivisionByZeroError;
use ErrorException;
use Exception;

class Energy extends Root
{
    const   float   JOULES_PER_KWH                      = 1000.0 * 3600.0,
                    DAYS_PER_YEAR                       = 365.25,
                    MONTHS_PER_YEAR                     = 12,
                    DEFAULT_TEMPERATURE_TARGET_CELSIUS  = 21.0,
                    TEMPERATURE_HALF_LIFE_DAYS          = 1.0;
    const   int     HOURS_PER_DAY                       = 24,
                    SECONDS_PER_HOUR                    = 3600;
    const array CHECKS                              = ['location' => [
                                                                        'coordinates'                   => ['array' => null          ],
                                                                        'latitude_degrees'              => ['range' => [ -90.0,  90.0]],
                                                                        'longitude_degrees'             => ['range' => [-180.0, 180.0]],
                                                                        'cloud_cover_months'            => ['array' => null          ],
                                                                        'fractions'                     => ['array' => 12            ],
                                                                        'factors'                       => ['array' => 12            ],
                                                                        'temperature_target_celsius'    => ['range' => [10.0,    30.0]],
                                                                        'temperature_half_life_days'    => ['range' => [0.1,     30.0]],
                                                                        'time_correction_fraction'      => ['range' => [-1.0,     1.0]],
                                                                        'target_hours'                  => ['array' => [0,        23 ]],
                                                                     ]
                                                      ],
                    DEFAULT_TEMPERATURE_TARGET_HOURS = [7,8,9,10,11,12,13,14,15,16,17,18,19,20,21];
    const   array COMPONENT_ACRONYMS                = [''              => 'none',
                                                       'battery'       => 'B',
                                                       'boiler'        => 'BO',
                                                       'heat_pump'     => 'HP',
                                                       'insulation'    => 'IN',
                                                       'solar_pv'      => 'PV',
                                                       'solar_thermal' => 'ST'],
                    PROJECTION_EMPTY                = [
                                                            [
                                                                "project_duration",
                                                                0,
                                                                25
                                                            ],
                                                            [
                                                                "none",
                                                                0.0,
                                                                0.0
                                                            ]
                                                       ],
                    TIME_UNITS                      = [ 'HOUR_OF_DAY'   => 24,
                                                        'MONTH_OF_YEAR' => 12,
                                                        'DAY_OF_YEAR'   => 366];

    public Check $check;
    public Time $time;
    public Demand $demand_space_heating_thermal, $demand_hotwater_thermal, $demand_non_heating_electric;
    public Supply $supply_grid, $supply_boiler;
    public Boiler $boiler;
    public SolarCollectors $solar_pv, $solar_thermal;
    public Battery $battery;
    public HotWaterTank $hot_water_tank;
    public HeatPump $heat_pump;
    public Insulation $insulation;
    public string $error;
    public float $temp_climate_c, $temperature_target_internal_c, $temperature_internal_decay_rate_per_s, $average_temp_climate_c, $average_temp_internal_c;
    public array $temperature_target_hours;

    /**
     * @throws Exception
     */
    public function __construct($config) {
        if (!is_null($config)) {
            $this->config = $config;
        }
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    public function slots(): bool|string {
        $sql = 'SELECT      `unix_timestamp`,
                            `load_house_kw`,
                            `previous_load_house_kw`,
                            `grid_kw`,
                            `previous_grid_kw`,
                            `solar_kw`,
                            `previous_solar_kw`,
                            `battery_level_kwh`,
                            `previous_battery_level_kwh`
                  FROM      `slots_cubic_splines`
                  ORDER BY  `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $slots_cubic_splines = [];
        while ($stmt->fetch()) {
            $slots_cubic_splines[] = [$unix_timestamp,  $load_house_kw,  $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh];
        }
        return json_encode($slots_cubic_splines, JSON_PRETTY_PRINT);
    }

    /**
     * @throws Exception
     */
    public function tariffCombinations(): bool|string {
        if (!$this->authenticate(null)) { // to do: does authenticate() belong here?
            return false;
        }
        $sql = "SELECT  UNIX_TIMESTAMP(`sndce`.`timestamp`) AS `start`,
                        CONCAT(`ti`.`code`, ', ', `te`.`code`, CONVERT(IF((`tc`.`active` IS NULL), '', ' *ACTIVE*') USING utf8mb4), ' (', `tc`.`id`, ')') AS `tariff [import, export]`,
                        ROUND(((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`), 2) AS `raw grid (GBP)`,
                        ROUND(((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`), 2) AS `optimised grid (GBP)`,
                        ROUND((((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - ((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`)), 2) AS `grid saving (GBP)`,
                        ROUND((((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - (((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`) + `sndce`.`optimised_wear`)), 2) AS `saving (GBP)`,
                        ROUND(((100.0 * (((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - (((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`) + `sndce`.`optimised_wear`))) / ((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`)), 0) AS `saving (%)`,
                        ROUND((((100.0 * `sndce`.`optimised_wear`) / ((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`)) + `sndce`.`optimised_wear`), 0) AS `wear (%)`
                    FROM `slot_next_day_cost_estimates` `sndce`
                    JOIN `tariff_combinations` `tc` ON `sndce`.`tariff_combination` = `tc`.`id`
                    JOIN `tariff_imports`      `ti` ON `ti`   .`id`                 = `tc`.`import`
                    JOIN `tariff_exports`      `te` ON `te`   .`id`                 = `tc`.`export`
                    ORDER BY ROUND((((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - ((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`)), 2) DESC";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($start, $tariff_combination, $raw_gbp, $optimised_gbp, $grid_saving_gbp, $total_saving_gbp, $saving_percent, $wear_percent) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $tariff_combinations = [];
        $tariff_combinations[] = ['Starting', 'Tariff combination [import, export]', 'Result', 'Grid: raw (£)', 'Grid: optimised (£)', 'Grid: saving (£)', 'Net saving (£)', 'Grid: saving (%)', 'Wear (%)'];
        while ($stmt->fetch()) {
            $tariff_combinations[] = [$start, $tariff_combination, $raw_gbp, $optimised_gbp, $grid_saving_gbp, $total_saving_gbp, $saving_percent, $wear_percent];
        }
        return json_encode($tariff_combinations, JSON_PRETTY_PRINT);
    }

    public function tariffWarn(): string {
        $sql = "SELECT  NOT IFNULL(`tc`.`active`, FALSE) AS `warn`,
                        CONCAT(`ti`.`code`, ', ', `te`.`code`) AS `tariff`
                    FROM `slot_next_day_cost_estimates` `sndce`
                    JOIN `tariff_combinations` `tc` ON `sndce`.`tariff_combination` = `tc`.`id`
                    JOIN `tariff_imports`      `ti` ON `ti`   .`id`                 = `tc`.`import`
                    JOIN `tariff_exports`      `te` ON `te`   .`id`                 = `tc`.`export`
                    ORDER BY (((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - ((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`)) DESC
                    LIMIT 0, 1";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($warn, $tariff) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        return $warn ? 'Warning, better tariff: ' . $tariff : '';
    }

    /**
     * @throws Exception
     */
    public function slotSolution(): bool|string {
        if (!$this->authenticate(null)) {
            return false;
        }
        // get slot message component
        $sql = "SELECT   `message`,
                         `mode`,
                         DATE_FORMAT(CONVERT_TZ(`stop`, 'UTC', ?), '%H:%i') AS `stop`
                    FROM  `slot_solutions` 
                    WHERE `id` = (SELECT MAX(`id`) FROM `slot_solutions`)";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $this->config['location']['time_zone']) ||
            !$stmt->bind_result($slot_solution_message, $mode, $slot_solution_stop) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        if (!$stmt->fetch()) {
            return false;
        }

        // get latest slice
        unset($stmt);
        $sql = "SELECT `charge_kw`,
                       DATE_FORMAT(CONVERT_TZ(`timestamp`, 'UTC', ?), '%H:%i') AS `timestamp`
                    FROM `slice_solutions`
                    WHERE `id` = (SELECT MAX(`id`) 
                                    FROM `slice_solutions`)";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $this->config['location']['time_zone']) ||
            !$stmt->bind_result($slice_charge_kw, $slice_timestamp) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        if (!$stmt->fetch()) {
            return false;
        }
        $message = $slot_solution_message . ' at ' . $slot_solution_stop;
        switch ($mode) {
            case 'CHARGE':
            case 'DISCHARGE': {
                $charge_text = ($slice_charge_kw < 0 ? 'DISCHARGE' : 'CHARGE') . '@' . ROUND(1000.0 * abs($slice_charge_kw)) . 'W at ' . $slice_timestamp . ')';
                $message .= ' (now ' . $charge_text;
                break;
            }
            case 'ECO':
            case 'IDLE': {
                break;
            }
            default: {
            }
        }

        return $message;
    }

    /**
     * @throws Exception
     */
    public function projectionCombinations($pre_parse_only, $projection_id, $config): void  {
        $config_combinations = new ParameterCombinations($config);
        $combinations = $config_combinations->combinations;
        $last_key = count($combinations)-1;
        foreach ($combinations as $key => $combination) {
            if (!$pre_parse_only || $key == $last_key) { // process only final combination where all components included
                $config_combined = $this->parametersCombined($config, $combination, $config_combinations->variables);
                if (DEBUG) {
                    echo PHP_EOL . ($key + 1) . ' of ' . count($combinations) . ' (' . $config_combined['acronym'] . '): ';
                }
                $this->simulate($pre_parse_only, $projection_id, $config_combined);
            }
        }
        if (DEBUG) {
            echo PHP_EOL . 'Done' . PHP_EOL;
        }
   }

    private function parametersCombined($config, $combination, $variables): array {
        $description = '';
        foreach (ParameterCombinations::COMBINATION_ELEMENTS as $component_name) {
            $value = $combination[$component_name];
            if (!is_bool($value)) {
              throw new Exception('component \'' . $component_name . '\' parameter \'include\' must be boolean');
            }
            $config[$component_name]['include'] = $value;
            if ($value && in_array($component_name, $variables)) {
                $description .= self::COMPONENT_ACRONYMS[$component_name] . ', ';
            }
        }
        return ['config'      => $config,
                'combination' => $combination,
                'acronym'     => (rtrim($description, ', ') ? : 'none')];
    }

    /**
     * @throws Exception
     */
    public function submitProjection($pre_parse_only, $crc32, $config, $config_json): bool {
        $comment = ($config[Root::COMMENT_STRING] ?? '') . ' (' . (new DateTime("now", new DateTimeZone("UTC")))->format('j M Y H:i:s') . ')';
        if (!$this->authenticate($config['token'] ?? false)) {
            return false;
        }
        // attempt to pre-parse request
        try {
            $this->error = '';
            $this->projectionCombinations($pre_parse_only, $crc32, $config);
        }
        catch (DivisionByZeroError $e){
            $this->error = $e->getMessage();
        }
        catch (ErrorException|Exception $e) {
            $this->error = $e->getMessage();
        }

        // submit projection if it parsed OK
        if (!$this->error) {
            $this->insertProjection($crc32, $config_json, '', $comment);
        }
        return true;
    }

    /**
     * @throws Exception
     */
    public function insertProjection($crc32, $config_json, $email, $comment): void
    {
        $sql = 'INSERT INTO `projections` (`id`,  `request`, `email`, `comment`)
                                       VALUES (?,     ?,         ?,       ?)
                        ON DUPLICATE KEY UPDATE  `request`   = ?,                                             
                                                 `error`     = NULL,
                                                 `status`    = \'IN_QUEUE\',
                                                 `submitted` = NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('issss', $crc32, $config_json, $email, $comment, $config_json) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function processNextProjection($projection_id): void  {
        if ($this->config) { // make json request from root config if exists
            $request = json_encode($this->config);
        }
        else {
            if (is_null($projection_id)) { // if no explicit projection id, get id for earliest in queue
                $sql = 'SELECT `j`.`id`,
                               `j`.`request`,
                               `j`.`email`
                          FROM `projections` `j`
                          INNER JOIN (SELECT MIN(`submitted`) AS `min_submitted`
                                        FROM `projections`
                                        WHERE `status` = \'IN_QUEUE\') `j_min` ON `j_min`.`min_submitted` = `j`.`submitted`
                          WHERE `j`.`status` = \'IN_QUEUE\'
                          LIMIT 0, 1';
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_result($projection_id, $request, $email) ||
                    !$stmt->execute()) {
                    $error = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $error, null, 'ERROR');
                    throw new Exception($error);
                }
            }
            else { // get request for specified id
                $sql = 'SELECT  `request`,
                                `email`
                          FROM  `projections`                     
                          WHERE `id` = ?';
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_param('i', $projection_id) ||
                    !$stmt->bind_result($request, $email) ||
                    !$stmt->execute()) {
                    $error = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $error, null, 'ERROR');
                    throw new Exception($error);
                }
            }
            $stmt->fetch();
            unset($stmt);
        }
        if ($request) {   // process next projection if exists
            $basetime_seconds = time();
            try {
                $this->projectionStatus($projection_id, 'IN_PROGRESS');
                $this->projectionInitialise($projection_id);
                $this->projectionCombinations(false, $projection_id, json_decode($request, true)); // process each combination
                $this->projectionStatus($projection_id, 'COMPLETED');
            }
            catch (DivisionByZeroError $e){
                $error = $e->getMessage();
            }
            catch (ErrorException|Exception $e) {
                $error = $e->getMessage();
            }
            if ($error ?? false) {
                $sql = 'UPDATE  `projections` 
                          SET   `error`  = ?,
                                `status` = \'COMPLETED\'
                          WHERE `id`     = ?';
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_param('si', $error, $projection_id) ||
                    !$stmt->execute()) {
                    $error = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $error, null, 'ERROR');
                    throw new Exception($error);
                }
            }
            $this->writeCpuSeconds($projection_id, time() - $basetime_seconds);
            $this->mysqli->commit();
            if ($email ?? false) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) &&
                    (new SMTPEmail())->email([  'subject'   => 'Renewable Visions: your results are ready',
                        'html'      => false,
                        'bodyHTML'  => ($error = 'Your results are ready at: https://www.drazin.net:8443/projections?id=' . $projection_id . '.' . PHP_EOL . '<br>'),
                        'bodyAlt'   => strip_tags($error)])) {
                    $this->logDb('MESSAGE', 'Notified ' . $email . 'of completed projection ' . $projection_id, null, 'NOTICE');
                    $this->projectionStatus($projection_id, 'NOTIFIED');
                }
                else {
                    $this->logDb('MESSAGE', 'Notification failed: ' . $email . 'of completed projection ' . $projection_id, null, 'WARNING');
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private function writeCpuSeconds($projection_id, $cpu_seconds): void {
        $sql = 'UPDATE  `projections` 
                  SET   `cpu_seconds` = ?
                  WHERE `id`          = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ii', $cpu_seconds, $projection_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function projectionInitialise($projection_id): void  {
        $sql = 'DELETE FROM `combinations`
                  WHERE `projection` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $projection_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $sql = 'UPDATE `projections`
                  SET `error` = NULL
                  WHERE `id`  = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $projection_id) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    private function projectionStatus($id, $status): void {
        $this->mysqli->commit();
        $sql = 'UPDATE  `projections`
                  SET   `status` = ?
                  WHERE `id` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('si', $status, $id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function getText($projection_id, $type): ?string {
        // get acronyms
        $sql = 'SELECT  `request`,
                        `status`,
                        `error`,
                        `timestamp`,
                        UNIX_TIMESTAMP(`submitted`),
                        `comment`,
                        `cpu_seconds`
                  FROM  `projections`
                  WHERE `id` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $projection_id) ||
            !$stmt->bind_result($json_request, $status, $error, $timestamp, $submitted_unix_timestamp, $comment, $cpu_seconds) ||
            !$stmt->execute()) {
            $error = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $error, null, 'ERROR');
            throw new Exception($error);
        }
        $stmt->fetch();
        switch($status) {
            case 'COMPLETED':
            case 'NOTIFIED': {
                switch ($type) {
                    case 'error': {
                        return $error ?? '';
                    }
                    case 'comment': {
                        return $comment . ' elapsed: ' . $cpu_seconds . 's';
                    }
                    case 'json_request': {
                        return $json_request ?? '';
                    }
                    default: {
                        throw new Exception('Bad text type');
                    }
                }
            }
            case 'IN_QUEUE': {
                $sql = 'SELECT  COUNT(`status`)
                          FROM  `projections`
                          WHERE UNIX_TIMESTAMP(`submitted`) < ? AND
                                `status` = \'IN_QUEUE\'';
                unset($stmt);
                if (!($stmt = $this->mysqli->prepare($sql)) ||
                    !$stmt->bind_param('i', $submitted_unix_timestamp) ||
                    !$stmt->bind_result($count) ||
                    !$stmt->execute() ||
                    !$stmt->fetch()) {
                    $error = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $error, null, 'ERROR');
                    throw new Exception($error);
                }
                return 'Projection is ' . ($count ? : 'next') . ' in queue. Come back shortly.';
            }
            case 'IN_PROGRESS': {
                return '';
            }
            default: {
                return 'Projection not found';
            }
        }
    }

    /**
     * @throws Exception
     */
    public function getProjection($projection_id): bool|string {
        // get max duration
        $sql = 'SELECT  MAX(`duration_years`),  
                        COUNT(`duration_years`)
                  FROM  `combinations`
                  WHERE `projection` =  ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $projection_id) ||
            !$stmt->bind_result($max_duration_years, $row_count) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        if ($max_duration_years && $row_count) {
            // get acronyms
            $sql = 'SELECT DISTINCT `acronym`
                       FROM         `combinations`
                       WHERE        `projection` = ?';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('i', $projection_id) ||
                !$stmt->bind_result($acronym) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $acronyms = [];
            while ($stmt->fetch()) {
                $acronyms[] = $acronym;
            }
            $sql = 'SELECT      `acronym`,
                                `duration_years`,
                                ROUND(`npv`/1000.0, 3)
                       FROM     `combinations`
                       WHERE    `projection` = ? AND
                                `acronym`    = ?
                       ORDER BY `duration_years`';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('is', $projection_id, $acronym) ||
                !$stmt->bind_result($acronym, $duration_years, $npv)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $columns = [];
            foreach ($acronyms as $acronym) {
                $column = [];
                $column[] = $acronym;
                $stmt->execute();
                while ($stmt->fetch()) {
                    $column[] = (float) $npv;
                }
                $columns[] = $column;
            }
            $column = [];
            $column[] = 'project_duration';
            for ($year = 0; $year <= $max_duration_years; $year++) {
                $column[] = $year;
            }
            $projection = [];
            $projection[] = $column;
            foreach ($columns as $column) {
                $projection[] = $column;
            }
        }
        else {
            $projection = self::PROJECTION_EMPTY;
        }
        return json_encode($projection, JSON_PRETTY_PRINT);
    }

    /**
     * @throws Exception
     */
    private function updateCombination($combination_parameters, $projection, $results, $projection_duration_years): void { // add
        $id                 = $projection['newResultId'];
        $sum_gbp            = $results['npv_summary']['sum_gbp'];
        $parameters_json    = json_encode($combination_parameters, JSON_PRETTY_PRINT);
        $results_json       = json_encode($results, JSON_PRETTY_PRINT);
        unset($this->stmt);
        $sql = 'UPDATE IGNORE `combinations`
                    SET   `duration_years` = ?,                    
                          `npv`            = ROUND(?),
                          `config`         = ?,
                          `result`         = ?,
                          `stop`           = NOW()
                    WHERE `id`             = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('idssi', $projection_duration_years, $sum_gbp, $parameters_json, $results_json, $id) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }

    private function consumption(): array {
        $consumption = [];
        if ($this->heat_pump->include ?? false) {
            $consumption['heatpump']      = ['annual' => $this->roundConsumption($this->heat_pump->kwh['YEAR'][0])];
        }
        if ($this->solar_pv->include ?? false) {
            $consumption['solar_pv']      = ['annual' => $this->roundConsumption($this->solar_pv->output_kwh['YEAR'][0])];
        }
        if ($this->solar_thermal->include ?? false) {
            $consumption['solar_thermal'] = ['annual' => $this->roundConsumption($this->solar_thermal->output_kwh['YEAR'][0])];
        }
        for ($year = 0; $year < $this->time->year; $year++) {
            $grid = [
                'kwh'       => $this->supply_grid->kwh['YEAR'][$year],
                'value_gbp' => $this->supply_grid->value_gbp ['YEAR'][$year]
            ];
            $boiler   = [
                'kwh'       => $this->supply_boiler->kwh['YEAR'][$year],
                'value_gbp' => $this->supply_boiler->value_gbp ['YEAR'][$year]
            ];
            $consumption['year'] = [
                'grid'   => $this->roundConsumption($grid),
                'boiler' => $this->roundConsumption($boiler)
            ];
        }
        return $consumption;
    }

    function roundConsumption($array): array {
        foreach ($array as $key => $element) {
            if (is_array($element)) {
                $element = $this->roundConsumption($element);
            }
            elseif (is_float($element)) {
                $element = round($element, 2);
            }
            $array[$key] = $element;
        }
        return $array;
    }

    function valueTimeStep($components, $time): void {
        foreach ($components as $component) {
            $component->valueTimeStep($time);
        }
    }

    function install($components): void {
        foreach ($components as $component) {
            if ($component->include && $component->cost_value_gbp <> 0) {
                $component->npv->valueGbp($this->time, $component->cost_value_gbp);
            }
        }
    }

    /**
     * @throws Exception
     */
    function simulate($pre_parse_only, $projection_id, $config_combined): void {
        $config = $config_combined['config'];
        $this->check = new Check();
        $this->check->checkValue($config, 'location', [],              'coordinates',        self::CHECKS['location']);
        $this->check->checkValue($config, 'location', ['coordinates'], 'latitude_degrees',   self::CHECKS['location']);
        $this->check->checkValue($config, 'location', ['coordinates'], 'longitude_degrees',  self::CHECKS['location']);
        $this->check->checkValue($config, 'location', [],              'cloud_cover_months', self::CHECKS['location']);
        $this->check->checkValue($config, 'location', ['cloud_cover_months'], 'fractions',   self::CHECKS['location']);
        $this->check->checkValue($config, 'location', ['cloud_cover_months'], 'factors',     self::CHECKS['location']);
        $this->temperature_target_internal_c         = $this->check->checkValue($config, 'location', ['internal'],'temperature_target_celsius',  self::CHECKS['location'], self::DEFAULT_TEMPERATURE_TARGET_CELSIUS);
        $this->temperature_internal_decay_rate_per_s = log(2.0) / ($this->check->checkValue($config, 'location', ['internal'],'temperature_half_life_days', self::CHECKS['location'], self::TEMPERATURE_HALF_LIFE_DAYS) * 24 * 3600);
        $this->temperature_target_hours              = array_flip($this->check->checkValue($config, 'location', ['internal'],'target_hours',  self::CHECKS['location'], self::DEFAULT_TEMPERATURE_TARGET_HOURS));
        $this->instantiateComponents($config);
        if (!$pre_parse_only) {
            if (($config['heat_pump']['include'] ?? false) && ($scop = $config['heat_pump']['scop'] ?? false)) {  // normalise cop performance to declared scop
                if (DEBUG) {
                    echo 'Calibrating SCOP: ';
                }
                $results = $this->traverseYears(true, $projection_id, $config_combined, 1.0);
                if (DEBUG) {
                    echo PHP_EOL;
                }
                $cop_factor = $scop / $results['scop'];
            } else {
                $cop_factor = 1.0;
            }
            $this->instantiateComponents($config);
            $this->traverseYears(false, $projection_id, $config_combined, $cop_factor);
        }
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateMalformedIntervalStringException
     * @throws Exception
     */
    function instantiateComponents($config): void {
        $this->time                         = new Time(           $this->check, $config);
        $this->hot_water_tank               = new HotWaterTank(   $this->check, $config, false, $this->time);
        $this->demand_space_heating_thermal = new Demand(         $this->check, $config, 'space_heating_thermal',   $this->temperature_target_internal_c);
        $this->demand_hotwater_thermal      = new Demand(         $this->check, $config, 'hot_water_thermal',       null);
        $this->demand_non_heating_electric  = new Demand(         $this->check, $config, 'non_heating_electric',    null);
        $this->supply_grid                  = new Supply(         $this->check, $config, 'grid',                      $this->time);
        $this->supply_boiler                = new Supply(         $this->check, $config, 'boiler',                    $this->time);
        $this->boiler                       = new Boiler(         $this->check, $config, $this->time);
        $this->solar_pv                     = new SolarCollectors($this->check, $config, 'solar_pv',      $config['location'], 0.0, $this->time);
        $this->solar_thermal                = new SolarCollectors($this->check, $config, 'solar_thermal', $config['location'], 0.0, $this->time);
        $this->battery                      = new Battery(        $this->check, $config, $this->time);
        $this->heat_pump                    = new HeatPump(       $this->check, $config, $this->time);
        $this->insulation                   = new Insulation(     $this->check, $config, $this->time);

        $this->temp_climate_c = (new Climate())->temperatureTime($this->time);  // get initial climate temperature
        $this->hot_water_tank->setTemperature($this->temp_climate_c);
    }

    /**
     * @throws Exception
     */
    function traverseYears($calibrating_scop, $projection_id, $config_combined, $cop_factor): array {
        $components = [	$this->supply_grid,
                        $this->supply_boiler,
                        $this->boiler,
                        $this->solar_pv,
                        $this->solar_thermal,
                        $this->battery,
                        $this->hot_water_tank,
                        $this->heat_pump,
                        $this->insulation];
        $components_included = [];
        foreach ($components as $component) {
            if ($component->include ?? false) {
                $components_included[] = $component;
            }
        }
        $this->install($components_included);                                                                                                // get install costs
        $this->yearSummary($calibrating_scop, $projection_id, $components_included, $config_combined);                                       // summarise year 0
        if ($calibrating_scop) {                                                                                                             // get annual average external and internal temperatures
            $house = new ThermalTank(null, null, 'House', $this->time);
            $house->cPerJoule(1.0);                                                                                                 // set house thermal inertia
            $house->setTemperature($this->temp_climate_c);
            $house->decay_rate_per_s = $this->temperature_internal_decay_rate_per_s;
            $sum_count = 0;
            $sum_temp_climate_c  = 0.0;
            $sum_temp_internal_c = 0.0;
        }
        while ($this->time->nextTimeStep()) {                                                                                                // timestep through years 0 ... N-1
            $this->temp_climate_c = (new Climate())->temperatureTime($this->time);	                                                         // update climate temperature
            if ($calibrating_scop) {                                                                                                         // get annual average external and internal temperatures
                if (!isset($this->temperature_target_hours[$this->time->values['HOUR_OF_DAY']])) {
                    $house->decay($this->temp_climate_c);    // cool down
                }
                else {
                    $house->setTemperature($this->temperature_target_internal_c);                                                                   // restore to target temperature
                }
                $sum_count++;
                $sum_temp_climate_c  += $this->temp_climate_c;
                $sum_temp_internal_c += $house->temperature_c;
            }
            $this->valueTimeStep($components_included, $this->time);                                                                         // add timestep component maintenance costs
            $this->supply_grid->updateTariff($this->time);                                                                                   // get supply bands
            $this->supply_boiler->updateTariff($this->time);
            $supply_electric_j = 0.0;                                                                                                        // zero supply balances for timestep
            $supply_boiler_j   = 0.0;				                                                                                         // export: +ve, import: -ve

            // battery
            if ($this->battery->include && ($this->supply_grid->current_bands['import'] == 'off_peak')) {	                                 // charge battery when import off peak
                $to_battery_j       = $this->battery->transferConsumeJ($this->time->step_s * $this->battery->max_charge_w)['consume']; // charge at max rate until full
                $supply_electric_j -= $to_battery_j;
            }
            // solar pv
            if ($this->solar_pv->include) {
                $solar_pv_j         = $this->solar_pv->transferConsumeJ($this->temp_climate_c, $this->time)['transfer'];                           // get solar electrical energy
                $supply_electric_j += $solar_pv_j;				                                                                             // start electric balance: surplus (+), deficit (-)
            }
            // satisfy hot water demand
            $demand_thermal_hotwater_j                 = $this->demand_hotwater_thermal->demandJ($this->time);                               // hot water energy demand
            if ($demand_thermal_hotwater_j > 0.0) {
                $hotwater_tank_transfer_consume_j      = $this->hot_water_tank->transferConsumeJ(-$demand_thermal_hotwater_j, $this->temperature_target_internal_c); // try to satisfy demand from hotwater tank;
                if (($demand_thermal_hotwater_j += $hotwater_tank_transfer_consume_j['transfer']) > 0.0) {                                   // if insufficient energy in hotwater tank, get from elsewhere
                    if ($this->boiler->include) {                                                                                            // else use boiler if available
                        $boiler_j     = $this->boiler->transferConsumeJ($demand_thermal_hotwater_j);
                        $supply_boiler_j              -= $boiler_j['consume'];
                    }
                    else {
                        $supply_electric_j            -= $demand_thermal_hotwater_j;                                                         // use electricity to satisfy any remaining demand
                    }
                }
            }
            // heat hot water tank if necessary
            if ($this->solar_thermal->include) {
                $solar_thermal_hotwater_j = $this->solar_thermal->transferConsumeJ($this->temperature_target_internal_c, $this->time)['transfer'];         // generated solar thermal energy
                if ($this->hot_water_tank->temperature_c < $this->hot_water_tank->target_temperature_c) {                                    // heat hot water tank from solar thermal if necessary                                                                          // top up with solar thermal
                    $solar_thermal_hotwater_j -= $this->hot_water_tank->transferConsumeJ($solar_thermal_hotwater_j, $this->temp_climate_c)['consume']; // deduct hot water consumption from solar thermal generation
                }
            }
            else {
                $solar_thermal_hotwater_j = 0.0;
            }
            if ($this->hot_water_tank->temperature_c < $this->hot_water_tank->target_temperature_c) {
                if ($this->heat_pump->include) {                                                                                             // use heat pump
                    $heatpump_j         = $this->heat_pump->transferConsumeJ($this->heat_pump->max_output_j,                                 // get energy from heat pump
                                                                  $this->hot_water_tank->temperature_c - $this->temp_climate_c,
                                                                             $this->time,
                                                                             $cop_factor);
                    $supply_electric_j   -= $heatpump_j['consume'];                                                                          // consumes electricity
                    $this->hot_water_tank->transferConsumeJ($heatpump_j['transfer'], $this->temperature_target_internal_c);                                // put energy in hotwater tank
                }
                elseif ($this->boiler->include) {                                                                                            // use boiler
                    $boiler_j           = $this->boiler->transferConsumeJ($this->boiler->max_output_j);                                      // get energy from boiler
                    $hotwater_j         = $this->hot_water_tank->transferConsumeJ($boiler_j['transfer'], $this->temperature_target_internal_c);            // put energy in hotwater tank
                    $supply_boiler_j   -= $hotwater_j['consume'];                                                                            // consumes oil/gas
                }
                else {                                                                                                                       // use immersion heater
                    $hotwater_j         = $this->hot_water_tank->transferConsumeJ($this->time->step_s * $this->hot_water_tank->immersion_w,
                                                                                  $this->temperature_target_internal_c);                                   // put energy in hotwater tank
                    $supply_electric_j -= $hotwater_j['consume'];                                                                            // consumes electricity
                }
            }
            // satisfy space heating-cooling demand
            $demand_thermal_space_heating_j                     = $this->demand_space_heating_thermal->demandJ($this->time)*$this->insulation->space_heating_demand_factor; // get space heating energy demand
            if ($solar_thermal_hotwater_j > 0.0) {
                $demand_thermal_space_heating_j                -= $solar_thermal_hotwater_j;                                                                  // use remaining solar thermal (if any) for space heating
            }
            if ($this->heat_pump->include) {                                                                                                                  // use heatpump if available
                if ($demand_thermal_space_heating_j >= 0.0     && $this->heat_pump->heat) {                                                                   // heating
                    $heatpump_transfer_thermal_space_heating_j  = $this->heat_pump->transferConsumeJ($demand_thermal_space_heating_j,
                                                                                    $this->temperature_target_internal_c - $this->temp_climate_c,
                                                                                               $this->time,
                                                                                               $cop_factor);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
                elseif ($demand_thermal_space_heating_j <  0.0 && $this->heat_pump->cool) {                                                                   // cooling
                    $heatpump_transfer_thermal_space_heating_j  = $this->heat_pump->transferConsumeJ($demand_thermal_space_heating_j,
                                                                                     $this->temp_climate_c - $this->temperature_target_internal_c,
                                                                                                $this->time,
                                                                                                $cop_factor);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
            }
            if ($demand_thermal_space_heating_j > 0.0) {
                if ($this->boiler->include) {                                                                                                                  // use boiler if available and necessary
                    $boiler_j                               = $this->boiler->transferConsumeJ($demand_thermal_space_heating_j);
                    $supply_boiler_j                       -= $boiler_j['consume'];
                }
                else {
                    $supply_electric_j                     -= $demand_thermal_space_heating_j;                                                                 // otherwise use electricity
                }
            }
            $demand_electric_non_heating_j                  = $this->demand_non_heating_electric->demandJ($this->time);                                        // electrical non-heating demand
            $supply_electric_j                             -= $demand_electric_non_heating_j;			                    	                               // satisfy electric non-heating demand
            if ($this->battery->include) {
                if ($this->supply_grid->current_bands['export'] == 'peak') {                                                                                   // export peak time
                    $to_battery_j                           = $this->battery->transferConsumeJ(-1E9)['transfer'];                              // discharge battery at max power until empty
                    $supply_electric_j                     -= $to_battery_j;
                }
                elseif ($this->supply_grid->current_bands['export'] == 'standard') {                                                                            // satisfy demand from battery when standard rate
                    $to_battery_j                           = $this->battery->transferConsumeJ($supply_electric_j)['transfer'];
                    $supply_electric_j                     -= $to_battery_j;
                }
            }
            if ($supply_electric_j > 0.0) {                                                                                                                     // export if surplus energy
                $export_limit_j    = 1000.0*$this->time->step_s*$this->supply_grid->tariff['export']['limit_kw'];
                $supply_electric_j = min($supply_electric_j, $export_limit_j);                                                                                  // cap to export limit
            }
            $this->supply_grid->transferTimestepConsumeJ($this->time,  $supply_electric_j);                                                                     // import if supply -ve, export if +ve
            $this->supply_boiler->transferTimestepConsumeJ($this->time, $supply_boiler_j);                                                                      // import boiler fuel consumed
            $this->hot_water_tank->decay(0.5*($this->temperature_target_internal_c + $this->temp_climate_c));                                        // hot water tank cooling to midway between room and outside temps
            if ($this->time->yearEnd()) {                                                                                                                       // write summary to db at end of each year's simulation
                $results = $this->yearSummary($calibrating_scop, $projection_id, $components_included, $config_combined);                                       // summarise year at year end
                if ($calibrating_scop) {
                    $this->average_temp_climate_c      = $sum_temp_climate_c  / $sum_count;
                    $this->average_temp_internal_c     = $sum_temp_internal_c / $sum_count;
                    $house_heating_thermal_w_per_c     = $this->demand_space_heating_thermal->total_annual_j / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR * ($this->average_temp_internal_c - $this->average_temp_climate_c));
                    $heat_capacity_j_per_c             = $house_heating_thermal_w_per_c / $house->decay_rate_per_s;
                    $house->thermal_compliance_c_per_j = 1.0/$heat_capacity_j_per_c;
                    $heat_capacity_kwh_per_c           = $heat_capacity_j_per_c / (1000.0 * Energy::SECONDS_PER_HOUR);

                    $steps_per_day = self::HOURS_PER_DAY * self::SECONDS_PER_HOUR / $this->time->step_s;
                    $month = 1;  // optimise set back temperatures for each month of the year
                    while ($month <= self::MONTHS_PER_YEAR) {
                        $this->time->beginDayMiddle($month);
                        $step_count = 0;
                        $climate_temps = [];
                        while ($step_count < $steps_per_day) { // make problem arrays
                            $climate_temps[$step_count] = (new Climate())->temperatureTime($this->time);
                            $this->supply_grid->updateTariff($this->time);
                            $import_gbp_per_kwh[$step_count] = $this->supply_grid->tariff['import'][$this->time->values['HOUR_OF_DAY']]['gbp_per_kwh'];
                            $this->time->nextTimeStep();
                            $step_count++;
                        }
                        // load setback hour first guesses
                        $setback_temps_c = [];
                        for ($hour = 0; $hour < self::HOURS_PER_DAY; $hour++) {
                            $setback_temps_c[$hour] = $this->temperature_target_internal_c;
                        }



                        $day_cost = $this->dayCost( $setback_temps_c,
                                                    $this->temperature_target_internal_c,
                                                    $this->temperature_target_hours,
                                                    $this->time->step_s,
                                                    $climate_temps,
                                                    $import_gbp_per_kwh,
                                                    $house,
                                                    $this->heat_pump);
                        $month++;
                    }
                    return $results;
                }
            }
        }


        return $results;
    }

    function dayCost($setback_temps_c, $temperature_target_internal_c, $target_hours, $time_step_s, $climate_temps, $import_gbp_per_kwh, $house, $heat_pump): float {
        $day_cost = 0.0;
        $steps_count = count($climate_temps);
        $seconds = 0;
        for ($step = 0; $step < $steps_count; $step++) {
            $hour                = (int) ($seconds / self::SECONDS_PER_HOUR);
            $temp_target_c       = isset($target_hours[$hour]) ? $temperature_target_internal_c : $setback_temps_c[$hour];
            if ($temp_target_c < $house->temperature_c) {
                $import_gbp_per_kwh = $import_gbp_per_kwh[$step];
                $cop = $heat_pump->cop($temp_target_c - $climate_temps[$step]);
            }



        }
        return $day_cost;
    }

    /**
     * @throws Exception
     */
    public function yearSummary($calibrating_scop, $projection_id, $components_included, $config_combined): array {
        if (DEBUG) {
            echo ($this->time->year ? ', ' : '') . $this->time->year;
        }
        $this->supply_grid->sum($this->time);
        $this->supply_boiler->sum($this->time);
        $consumption = self::consumption();
        $results['npv_summary'] = self::npvSummary($components_included); // $results['npv_summary']['components']['13.5kWh battery'] is unset after $time->year == 8
        $results['consumption'] = $consumption;
        if (($this->heat_pump->include ?? false) && $this->time->year) {
            $kwh = $this->heat_pump->kwh['YEAR'][$this->time->year -1];
            $results['scop'] = $kwh['consume_kwh'] ? round($kwh['transfer_kwh'] / $kwh['consume_kwh'], 3) : null;
        }
        if (!$calibrating_scop) {
            $result = ['newResultId' => $this->combinationId($projection_id, $config_combined),
                       'combination' => $config_combined['combination']];
            $this->updateCombination($config_combined, $result, $results, $this->time->year);  // end projection
        }
        return $results;
    }

    private function combinationId($projection_id, $config_combined): int { // returns combination id
        $acronym       = $config_combined['acronym'];
        $combination   = $config_combined['combination'];
        $battery       = $combination['battery'];
        $heat_pump     = $combination['heat_pump'];
        $insulation    = $combination['insulation'];
        $boiler        = $combination['boiler'];
        $solar_pv      = $combination['solar_pv'];
        $solar_thermal = $combination['solar_thermal'];
        $sql = 'INSERT IGNORE INTO `combinations` (`projection`, `acronym`, `battery`, `heat_pump`, `insulation`, `boiler`, `solar_pv`, `solar_thermal`, `start`, `stop`)
			                               VALUES (?,            ?,              ?,         ?,       ?,            ?,        ?,          ?,               NOW(),   NULL  )';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('isiiiiii', $projection_id, $acronym, $battery, $heat_pump, $insulation, $boiler, $solar_pv, $solar_thermal) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $id = $this->mysqli->insert_id;
        $this->mysqli->commit();
        return $id;
    }

    function npvSummary($components): array {
        $npv = [];
        $npv_components = [];
        $sum_gbp = 0.0;
        foreach ($components as $component) {
            if ($component->include) {
                $name = $component->name;
                if ($type = $component->type ?? false) {
                    $name .= ' (' . $type . ')';
                }
                $value_gbp = $component->npv->value_gbp;
                $npv_components[$name] = round($value_gbp, 2);
                $sum_gbp += $value_gbp;
            }
        }
        $npv['sum_gbp'] = round($sum_gbp, 2);
        $npv['components'] = $npv_components;
        return $npv;
    }
}