<?php
namespace Src;
use DateTime;
use DateTimeZone;
use DivisionByZeroError;
use ErrorException;
use Exception;

class Energy extends Root
{
    const   int TEMPERATURE_INTERNAL_LIVING_CELSIUS = 20;
    const   float JOULES_PER_KWH                    = 1000.0 * 3600.0;
    const   float DAYS_PER_YEAR                     = 365.25;
    const   int HOURS_PER_DAY                       = 24;
    const   int SECONDS_PER_HOUR                    = 3600;
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
                                                       ];
    public Check $check;
    public Time $time;
    public Demand $demand_space_heating_thermal, $demand_hotwater_thermal, $demand_non_heating_electric;
    public Supply $supply_grid, $supply_boiler;
    public Boiler $boiler;
    public SolarCollectors $solar_pv, $solar_thermal;
    public Battery $battery;
    public ThermalTank $hotwater_tank;
    public HeatPump $heat_pump;
    public Insulation $insulation;
    public string $error;
    public float $temp_internal_c;
    public array $time_units                        = ['HOUR_OF_DAY'   => 24,
                                                       'MONTH_OF_YEAR' => 12,
                                                       'DAY_OF_YEAR'   => 366];

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
    public function tariff_combinations(): bool|string {
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

    /**
     * @throws Exception
     */
    public function slot_solution(): bool|string {
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
            !$stmt->bind_param('s', $this->config['time']['zone']) ||
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
            !$stmt->bind_param('s', $this->config['time']['zone']) ||
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
                $config_combined = $this->parameters_combined($config, $combination, $config_combinations->variables);
                $combination_acronym = $config_combined['description'];
                if (DEBUG) {
                    echo PHP_EOL . ($key + 1) . ' of ' . count($combinations) . ' (' . $combination_acronym . '): ';
                }
                $this->simulate($pre_parse_only, $projection_id, $config_combined['config'], $combination, $combination_acronym);
            }
        }
        if (DEBUG) {
            echo PHP_EOL . 'Done' . PHP_EOL;
        }
   }

    private function parameters_combined($config, $combination, $variables): array {
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
                'description' => (rtrim($description, ', ') ? : 'none')];
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
            $this->projectionCombinations($pre_parse_only,null, $config);
        }
        catch (DivisionByZeroError $e){
            $this->error = $e->getMessage();
        }
        catch (ErrorException|Exception $e) {
            $this->error = $e->getMessage();
        }

        // submit projection if it parsed OK
        if (!$this->error) {
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
        return true;
    }

    /**
     * @throws Exception
     */
    public function processNextProjection($projection_id): void  {
        if ($this->config) { // if root config, submit then process it
            $request = json_encode($this->config);
            $projection_id = $this->submitProjection(false, 0, $this->config, $request);
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
            catch (ErrorException $e) {
                $error = $e->getMessage();
            }
            catch (Exception $e) {
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
            $this->write_cpu_seconds($projection_id, time() - $basetime_seconds);
            $this->mysqli->commit();
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) &&
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

    /**
     * @throws Exception
     */
    private function write_cpu_seconds($projection_id, $cpu_seconds): void {
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
    public function get_text($projection_id, $type): ?string {
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
    public function get_projection($projection_id): bool|string {
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

    private function combinationId($projection_id, $combination, $combination_acronym): int { // returns combination id
        $battery       = $combination['battery'];
        $heat_pump     = $combination['heat_pump'];
        $insulation    = $combination['insulation'];
        $boiler        = $combination['boiler'];
        $solar_pv      = $combination['solar_pv'];
        $solar_thermal = $combination['solar_thermal'];
        $sql = 'INSERT IGNORE INTO `combinations` (`projection`, `acronym`, `battery`, `heat_pump`, `insulation`, `boiler`, `solar_pv`, `solar_thermal`, `start`, `stop`)
			                               VALUES (?,            ?,              ?,         ?,       ?,            ?,        ?,          ?,               NOW(),   NULL  )';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('isiiiiii', $projection_id, $combination_acronym, $battery, $heat_pump, $insulation, $boiler, $solar_pv, $solar_thermal) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $id = $this->mysqli->insert_id;
        $this->mysqli->commit();
        return $id;
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
            $consumption['heatpump']      = ['annual' => $this->round_consumption($this->heat_pump->kwh['YEAR'][0])];
        }
        if ($this->solar_pv->include ?? false) {
            $consumption['solar_pv']      = ['annual' => $this->round_consumption($this->solar_pv->output_kwh['YEAR'][0])];
        }
        if ($this->solar_thermal->include ?? false) {
            $consumption['solar_thermal'] = ['annual' => $this->round_consumption($this->solar_thermal->output_kwh['YEAR'][0])];
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
                'grid'   => $this->round_consumption($grid),
                'boiler' => $this->round_consumption($boiler)
            ];
        }
        return $consumption;
    }

    function round_consumption($array): array {
        foreach ($array as $key => $element) {
            if (is_array($element)) {
                $element = $this->round_consumption($element);
            }
            elseif (is_float($element)) {
                $element = round($element, 2);
            }
            $array[$key] = $element;
        }
        return $array;
    }

    function value_maintenance($components, $time): void {
        foreach ($components as $component) {
            $component->value_maintenance($time);
        }
    }

    function install($components): void {
        foreach ($components as $component) {
            if ($component->include && $component->value_install_gbp <> 0) {
                $component->npv->value_gbp($this->time, $component->value_install_gbp);
            }
        }
    }

    /**
     * @throws Exception
     */
    function simulate($pre_parse_only, $projection_id, $config, $combination, $combination_acronym): void {
        $this->instantiateComponents(false, $config);
        if (!$pre_parse_only) {
            if (($config['heat_pump']['include'] ?? false) && ($scop = $config['heat_pump']['scop'] ?? false)) {  // normalise cop performance to declared scop
                if (DEBUG) {
                    echo 'Calibrating SCOP: ';
                }
                $results = $this->traverse_years(true, $projection_id, $config, $combination, $combination_acronym, 1.0);
                if (DEBUG) {
                    echo PHP_EOL;
                }
                $cop_factor = $scop / $results['scop'];
            } else {
                $cop_factor = 1.0;
            }
            $this->instantiateComponents(false, $config);
            $this->traverse_years(false, $projection_id, $config, $combination, $combination_acronym, $cop_factor);
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws \DateMalformedIntervalStringException
     * @throws Exception
     */
    function instantiateComponents($calibrating_scop, $config): void {
        $this->temp_internal_c                = (float) $config['temperatures']['internal_room_celsius'] ?? self::TEMPERATURE_INTERNAL_LIVING_CELSIUS;
        $this->check                          = new Check();
        $this->time                           = new Time($this->check, $config, $this->time_units, $calibrating_scop);
        $this->demand_space_heating_thermal   = new Demand($this->check, $config, 'space_heating_thermal',   $this->temp_internal_c);
        $this->demand_hotwater_thermal        = new Demand($this->check, $config, 'hot_water_thermal',       null);
        $this->demand_non_heating_electric    = new Demand($this->check, $config, 'non_heating_electric',    null);
        $this->supply_grid                    = new Supply($this->check, $config, 'grid',   $this->time);
        $this->supply_boiler                  = new Supply($this->check, $config, 'boiler', $this->time);
        $this->boiler                         = new Boiler($this->check, $config, $this->time);
        $this->solar_pv                       = new SolarCollectors($this->check, $config, 'solar_pv',      $config['location'], 0.0, $this->time);
        $this->solar_thermal                  = new SolarCollectors($this->check, $config, 'solar_thermal', $config['location'], 0.0, $this->time);
        $this->battery                        = new Battery($this->check, $config, $this->time);
        $this->hotwater_tank                  = new ThermalTank($this->check, $config, false, $this->time);
        $this->heat_pump                      = new HeatPump($this->check, $config, $this->time);
        $this->insulation                     = new Insulation($this->check, $config, $this->time);
    }

    /**
     * @throws Exception
     */
    function traverse_years($calibrating_scop, $projection_id, $config, $combination, $combination_acronym, $cop_factor): array {
        $components = [	$this->supply_grid,
                        $this->supply_boiler,
                        $this->boiler,
                        $this->solar_pv,
                        $this->solar_thermal,
                        $this->battery,
                        $this->hotwater_tank,
                        $this->heat_pump,
                        $this->insulation];
        $components_included = [];
        foreach ($components as $component) {
            if ($component->include ?? false) {
                $components_included[] = $component;
            }
        }
        $this->install($components_included);                                                                                                               // get install costs
        $this->year_summary($calibrating_scop, $projection_id, $components_included, $config, $combination, $combination_acronym);                          // summarise year 0
        $export_limit_j = 1000.0*$this->time->step_s*$this->supply_grid->export_limit_kw;
        while ($this->time->next_timestep()) {                                                                                                              // timestep through years 0 ... N-1
            $this->value_maintenance($components_included, $this->time);                                                                                    // add timestep component maintenance costs
            $this->supply_grid->update_bands($this->time);                                                                                                  // get supply bands
            $this->supply_boiler->update_bands($this->time);
            $supply_electric_j = 0.0;                                                                                                                       // zero supply balances for timestep
            $supply_boiler_j   = 0.0;				                                                                                                        // export: +ve, import: -ve
            $temp_climate_c = (new Climate())->temperature_time($this->time);	                                                                            // get average climate temperature for day of year, time of day
            // battery
            if ($this->battery->include && ($this->supply_grid->current_bands['import'] == 'off_peak')) {	                                                // charge battery when import off peak
                $to_battery_j       = $this->battery->transfer_consume_j($this->time->step_s * $this->battery->max_charge_w)['consume'];    // charge at max rate until full
                $supply_electric_j -= $to_battery_j;
            }
            // solar pv
            if ($this->solar_pv->include) {
                $solar_pv_j         = $this->solar_pv->transfer_consume_j($temp_climate_c, $this->time)['transfer'];                                        // get solar electrical energy
                $supply_electric_j += $solar_pv_j;				                                                                                            // start electric balance: surplus (+), deficit (-)
            }
            // satisfy hot water demand
            $demand_thermal_hotwater_j                 = $this->demand_hotwater_thermal->demand_j($this->time);                                              // hot water energy demand
            if ($demand_thermal_hotwater_j > 0.0) {
                $hotwater_tank_transfer_consume_j      = $this->hotwater_tank->transfer_consume_j(-$demand_thermal_hotwater_j, $this->temp_internal_c);      // try to satisfy demand from hotwater tank;
                if (($demand_thermal_hotwater_j += $hotwater_tank_transfer_consume_j['transfer']) > 0.0) {                                                   // if insufficient energy in hotwater tank, get from elsewhere
                    if ($this->boiler->include) {                                                                                                            // else use boiler if available
                        $boiler_transfer_consume_j     = $this->boiler->transfer_consume_j($demand_thermal_hotwater_j);
                        $supply_boiler_j              -= $boiler_transfer_consume_j['consume'];
                    }
                    else {
                        $supply_electric_j            -= $demand_thermal_hotwater_j;                                                                         // use electricity to satisfy any remaining demand
                    }
                }
            }
            // heat hot water tank if necessary
            if ($this->solar_thermal->include) {
                $solar_thermal_hotwater_j = $this->solar_thermal->transfer_consume_j($this->temp_internal_c, $this->time)['transfer'];                       // generated solar thermal energy
                if ($this->hotwater_tank->temperature_c < $this->hotwater_tank->target_temperature_c) {                                                      // heat hot water tank from solar thermal if necessary                                                                          // top up with solar thermal
                    $solar_thermal_hotwater_j -= $this->hotwater_tank->transfer_consume_j($solar_thermal_hotwater_j, $temp_climate_c)['consume'];            // deduct hot water consumption from solar thermal generation
                }
            }
            else {
                $solar_thermal_hotwater_j = 0.0;
            }
            if ($this->hotwater_tank->temperature_c < $this->hotwater_tank->target_temperature_c) {
                if ($this->heat_pump->include) {                  // use heat pump
                    $heatpump_transfer_consume_j         = $this->heat_pump->transfer_consume_j($this->heat_pump->max_output_j,                                 // get energy from heat pump
                                                                             $this->hotwater_tank->temperature_c - $temp_climate_c,
                                                                                        $this->time,
                                                                                        $cop_factor);
                    $supply_electric_j                  -= $heatpump_transfer_consume_j['consume'];                                                           // consumes electricity
                    $this->hotwater_tank->transfer_consume_j($heatpump_transfer_consume_j['transfer'], $this->temp_internal_c);                               // put energy in hotwater tank
                }
                elseif ($this->boiler->include) {                                                                                                             // use boiler
                    $boiler_transfer_consume_j           = $this->boiler->transfer_consume_j($this->boiler->max_output_j);                                    // get energy from boiler
                    $hotwater_transfer_consume_j         = $this->hotwater_tank->transfer_consume_j($boiler_transfer_consume_j['transfer'], $this->temp_internal_c);// put energy in hotwater tank
                    $supply_boiler_j                    -= $hotwater_transfer_consume_j['consume'];                                                           // consumes oil/gas
                }
                else {                                                                                                                                        // use immersion heater
                    $hotwater_transfer_consume_j         = $this->time->step_s * $this->hotwater_tank->immersion_w;                                           // get energy from hotwater immersion element
                    $hotwater_transfer_consume_j         = $this->hotwater_tank->transfer_consume_j($hotwater_transfer_consume_j, $this->temp_internal_c);    // put energy in hotwater tank
                    $supply_electric_j                  -= $hotwater_transfer_consume_j['consume'];                                                           // consumes electricity
                }
            }
            // satisfy space heating-cooling demand
            $demand_thermal_space_heating_j                     = $this->demand_space_heating_thermal->demand_j($this->time)*$this->insulation->space_heating_demand_factor; // get space heating energy demand
            if ($solar_thermal_hotwater_j > 0.0) {
                $demand_thermal_space_heating_j                -= $solar_thermal_hotwater_j;                                                                   // use remaining solar thermal (if any) for space heating
            }
            if ($this->heat_pump->include) {                                                                                                                    // use heatpump if available
                if ($demand_thermal_space_heating_j >= 0.0     && $this->heat_pump->heat) {                                                                     // heating
                    $heatpump_transfer_thermal_space_heating_j  = $this->heat_pump->transfer_consume_j($demand_thermal_space_heating_j,
                                                                                    $this->temp_internal_c - $temp_climate_c,
                                                                                               $this->time,
                                                                                               $cop_factor);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
                elseif ($demand_thermal_space_heating_j <  0.0 && $this->heat_pump->cool) {                                                                     // cooling
                    $heatpump_transfer_thermal_space_heating_j  = $this->heat_pump->transfer_consume_j($demand_thermal_space_heating_j,
                                                                                     $temp_climate_c - $this->temp_internal_c,
                                                                                                $this->time,
                                                                                                $cop_factor);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
            }
            if ($demand_thermal_space_heating_j > 0.0) {
                if ($this->boiler->include) {                                                                                                                   // use boiler if available and necessary
                    $boiler_transfer_consume_j              = $this->boiler->transfer_consume_j($demand_thermal_space_heating_j);
                    $supply_boiler_j                       -= $boiler_transfer_consume_j['consume'];
                }
                else {
                    $supply_electric_j                     -= $demand_thermal_space_heating_j;                                                                  // otherwise use electricity
                }
            }
            $demand_electric_non_heating_j                  = $this->demand_non_heating_electric->demand_j($this->time);                                        // electrical non-heating demand
            $supply_electric_j                             -= $demand_electric_non_heating_j;			                    	                                // satisfy electric non-heating demand
            if ($this->battery->include) {
                if ($this->supply_grid->current_bands['export'] == 'peak') {                                                                                    // export peak time
                    $to_battery_j                           = $this->battery->transfer_consume_j(-1E9)['transfer'];                             // discharge battery at max power until empty
                    $supply_electric_j                     -= $to_battery_j;
                }
                elseif ($this->supply_grid->current_bands['export'] == 'standard') {                                                                            // satisfy demand from battery when standard rate
                    $to_battery_j                           = $this->battery->transfer_consume_j($supply_electric_j)['transfer'];
                    $supply_electric_j                     -= $to_battery_j;
                }
            }
            if ($supply_electric_j > 0.0) {                                                                                                                     // export if surplus energy
                $supply_electric_j = min($supply_electric_j, $export_limit_j);                                                                                  // cap to export limit
            }
            $this->supply_grid->transfer_consume_j($this->time, $supply_electric_j < 0.0 ? 'import' : 'export', $supply_electric_j);                    // import if supply -ve, export if +ve
            $this->supply_boiler->transfer_consume_j($this->time, 'import',                                       $supply_boiler_j);                    // import boiler fuel consumed
            $this->hotwater_tank->decay(0.5*($this->temp_internal_c+$temp_climate_c));                                                        // hot water tank cooling to midway between room and outside temps
            if ($this->time->year_end()) {                                                                                                                      // write summary to db at end of each year's simulation
                $results = $this->year_summary($calibrating_scop, $projection_id, $components_included, $config, $combination, $combination_acronym);           // summarise year at year end
                if ($calibrating_scop) {
                    return $results;
                }
            }
        }
        return $results;
    }

    /**
     * @throws Exception
     */
    public function year_summary($calibrating_scop, $projection_id, $components_active, $config, $combination, $combination_acronym): array {
        if (DEBUG) {
            echo ($this->time->year ? ', ' : '') . $this->time->year;
        }
        $this->supply_grid->sum($this->time);
        $this->supply_boiler->sum($this->time);
        $consumption = self::consumption();
        $results['npv_summary'] = self::npv_summary($components_active); // $results['npv_summary']['components']['13.5kWh battery'] is unset after $time->year == 8
        $results['consumption'] = $consumption;
        if (($this->heat_pump->include ?? false) && $this->time->year) {
            $kwh = $this->heat_pump->kwh['YEAR'][$this->time->year -1];
            $results['scop'] = $kwh['consume_kwh'] ? round($kwh['transfer_kwh'] / $kwh['consume_kwh'], 3) : null;
        }
        if (!$calibrating_scop) {
            $result = ['newResultId' => $this->combinationId($projection_id, $combination, $combination_acronym),
                       'combination' => $combination];
            $this->updateCombination($config, $result, $results, $this->time->year);  // end projection
        }
        return $results;
    }

    function npv_summary($components): array {
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