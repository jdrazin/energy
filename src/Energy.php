<?php
namespace Src;
use Exception;

class Energy extends Root
{
    const   int TEMPERATURE_INTERNAL_LIVING_CELSIUS = 20;
    const   float JOULES_PER_KWH                    = 1000.0 * 3600.0;
    const   float DAYS_PER_YEAR                     = 365.25;
    const   int HOURS_PER_DAY                       = 24;
    const   int SECONDS_PER_HOUR                    = 3600;
    public float $step_s;
    public array $time_units                        = [ 'HOUR_OF_DAY'   => 24,
                                                        'MONTH_OF_YEAR' => 12,
                                                        'DAY_OF_YEAR'   => 366];
    private $jobId;

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
    public function slots($request): bool|string {
        if (!$this->basicAuth($request)) {
            return false;
        }
        $sql = 'SELECT      `unix_timestamp`,
                            `load_house_kw`,
                            `previous_load_house_kw`,
                            `grid_kw`,
                            `previous_grid_kw`,
                            `solar_kw`,
                            `previous_solar_kw`,
                            `battery_level_percent`,
                            `previous_battery_level_percent`
                  FROM      `slots_cubic_splines`
                  ORDER BY  `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_percent, $previous_battery_level_percent) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slots_cubic_splines = [];
        while ($stmt->fetch()) {
            $slots_cubic_splines[] = [$unix_timestamp,  $load_house_kw,  $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_percent, $previous_battery_level_percent];
        }
        return json_encode($slots_cubic_splines, JSON_PRETTY_PRINT);
    }

    /**
     * @throws Exception
     */
    public function tariff_combinations($request): bool|string {
        if (!$this->basicAuth($request)) {
            return false;
        }
        $sql = 'SELECT  UNIX_TIMESTAMP(`s`.`start`) AS `start`,
                        CONCAT(`ti`.`code`, \', \', `te`.`code`, CONVERT(IF((`tc`.`active` IS NULL), \'\', \' *ACTIVE*\') USING utf8mb4), \' (\', `tc`.`id`, \')\') AS `tariff [import, export]`,
                        `tc`.`result`,
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
                    JOIN `slots`               `s`  ON `s`.`id` = `sndce`.`slot`
                    ORDER BY ROUND((((`sndce`.`raw_import` + `sndce`.`raw_export`) + `sndce`.`standing`) - ((`sndce`.`optimised_import` + `sndce`.`optimised_export`) + `sndce`.`standing`)), 2) DESC';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($start, $result, $tariff_combination, $raw_gbp, $optimised_gbp, $grid_saving_gbp, $total_saving_gbp, $saving_percent, $wear_percent) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $tariff_combinations = [];
        $tariff_combinations[] = ['Starting', 'Tariff combination [import, export]', 'Result', 'Grid: raw (£)', 'Grid: optimised (£)', 'Grid: saving (£)', 'Net saving (£)', 'Grid: saving (%)', 'Wear (%)'];
        while ($stmt->fetch()) {
            $tariff_combinations[] = [$start, $result, $tariff_combination, $raw_gbp, $optimised_gbp, $grid_saving_gbp, $total_saving_gbp, $saving_percent, $wear_percent];
        }
        return json_encode($tariff_combinations, JSON_PRETTY_PRINT);
    }

    /**
     * @throws Exception
     */
    public function permute(): void  {
        echo 'processing ' . self::APIS_PATH . PHP_EOL;
        $this->jobId = crc32(json_encode($this->config));
        $this->deleteJob();
        $config_permutations = new ParameterPermutations($this->config);
        $permutations = $config_permutations->permutations;
        foreach ($permutations as $key => $permutation) {
            $config_permuted = $this->parameters_permuted($this->config, $permutation, $config_permutations->variables);
            echo PHP_EOL . ($key+1) . ' of ' . count($permutations) . ' (' . $config_permuted['description'] . '): ';
            $this->simulate($config_permuted['config'], $this->config['time']['project_duration_years'], $permutation);
        }
        echo PHP_EOL . 'done' . PHP_EOL;
   }

    private function parameters_permuted($config, $permutation, $variables): array {
        $description = '';
        foreach (ParameterPermutations::PERMUTATION_ELEMENTS as $element_name) {
            $value = (bool) $permutation[$element_name];
            $config[$element_name]['active'] = $value;
            if ($value && in_array($element_name, $variables)) {
                $description .= $element_name . ', ';
            }
        }
        return ['config'  => $config,
                'description' => rtrim($description, ', ')];
    }

    private function deleteJob(): void {
        $sql = 'DELETE FROM `permutations`
			       WHERE `job` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $this->jobId) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
    }
    private function permutationId($permutation): int { // returns permutation id
        $battery       = $permutation['battery'];
        $heat_pump     = $permutation['heat_pump'];
        $boiler        = $permutation['boiler'];
        $solar_pv      = $permutation['solar_pv'];
        $solar_thermal = $permutation['solar_thermal'];
        $sql = 'INSERT INTO `permutations` (`job`, `start`, `stop`, `battery`, `heat_pump`, `boiler`, `solar_pv`, `solar_thermal`)
			                        VALUES (?,     NOW(),   NULL,   ?,         ?,           ?,        ?,          ?              )';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('iiiiii', $this->jobId, $battery, $heat_pump, $boiler, $solar_pv, $solar_thermal) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $id = $this->mysqli->insert_id;
        $this->mysqli->commit();
        return $id;
    }

    /**
     * @throws Exception
     */
    private function updatePermutation($permutation_parameters, $job, $results, $projection_duration_years): void { // add
        $id                 = $job['newResultId'];
        $comment            = $this->config['comment'];
        $sum_gbp            = $results['npv_summary']['sum_gbp'];
        $parameters_json    = json_encode($permutation_parameters, JSON_PRETTY_PRINT);
        $results_json       = json_encode($results, JSON_PRETTY_PRINT);
        unset($this->stmt);
        $sql = 'UPDATE    `permutations`
                    SET   `comment`        = ?,
                          `duration_years` = ?,                    
                          `npv`            = ROUND(?),
                          `config`         = ?,
                          `result`         = ?,
                          `stop`           = NOW()
                    WHERE `id`             = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sidssi', $comment, $projection_duration_years, $sum_gbp, $parameters_json, $results_json, $id) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__,__FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
    }

    private function consumption($time, $supply_electric, $supply_boiler, $heatpump, $solar_pv, $solar_thermal): array {
        $consumption = [];
        if ($heatpump->active) {
            $consumption['heatpump']      = ['annual' => $this->round_consumption($heatpump->kwh['YEAR'][0])];
        }
        if ($solar_pv->active) {
            $consumption['solar_pv']      = ['annual' => $this->round_consumption($solar_pv->output_kwh['YEAR'][0])];
        }
        if ($solar_thermal->active) {
            $consumption['solar_thermal'] = ['annual' => $this->round_consumption($solar_thermal->output_kwh['YEAR'][0])];
        }
        for ($year = 0; $year < $time->year; $year++) {
            $electric = [
                'kwh'       => $supply_electric->kwh['YEAR'][$year],
                'value_gbp' => $supply_electric->value_gbp ['YEAR'][$year]
            ];
            $boiler   = [
                'kwh'       => $supply_boiler->kwh['YEAR'][$year],
                'value_gbp' => $supply_boiler->value_gbp ['YEAR'][$year]
            ];
            $consumption['year'] = [
                'electric' => $this->round_consumption($electric),
                'boiler'   => $this->round_consumption($boiler)
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

    function install($components, $time): void {
        foreach ($components as $component) {
            if ($component->active && $component->value_install_gbp <> 0) {
                $component->npv->value_gbp($time, $component->value_install_gbp);
            }
        }
    }

    /**
     * @throws Exception
     */
    function simulate($config, $project_duration_years, $permutation): void {
        $npv                            = $config['npv'];
        $time                           = new Time($config['time']['start'], $project_duration_years, $config['time']['timestep_seconds'], $this->time_units);
        $this->step_s                   = $time->step_s;
        $temperature_internal_room_c    = (float) $config['temperatures']['internal_room_celsius'] ?? self::TEMPERATURE_INTERNAL_LIVING_CELSIUS;
        $demand_space_heating_thermal   = new Demand($config['demands']['space_heating_thermal'],   $temperature_internal_room_c);
        $demand_hotwater_thermal        = new Demand($config['demands']['hot_water_thermal'],       null);
        $demand_non_heating_electric    = new Demand($config['demands']['non_heating_electric'],     null);
        $supply_electric                = new Supply($config['energy']['electric'],                                      $time, $npv);
        $supply_boiler                  = new Supply($config['energy'][$config['boiler']['tariff']],                     $time, $npv);
        $boiler                         = new Boiler($config['boiler'],                                                  $time, $npv);
        $solar_pv                       = new SolarCollectors($config['solar_pv'],      $config['location'], 0.0,    $time, $npv);
        $solar_thermal                  = new SolarCollectors($config['solar_thermal'], $config['location'], 0.0,    $time, $npv);
        $battery                        = new Battery($config['battery'],                                                $time, $npv);
        $hotwater_tank                  = new ThermalTank($config['storage_hot_water'], false,                  $time, $npv);
        $heatpump                       = new HeatPump($config['heat_pump'],                                             $time, $npv);
        $components = [	$supply_electric,
                        $supply_boiler,
                        $boiler,
                        $solar_pv,
                        $solar_thermal,
                        $battery,
                        $hotwater_tank,
                        $heatpump];
        $components_active = [];
        foreach ($components as $component) {
            if ($component->active) {
                $components_active[] = $component;
            }
        }
        $this->mysqli->commit();
        $this->install($components_active, $time);                                                                                                                  // get install costs
        $this->year_summary($time, $supply_electric, $supply_boiler, $heatpump, $solar_pv,  $solar_thermal, $components_active, $config, $permutation);             // summarise year 0
        $export_limit_j = 1000.0*$time->step_s*$supply_electric->export_limit_kw;
        while ($time->next_timestep()) {                                                                                                                            // timestep through years 0 ... N-1
            $this->value_maintenance($components_active, $time);                                                                                                    // add timestep component maintenance costs
            $supply_electric->update_bands($time);                                                                                                                  // get supply bands
            $supply_boiler->update_bands($time);
            $supply_electric_j = 0.0;                                                                                                                               // zero supply balances for timestep
            $supply_boiler_j   = 0.0;				                                                                                                                // export: +ve, import: -ve
            $temp_climate_c = (new Climate())->temperature_time($time);	                                                                                            // get average climate temperature for day of year, time of day
            // battery
            if ($battery->active && ($supply_electric->current_bands['import'] == 'off_peak')) {	                                                                // charge battery when import off peak, WARNING: transfer_consume_j() must be called EXACTLY ONCE per timestep
                $supply_electric_j -= $battery->transfer_consume_j($time->step_s * $battery->max_charge_w)['consume'];                              // charge at max rate
            }
            // solar pv
            if ($solar_pv->active) {
                $solar_pv_j         = $solar_pv->transfer_consume_j($temp_climate_c, $time)['transfer'];                                                            // get solar electrical energy
                $supply_electric_j += $solar_pv_j;				                                                                                                    // start electric balance: surplus (+), deficit (-)
            }
            // satisfy hot water demand
            $demand_thermal_hotwater_j          = $demand_hotwater_thermal->demand_j($time);                                                                        // hot water energy demand
            if ($demand_thermal_hotwater_j > 0.0) {
                $hotwater_tank_transfer_consume_j      = $hotwater_tank->transfer_consume_j(-$demand_thermal_hotwater_j, $temperature_internal_room_c);             // try to satisfy demand from hotwater tank;
                $demand_thermal_hotwater_j            += $hotwater_tank_transfer_consume_j['transfer'];
                if ($demand_thermal_hotwater_j > 0.0) {                                                                                                             // insufficient energy in hotwater tank, get from elsewhere
                    if ($boiler->active) {                                                                                                                          // else use boiler if available
                        $boiler_transfer_consume_j     = $boiler->transfer_consume_j($demand_thermal_hotwater_j);
                        $supply_boiler_j              -= $boiler_transfer_consume_j['consume'];
                    }
                    else {
                        $supply_electric_j            -= $demand_thermal_hotwater_j;                                                                                // use electricity to satisfy any remaining demand
                    }
                }
            }
            // heat hot water tank if necessary
            if ($solar_thermal->active && ($hotwater_tank->temperature_c < $hotwater_tank->target_temperature_c)) {                                                 // heat hotwater from solar thermal if necessary                                                                          // top up with solar thermal
                $solar_hotwater_j = $solar_thermal->transfer_consume_j($temperature_internal_room_c, $time)['transfer'];
                $hotwater_tank->transfer_consume_j($solar_hotwater_j, $temp_climate_c);
            }
            if ($hotwater_tank->temperature_c < $hotwater_tank->target_temperature_c) {
                if ($heatpump->active) {                  // use heat pump
                    $heatpump_transfer_consume_j         = $heatpump->transfer_consume_j($heatpump->max_output_j, $hotwater_tank->temperature_c - $temp_climate_c, $time); // get energy from heat pump
                    $supply_electric_j                  -= $heatpump_transfer_consume_j['consume'];                                                                 // consumes electricity
                    $hotwater_tank->transfer_consume_j($heatpump_transfer_consume_j['transfer'], $temperature_internal_room_c);                                     // put energy in hotwater tank
                }
                elseif ($boiler->active) {                                                                                                                          // use boiler
                    $boiler_transfer_consume_j           = $boiler->transfer_consume_j($boiler->max_output_j);                                                      // get energy from boiler
                    $hotwater_transfer_consume_j         = $hotwater_tank->transfer_consume_j($boiler_transfer_consume_j['transfer'], $temperature_internal_room_c);// put energy in hotwater tank
                    $supply_boiler_j                    -= $hotwater_transfer_consume_j['consume'];                                                                 // consumes oil/gas
                }
                else {                                                                                                                                              // use immersion heater
                    $hotwater_transfer_consume_j         = $time->step_s * $hotwater_tank->immersion_w;                                                             // get energy from hotwater immersion element
                    $hotwater_transfer_consume_j         = $hotwater_tank->transfer_consume_j($hotwater_transfer_consume_j, $temperature_internal_room_c);          // put energy in hotwater tank
                    $supply_electric_j                  -= $hotwater_transfer_consume_j['consume'];                                                                 // consumes electricity
                }
            }
            // satisfy space heating-cooling demand
            $demand_thermal_space_heating_j = $demand_space_heating_thermal->demand_j($time);                                                                       // get space heating energy demand
            if ($heatpump->active) {                                                                                                                                // use heatpump if available
                if ($demand_thermal_space_heating_j >= 0.0     && $heatpump->heat) {                                                                                // heating
                    $heatpump_transfer_thermal_space_heating_j  = $heatpump->transfer_consume_j($demand_thermal_space_heating_j, $temperature_internal_room_c - $temp_climate_c, $time);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
                elseif ($demand_thermal_space_heating_j <  0.0  && $heatpump->cool) {                                                                               // cooling
                    $heatpump_transfer_thermal_space_heating_j  = $heatpump->transfer_consume_j($demand_thermal_space_heating_j, $temp_climate_c - $temperature_internal_room_c, $time);
                    $demand_thermal_space_heating_j            -= $heatpump_transfer_thermal_space_heating_j['transfer'];
                    $supply_electric_j                         -= $heatpump_transfer_thermal_space_heating_j['consume'];
                }
            }
            if ($demand_thermal_space_heating_j > 0.0) {
                if ($boiler->active) {                                                                                                                              // use boiler if available and necessary
                    $boiler_transfer_consume_j              = $boiler->transfer_consume_j($demand_thermal_space_heating_j);
                    $supply_boiler_j                       -= $boiler_transfer_consume_j['consume'];
                }
                else {
                    $supply_electric_j                     -= $demand_thermal_space_heating_j;                                                                      // otherwise use electricity
                }
            }
            $demand_electric_non_heating_j                  = $demand_non_heating_electric->demand_j($time);                                                        // electrical non-heating demand
            $supply_electric_j                             -= $demand_electric_non_heating_j;			                    	                                    // satisfy electric non-heating demand
            if ($battery->active) {
                if ($supply_electric->current_bands['export'] == 'peak') {                                                                                          // export peak time
                    $supply_electric_j                     += $battery->transfer_consume_j(1E9)['transfer'];                                        // discharge battery as fast as possible
                }
                else {                                                                                                                                              // off-peak:
                    $supply_electric_j                     -= $battery->transfer_consume_j($supply_electric_j)['transfer'];                                         // draw power from battery
                }
            }
            if ($supply_electric_j > 0.0) {                                                                                                                         // export if surplus energy
                $supply_electric_j = min($supply_electric_j, $export_limit_j);
            }
            $supply_electric->transfer_consume_j($time, $supply_electric_j < 0.0 ? 'import' : 'export', $supply_electric_j);                                // import if supply -ve, export if +ve
            $supply_boiler  ->transfer_consume_j($time, 'import',                                       $supply_boiler_j);                                  // import boiler fuel consumed
            $hotwater_tank->decay(0.5*($temperature_internal_room_c+$temp_climate_c));                                                            // hot water tank cooling to midway between room and outside temps
            if ($time->year_end()) {                                                                                                                                // write summary to db at end of each year's simulation
                $this->year_summary($time, $supply_electric, $supply_boiler, $heatpump, $solar_pv, $solar_thermal, $components_active, $config, $permutation);  // summarise year at year end
            }
        }
    }

    /**
     * @throws Exception
     */
    public function year_summary($time, $supply_electric, $supply_boiler, $heatpump, $solar_pv, $solar_thermal, $components_active, $config, $permutation): void {
        echo ($time->year ? ', ' : '') . $time->year;
        $supply_electric->sum($time);
        $supply_boiler->sum($time);
        $consumption = self::consumption($time, $supply_electric, $supply_boiler, $heatpump, $solar_pv, $solar_thermal);
        $results['npv_summary'] = self::npv_summary($components_active);
        $results['consumption'] = $consumption;
        if ($heatpump->active && $time->year) {
            $kwh = $heatpump->kwh['YEAR'][$time->year -1];
            $results['scop'] = $kwh['consume_kwh'] ? round($kwh['transfer_kwh'] / $kwh['consume_kwh'], 3) : null;
        }
        $result = ['newResultId' => $this->permutationId($permutation),
                   'permutation' => $permutation];
        $this->updatePermutation($config, $result, $results, $time->year);  // end job
    }

    function npv_summary($components): array {
        $npv = [];
        $npv_components = [];
        $sum_gbp = 0.0;
        foreach ($components as $component) {
            if ($component->active) {
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