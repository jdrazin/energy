<?php

namespace Src;

use Exception;
use GuzzleHttp\Exception\GuzzleException;

class EnergyCost extends Root
{
    const bool      DEBUG = false;
    const float     THRESHOLD_POWER_W = 100.0;

    const string    JSON_PROBLEM            = '/var/www/html/energy/test/problem.json',
                    JSON_PROBLEM_DEBUG      = '/var/www/html/energy/test/problem_debug.json',
                    PYTHON_SCRIPT_COMMAND   = 'python3 /var/www/html/energy/src/optimize.py';

    const array     HOURLY_WEIGHTED_PARAMETER_NAMES = [
                                                        'total_load_kws',
                                                        'import_gbp_per_kwhs',
                                                        'export_gbp_per_kwhs'
                                                      ];

    // setup parameters
    protected float $batteryCapacityKwh,
                    $batteryOneWayStorageEfficiency,
                    $batteryDepthOfDischargePercent,
                    $batteryWearCostGbpPerKwh,
                    $batteryWearRatio,
                    $batteryOutOfSpecCostMultiplier,
                    $batteryMaxChargeKw,
                    $batteryMaxDischargeKw,
                    $slotDurationHour,
                    $batteryEnergyInitialKwh,
                    $importLimitKw,
                    $exportLimitKw;

    private array   $problem,
                    $total_load_kws,
                    $import_gbp_per_kws,
                    $export_gbp_per_kws,
                    $tariff_combination,
                    $costs;

    public array   $slotCommands;

    public string $string;
    private int $number_slots;
    private mixed $db_slots;

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function __construct($db_slots)
    {
        parent::__construct();
        $this->db_slots             = $db_slots;
        $this->tariff_combination   = $this->db_slots->tariff_combination;
        $this->slotDurationHour     = (float)(DbSlots::SLOT_DURATION_MIN / 60);
        $this->number_slots         = 24 * 60 / DbSlots::SLOT_DURATION_MIN;
        $loadImportExports          = $this->loadImportExport();
        $this->problem              = [
                                        'batteryCapacityKwh'             => $this->config['battery']['initial_raw_capacity_kwh'],
                                        'batteryDepthOfDischargePercent' => $this->config['battery']['permitted_depth_of_discharge_percent'],
                                        'batteryOneWayStorageEfficiency' => $this->config['battery']['inverter']['one_way_storage_efficiency'],
                                        'batteryWearCostGbpPerKwh'       => $this->config['battery']['wear_cost_gbp_per_kwh'],
                                        'batteryWearRatio'               => $this->config['battery']['wear_ratio'],
                                        'batteryOutOfSpecCostMultiplier' => $this->config['battery']['out_of_spec_cost_multiplier'],
                                        'batteryMaxChargeKw'             => $this->config['battery']['max_charge_kw'],
                                        'batteryMaxDischargeKw'          => $this->config['battery']['max_discharge_kw'],
                                        'importLimitKw'                  => $this->config['energy']['electric']['import']['limit_kw'],
                                        'exportLimitKw'                  => $this->config['energy']['electric']['export']['limit_kw'],
                                        'batteryEnergyInitialKwh'        => (new GivEnergy())->battery($this->db_slots)['effective_stored_kwh'], // get battery state of charge and extrapolate to beginning of slots
                                        'slotDurationHour'               => $this->slotDurationHour,
                                        'number_slots'                   => $this->number_slots,
                                        'import_gbp_per_day'             => $loadImportExports['import_gbp_per_day'],
                                        'export_gbp_per_day'             => $loadImportExports['export_gbp_per_day'],
                                        'import_gbp_per_kwhs'            => $loadImportExports['import_gbp_per_kwhs'],
                                        'export_gbp_per_kwhs'            => $loadImportExports['export_gbp_per_kwhs'],
                                        'load_kws'                       => $loadImportExports['load_kws'],
                                      ];
        if (!($json_problem = json_encode($this->problem, JSON_PRETTY_PRINT)) ||
            !file_put_contents(self::JSON_PROBLEM, $json_problem)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write json problem parameters');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function optimise(): ?array // returns optimum battery charge level for next slot
    {
        //
        // see https://scipy-lectures.org/advanced/mathematical_optimization/#knowing-your-problem
        //
        // convex, non-smooth, exact cost
        //
        if (self::DEBUG) {  // use debug JSON and make slot arrays as necessary
           $this->problem           = $this->makeSlotsArrays( json_decode(file_get_contents(self::JSON_PROBLEM_DEBUG), true));
           $this->total_load_kws    = $this->problem['total_load_kws'];  // get load from problem
           $this->insertLoadKwsClean();
        }
        else {
            $this->problem          = json_decode(file_get_contents(self::JSON_PROBLEM), true);
            $this->total_load_kws   = $this->total_load_kws();  // get load_kws from db
        }
        $command = $this->command();
        $this->costs = [];
        $this->costs['raw'] = $this->costCLI($command, $this->total_load_kws);    // calculate pre-optimised cost using load with CLI command
        $output = shell_exec($command);                                           // execute Python command and capture output
        $result = json_decode($output, true);                           // decode JSON output from Python
        if (!($result['success'] ?? false)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Convergence failure');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
        // calculate optimised cost elements using CLI command
        $this->costs['optimised'] = $this->costCLI($command, $optimumGridKws = $result['optimumGridKws']);
        $this->insertOptimumGridInverterKw($optimumGridKws);                      // insert for each slot: grid and battery discharge energies (kWh)
        if (self::DEBUG) {
            echo 'Php    raw cost: '        . $this->costs['raw']['cost']         . ' GBP' . PHP_EOL;
            echo 'Python optimised cost: '  . $result['energyCost']               . ' GBP' . PHP_EOL;
            echo 'Php    optimised cost: '  . $this->costs['optimised']['cost']   . ' GBP' . PHP_EOL;
            return null;
        }
        else {
            $this->slotCommands();
            $this->insertSlotNextDayCostEstimates();
            return $this->slotCommands[0];
        }
    }

    private function makeSlotsArrays($problem): array {
        $number_slots = $problem['number_slots'];
        foreach (self::HOURLY_WEIGHTED_PARAMETER_NAMES as $parameter_name) {
            if ($parameter_array = $problem[$parameter_name . '_weights'] ?? false) {
                $weight_acc = 0.0;
                $weights    = [];
                $weight     = 0.0;
                for ($slot = 0; $slot < $number_slots; $slot++) {
                    $weight = $parameter_array[$slot/2] ?? $weight;
                    $weights[$slot++] = $weight;
                    $weights[$slot  ] = $weight;
                    $weight_acc += $weight;
                }
                if (isset($problem[$parameter_name])) {
                    $value = $problem[$parameter_name];
                    foreach ($weights as $slot => $weight) {
                      $weights[$slot] = round(0.5 * $number_slots * $value * $weight / $weight_acc, 3);
                    }
                }
                $problem[$parameter_name] = $weights;
            }
        }
        return $problem;
    }

    private function command(): string {
        //
        // make CLI command string
        //
        $command = self::PYTHON_SCRIPT_COMMAND . ' ';
        $command .= $this->parameter_name_value('batteryCapacityKwh');
        $command .= $this->parameter_name_value('batteryDepthOfDischargePercent');
        $command .= $this->parameter_name_value('batteryOneWayStorageEfficiency');
        $command .= $this->parameter_name_value('batteryWearCostGbpPerKwh');
        $command .= $this->parameter_name_value('batteryWearRatio');
        $command .= $this->parameter_name_value('batteryOutOfSpecCostMultiplier');
        $command .= $this->parameter_name_value('batteryMaxChargeKw');
        $command .= $this->parameter_name_value('batteryMaxDischargeKw');
        $command .= $this->parameter_name_value('importLimitKw');
        $command .= $this->parameter_name_value('exportLimitKw');
        $command .= $this->parameter_name_value('batteryEnergyInitialKwh');
        $command .= $this->parameter_name_value('slotDurationHour');
        $command .= $this->parameter_name_value('number_slots');

        $number_slots = $this->problem['number_slots'];

        $command .= 'import_gbp_per_kwhs= ';
        $import_gbp_per_kwhs = $this->problem['import_gbp_per_kwhs'];
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $import_gbp_per_kwhs[$slot_count] . ' ';
        }
        $command .= 'export_gbp_per_kwhs= ';
        $export_gbp_per_kwhs = $this->problem['export_gbp_per_kwhs'];
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $export_gbp_per_kwhs[$slot_count] . ' ';
        }
        $command .= 'total_load_kws= ';
        $total_load_kws = $this->problem['total_load_kws'];
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $total_load_kws[$slot_count] . ' ';
        }
        // use load power for first guess
        $command .= 'FIRST_GUESS_grid_kws= ';
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $total_load_kws[$slot_count] . ' ';
        }
        return $command;
    }

    private function costCLI($command, $grid_kws): array
    {
        //
        // calculates cost using SciPy command line arguments and $grid_kw solution
        //
        $this->string = $command;
        $this->strip();
        $this->strip(); // removes PYTHON_SCRIPT_COMMAND
        $this->strip();
        $this->batteryCapacityKwh               = (float) $this->strip();
        $this->strip();
        $this->batteryDepthOfDischargePercent   = (float) $this->strip();
        $this->strip();
        $this->batteryOneWayStorageEfficiency   = (float) $this->strip();
        $this->strip();
        $this->batteryWearCostGbpPerKwh         = (float) $this->strip();
        $this->strip();
        $this->batteryWearRatio                 = (float) $this->strip();
        $this->strip();
        $this->batteryOutOfSpecCostMultiplier   = (float) $this->strip();
        $this->strip();
        $this->batteryMaxChargeKw               = (float) $this->strip();
        $this->strip();
        $this->batteryMaxDischargeKw            = (float) $this->strip();
        $this->strip();
        $this->importLimitKw                    = (float) $this->strip();
        $this->strip();
        $this->exportLimitKw                    = (float) $this->strip();
        $this->strip();
        $this->batteryEnergyInitialKwh          = (float) $this->strip();
        $this->strip();
        $this->slotDurationHour                 = (float) $this->strip();
        $this->strip();
        $this->number_slots                     = (int)   $this->strip();
        $this->strip();
        $this->import_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $this->import_gbp_per_kws[]         = (float) $this->strip();
        }
        $this->strip();
        $this->export_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $this->export_gbp_per_kws[]         = (float) $this->strip();
        }
        $this->strip();
        $this->total_load_kws           = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $this->total_load_kws[]                   = (float) $this->strip();
        }
        return $this->dayCosts($grid_kws);
    }

    private function dayCosts($grid_kws): array {                     // calculate cost components
        $cost_energy_average_per_kwh_acc = 0.0;                       // accumulator for calculating average energy cost
        $battery_level_kwh = $this->batteryEnergyInitialKwh;          // initial battery level
        $battery_level_mid_kwh = $this->batteryCapacityKwh / 2.0;     // midpoint battery level
        $battery_level_max_kwh = (100.0 + $this->batteryDepthOfDischargePercent) * $this->batteryCapacityKwh / 200.0; // max battery operating level
        $cost_min_per_kwh = 2.0 * $this->batteryWearCostGbpPerKwh / (1.0 + $this->batteryWearRatio);             // minimum wear cost at midpoint level
        $cost_grid_import = 0.0;
        $cost_grid_export = 0.0;
        $cost_wear        = 0.0;
        $cost_out_of_spec = 0.0;
        $import_kwh       = 0.0;
        $export_kwh       = 0.0;
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $grid_power_slot_kw = $grid_kws[$slot_count];
            if ($grid_power_slot_kw < -$this->exportLimitKw) {          // clip grid power to import/export limit
                $grid_power_slot_kw = -$this->exportLimitKw;
            } elseif ($grid_power_slot_kw > $this->importLimitKw) {
                $grid_power_slot_kw = $this->importLimitKw;
            }
            $load_kw = $this->total_load_kws[$slot_count];
            $tariff_import_per_kwh = $this->import_gbp_per_kws[$slot_count];
            $tariff_export_per_kwh = $this->export_gbp_per_kws[$slot_count];
            $energy_grid_kwh = $grid_power_slot_kw * $this->slotDurationHour;
            $load_kwh = $load_kw * $this->slotDurationHour;

            // grid
            if ($energy_grid_kwh < 0.0) {
                $export_kwh += $energy_grid_kwh;
                $cost_grid_export += $tariff_export_per_kwh * $energy_grid_kwh;
            } else {
                $import_kwh += $energy_grid_kwh;
                $cost_grid_import += $tariff_import_per_kwh * $energy_grid_kwh;
            }

            // battery
            $battery_charge_kwh = $energy_grid_kwh - $load_kwh;
            $battery_charge_kw = $grid_power_slot_kw - $load_kw;
            $battery_level_kwh += $battery_charge_kwh * $this->batteryOneWayStorageEfficiency;

            // wear
            $battery_level_wear_fraction = abs($battery_level_kwh - $battery_level_mid_kwh) / ($battery_level_max_kwh - $battery_level_mid_kwh);
            if ($battery_level_wear_fraction <= 1.0) {    // wear
                $cost_wear += $cost_min_per_kwh * abs($battery_charge_kwh) * (1.0 + $this->batteryWearRatio * $battery_level_wear_fraction);
            } else {                                        // out of spec
                $cost_out_of_spec += $cost_min_per_kwh * abs($battery_charge_kwh) * ($this->batteryWearRatio + ($battery_level_wear_fraction - 1.0) * $this->batteryOutOfSpecCostMultiplier);
            }

            // out of spec power
            $out_of_spec_kwh = 0.0;
            if ($battery_charge_kw > 0.0) {    // charging
                $excess_kw = $battery_charge_kw - $this->batteryMaxChargeKw;
                if ($excess_kw > 0.0) {
                    $out_of_spec_kwh += $excess_kw * $this->slotDurationHour;
                }
            } else {                              // discharging
                $excess_kw = -$battery_charge_kw - $this->batteryMaxDischargeKw;
                if ($excess_kw > 0.0) {
                    $out_of_spec_kwh += $excess_kw * $this->slotDurationHour;
                }
            }
            $cost_out_of_spec += $out_of_spec_kwh * $this->batteryOutOfSpecCostMultiplier;
            $cost_energy_average_per_kwh_acc += 0.5 * ($tariff_import_per_kwh + $tariff_export_per_kwh);    // accumulate average energy cost
        }
        $cost_level_change = ($this->batteryEnergyInitialKwh - $battery_level_kwh) * $cost_energy_average_per_kwh_acc / ((float) $this->number_slots);
        $cost = $cost_grid_import + $cost_grid_export + $cost_wear + $cost_out_of_spec + $cost_level_change;
        return [
                    'cost'          => $cost,
                    'cost_import'   => $cost_grid_import,
                    'cost_export'   => $cost_grid_export,
                    'cost_wear'     => $cost_wear,
                    'import_kwh'    => $import_kwh,
                    'export_kwh'    => $export_kwh
        ];
    }

    /**
     * @throws Exception
     */
    private function loadImportExport(): array
    { // get slot load, import and export tariffs for each slot
        $sql = 'SELECT      `total_load_kw`,
                            `import_gbp_per_kwh`,
                            `export_gbp_per_kwh`,
                            `import_gbp_per_day`,
                            `export_gbp_per_day`
                   FROM     `slots`
                   WHERE    `tariff_combination` = ? AND 
                            `slot` IS NOT NULL AND
                            NOT `final`
                   ORDER BY `slot`';
        $id = $this->tariff_combination['id'];
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $id) ||
            !$stmt->bind_result($load_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $load_kw = [];
        $import_gbp_per_kwh = [];
        $export_gbp_per_kwh = [];
        while ($stmt->fetch()) {
            $load_kws[] = $load_kw;
            $import_gbp_per_kwhs[] = $import_gbp_per_kwh;
            $export_gbp_per_kwhs[] = $export_gbp_per_kwh;
        }
        return [
                'load_kws'              => $load_kws,
                'import_gbp_per_kwhs'   => $import_gbp_per_kwhs,
                'export_gbp_per_kwhs'   => $export_gbp_per_kwhs,
                'import_gbp_per_day'    => $import_gbp_per_day,
                'export_gbp_per_day'    => $export_gbp_per_day
        ];
    }

    protected function parameter_name_value($parameter_name): string {  // make parameter substring
        return $parameter_name . '= ' . $this->problem[$parameter_name] . ' ';
    }

    /**
     * @throws Exception
     */
    private function insertLoadKwsClean(): void
    {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'UPDATE      `slots` 
                   SET      `total_load_kw`         = ?,
                            `import_gbp_per_kwh`    = ?,
                            `export_gbp_per_kwh`    = ?,
                            `import_gbp_per_day`    = NULL,
                            `export_gbp_per_day`    = NULL,
                            `solar_kw`              = NULL,
                            `load_non_heating_kw`   = NULL,
                            `load_heating_kw`       = NULL     
                   WHERE    `slot`                  = ? AND
                            `tariff_combination`    = ? AND 
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('dddii', $total_load_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $slot, $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $number_slots = $this->problem['number_slots'];
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $total_load_kw      = $this->problem['total_load_kws']     [$slot];
            $import_gbp_per_kwh = $this->problem['import_gbp_per_kwhs'][$slot];
            $export_gbp_per_kwh = $this->problem['export_gbp_per_kwhs'][$slot];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function insertOptimumGridInverterKw($optimum_grid_kws): void
    {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'UPDATE      `slots` 
                   SET      `grid_kw`            = ?,
                            `battery_charge_kw`  = ?,
                            `battery_level_kwh`  = ?
                   WHERE    `slot`               = ? AND
                            `tariff_combination` = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('dddii', $optimum_grid_kw, $battery_kw, $battery_kwh, $slot, $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $battery_kwh = $this->batteryEnergyInitialKwh;
        foreach ($optimum_grid_kws as $slot => $optimum_grid_kw) {
            $battery_kw = $optimum_grid_kw - $this->total_load_kws[$slot];
            $battery_kwh += $battery_kw * DbSlots::SLOT_DURATION_MIN / 60;
            $battery_kw = round($battery_kw, 3);
            $battery_kwh = round($battery_kwh, 3);
            $optimum_grid_kw = round($optimum_grid_kw, 3);
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    private function total_load_kws(): array
    {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT      `total_load_kw` 
                   FROM     `slots`         
                   WHERE    `tariff_combination` = ? AND
                            NOT `final`
                   ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($total_load_kw) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $total_load_kws = [];
        while ($stmt->fetch()) {
            $total_load_kws[] = $total_load_kw;
        }
        return $total_load_kws;
    }

    private function strip(): string
    {
        $pos = strpos($this->string, ' ');
        $argument = substr($this->string, 0, $pos);
        $this->string = substr($this->string, $pos + 1);
        return $argument;
    }

    /**
     * @throws Exception
     */
    private function slotCommands(): void {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT          `id`,
                                `start` AS `start_datetime`,  
                                DATE_FORMAT(`start`, \'%H:%i\') AS `start`,
                                DATE_FORMAT(`stop`, \'%H:%i\') AS `stop`,
                                `grid_kw`,
                                `battery_charge_kw`,
                                `battery_level_kwh`
                    FROM        `slots`
                    WHERE       `tariff_combination` = ? AND
                                NOT `final`
                    ORDER BY    `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $start_datetime, $start, $stop, $grid_kw, $battery_charge_kw, $target_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->slotCommands = [];
        while ($stmt->fetch()) {
            $grid_w = (int)(1000.0 * $grid_kw);
            $dod_margin = (100.0 - $this->batteryDepthOfDischargePercent) / 2.0;
            $target_level_percent_min = $dod_margin;
            $target_level_percent_max = 100.0 - $dod_margin;
            if ($battery_charge_kw > 0.0) {  // CHARGE
                $charge_power_w = (int)round(1000.0 * min($battery_charge_kw, $this->batteryMaxChargeKw));
            } else {                           // DISCHARGE
                $charge_power_w = (int)round(1000.0 * max($battery_charge_kw, -$this->batteryMaxDischargeKw));
            }
            if (abs($grid_w) < self::THRESHOLD_POWER_W) {                                 // ECO if no appreciable import/export
                $mode = 'ECO';
                $abs_charge_power_w = null;
                $target_level_percent = null;
                $message = ' between ' . $start . ' and ' . $stop;
            } elseif (abs($charge_power_w) > self::THRESHOLD_POWER_W) {                   // CHARGE, DISCHARGE when above threshold charge power
                $mode = $charge_power_w > 0 ? 'CHARGE' : 'DISCHARGE';
                $abs_charge_power_w = abs($charge_power_w);
                $target_level_percent = (int)round(min(max(round(100.0 * $target_level_kwh / $this->batteryCapacityKwh), $target_level_percent_min), $target_level_percent_max));
                $message = '@' . round($abs_charge_power_w) . 'W to ' . $target_level_percent . '% between ' . $start . ' and ' . $stop;
            } else {                                                                       // otherwise IDLE
                $mode = 'IDLE';
                $abs_charge_power_w = null;
                $target_level_percent = $target_level_percent_min;
                $message = '';
            }
            $this->slotCommands[] = [
                                        'id'                    => $id,
                                        'start_datetime'        => $start_datetime,
                                        'start'                 => $start,
                                        'stop'                  => $stop,
                                        'mode'                  => $mode,
                                        'abs_charge_power_w'    => $abs_charge_power_w,
                                        'target_level_percent'  => $target_level_percent,
                                        'message'               => $mode . $message
                                    ];
        }
        unset($stmt);
        // update slots with command parameteres
        $sql = 'UPDATE      `slots`
                   SET      `mode`                 = ?,
                            `abs_charge_power_w`   = ?,
                            `target_level_percent` = ?
                   WHERE    `tariff_combination`   = ? AND
                            `slot`                 = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sdiii', $mode, $abs_charge_power_w, $target_level_percent, $tariff_combination_id, $slot)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($this->slotCommands as $slot => $slot_command) {
            $mode                 = $slot_command['mode'];
            $abs_charge_power_w   = $slot_command['abs_charge_power_w'];
            $target_level_percent = $slot_command['target_level_percent'];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function insertSlotNextDayCostEstimates(): void
    {
        $slot                   = $this->slotCommands[0]['id'];
        $standing               = ($this->problem['import_gbp_per_day'] ?? 0.0) + ($this->problem['export_gbp_per_day'] ?? 0.0);
        $raw                    = $this->costs['raw'];
        $raw_import             = round($raw['cost_import'], 3);
        $raw_export             = round($raw['cost_export'], 3);
        $raw_import_kwh         = round($raw['import_kwh'], 3);
        $raw_export_kwh         = round($raw['export_kwh'], 3);
        $optimised              = $this->costs['optimised'];
        $optimised_import       = round($optimised['cost_import'], 3);
        $optimised_export       = round($optimised['cost_export'], 3);
        $optimised_wear         = round($optimised['cost_wear'], 3);
        $optimised_import_kwh   = round($optimised['import_kwh'], 3);
        $optimised_export_kwh   = round($optimised['export_kwh'], 3);
        $tariff_combination_id  = $this->tariff_combination['id'];
        $sql = 'INSERT IGNORE INTO `slot_next_day_cost_estimates` (`slot`, `tariff_combination`, `standing`, `raw_import`, `raw_export`, `raw_import_kwh`, `raw_export_kwh`, `optimised_import`, `optimised_export`, `optimised_wear`, `optimised_import_kwh`, `optimised_export_kwh`)
                                                           VALUES (?,       ?,                   ?,          ?,            ?,            ?,                ?,                ?,                  ?,                  ?,                ?,                      ?                     )';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('iidddddddddd', $slot, $tariff_combination_id, $standing, $raw_import, $raw_export, $raw_import_kwh, $raw_export_kwh, $optimised_import, $optimised_export, $optimised_wear, $optimised_import_kwh, $optimised_export_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
    }
}