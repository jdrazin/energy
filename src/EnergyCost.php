<?php

namespace Src;

use Exception;
use GuzzleHttp\Exception\GuzzleException;

class EnergyCost extends Root
{
    const bool      DEBUG = true;
    const float     THRESHOLD_POWER_W = 100.0;

    const string    JSON_PROBLEM            = '/var/www/html/energy/test/problem.json',
                    JSON_PROBLEM_DEBUG      = '/var/www/html/energy/test/problem_debug.json',
                    PYTHON_SCRIPT_COMMAND   = 'python3 /var/www/html/energy/src/optimize.py';

    const array     HOURLY_WEIGHTED_PARAMETER_NAMES = [
                                                        'import_gbp_per_kwhs',
                                                        'export_gbp_per_kwhs',
                                                        'load_kws'
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
                    $import_gbp_per_day,
                    $export_gbp_per_day,
                    $importLimitKw,
                    $exportLimitKw;

    private array   $load_kws,
                    $import_gbp_per_kws,
                    $export_gbp_per_kws,
                    $tariff_combination;

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
        $problem                    = [
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
                                        'numberSlots'                    => $this->number_slots,
                                        'import_gbp_per_kwhs'            => $loadImportExports['import_gbp_per_kwhs'],
                                        'export_gbp_per_kwhs'            => $loadImportExports['export_gbp_per_kwhs'],
                                        'load_kws'                       => $loadImportExports['load_kws'],
                                      ];
        if (!($json_problem = json_encode($problem, JSON_PRETTY_PRINT)) ||
            !file_put_contents(self::JSON_PROBLEM, $json_problem)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write json problem parameters');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    public function optimise(): array // returns optimum battery charge level for next slot
    {
        //
        // see https://scipy-lectures.org/advanced/mathematical_optimization/#knowing-your-problem
        //
        // convex, non-smooth, exact cost
        //
        $problem = $this->makeSlotsArrays(json_decode(file_get_contents( self::DEBUG ? self::JSON_PROBLEM_DEBUG : self::JSON_PROBLEM), true));
        $command = $this->command($problem);
        $costs = [];
        $costs['raw'] = $this->costCLI($command, $problem['load_kws']);     // calculate pre-optimised cost using load with CLI command
        $output = shell_exec($command);                                     // execute Python command and capture output
        $result = json_decode($output, true);                     // decode JSON output from Python
        if (!($result['success'] ?? false)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Convergence failure');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
        // calculate optimised cost elements using CLI command
        $costs['optimised'] = $this->costCLI($command, $optimumGridKws = $result['optimumGridKws']);
        if (self::DEBUG) {
            echo 'Php    raw cost: '        . $costs['raw']['cost']         . ' GBP' . PHP_EOL;
            echo 'Python optimised cost: '  . $result['energyCost']         . ' GBP' . PHP_EOL;
            echo 'Php    optimised cost: '  . $costs['optimised']['cost']   . ' GBP' . PHP_EOL;
        }
        $this->insertOptimumGridInverterKw($optimumGridKws);                // insert for each slot: grid and battery discharge energies (kWh)
        $this->insertSlotNextDayCostEstimates($costs, $slot_command = $this->slotCommands()[0]);
        return $slot_command;
    }

    private function makeSlotsArrays($problem): array {
        foreach (self::HOURLY_WEIGHTED_PARAMETER_NAMES as $serial_parameter) {
            if ($serial_parameter_value = $problem[$serial_parameter . '_weights'] ?? false) {

            }
        }
        return $problem;
    }

    private function command($problem): string {
        //
        // make CLI command string
        //
        $command = self::PYTHON_SCRIPT_COMMAND . ' ';
        $command .= $this->argSubstring($problem['batteryCapacityKwh']);
        $command .= $this->argSubstring($problem['batteryDepthOfDischargePercent']);
        $command .= $this->argSubstring($problem['batteryOneWayStorageEfficiency']);
        $command .= $this->argSubstring($problem['batteryWearCostGbpPerKwh']);
        $command .= $this->argSubstring($problem['batteryWearRatio']);
        $command .= $this->argSubstring($problem['batteryOutOfSpecCostMultiplier']);
        $command .= $this->argSubstring($problem['batteryMaxChargeKw']);
        $command .= $this->argSubstring($problem['batteryMaxDischargeKw']);
        $command .= $this->argSubstring($problem['importLimitKw']);
        $command .= $this->argSubstring($problem['exportLimitKw']);
        $command .= $this->argSubstring($problem['batteryEnergyInitialKwh']);
        $command .= $this->argSubstring($problem['slotDurationHour']);
        $command .= $this->argSubstring($number_slots = $problem['numberSlots']);
        $import_gbp_per_kwhs = $problem['import_gbp_per_kwhs'];
        $export_gbp_per_kwhs = $problem['export_gbp_per_kwhs'];
        $load_kws            = $problem['load_kws'];
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $this->argSubstring($import_gbp_per_kwhs [$slot_count]);
            $command .= $this->argSubstring($export_gbp_per_kwhs [$slot_count]);
            $command .= $this->argSubstring($load_kws           [$slot_count]);
        }
        // use load power for first guess
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $this->argSubstring($load_kws[$slot_count]);
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
        $this->batteryCapacityKwh               = (float) $this->strip();
        $this->batteryDepthOfDischargePercent   = (float) $this->strip();
        $this->batteryOneWayStorageEfficiency   = (float) $this->strip();
        $this->batteryWearCostGbpPerKwh         = (float) $this->strip();
        $this->batteryWearRatio                 = (float) $this->strip();
        $this->batteryOutOfSpecCostMultiplier   = (float) $this->strip();
        $this->batteryMaxChargeKw               = (float) $this->strip();
        $this->batteryMaxDischargeKw            = (float) $this->strip();
        $this->importLimitKw                    = (float) $this->strip();
        $this->exportLimitKw                    = (float) $this->strip();
        $this->batteryEnergyInitialKwh          = (float) $this->strip();
        $this->slotDurationHour                 = (float) $this->strip();

        $this->load_kws           = [];
        $this->import_gbp_per_kws = [];
        $this->export_gbp_per_kws = [];
        $this->number_slots                     = (int)   $this->strip();
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $this->import_gbp_per_kws[]         = (float) $this->strip();
            $this->export_gbp_per_kws[]         = (float) $this->strip();
            $this->load_kws[]                   = (float) $this->strip();
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
            $load_kw = $this->load_kws[$slot_count];
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
                            `slot` IS NOT NULL
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
        return ['load_kws' => $load_kws,
            'import_gbp_per_kwhs' => $import_gbp_per_kwhs,
            'export_gbp_per_kwhs' => $export_gbp_per_kwhs,
            'import_gbp_per_day' => $import_gbp_per_day,
            'export_gbp_per_day' => $export_gbp_per_day
        ];
    }

    protected function argSubstring($value): string
    {  // make argument substring
        return $value . ' ';
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
                            `tariff_combination` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('dddii', $optimum_grid_kw, $battery_kw, $battery_kwh, $slot, $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $battery_kwh = $this->batteryEnergyInitialKwh;
        foreach ($optimum_grid_kws as $slot => $optimum_grid_kw) {
            $battery_kw = $optimum_grid_kw - $this->load_kws[$slot];
            $battery_kwh += $battery_kw * DbSlots::SLOT_DURATION_MIN / 60;
            $battery_kw = round($battery_kw, 3);
            $battery_kwh = round($battery_kwh, 3);
            $optimum_grid_kw = round($optimum_grid_kw, 3);
            $stmt->execute();
        }
        $this->mysqli->commit();
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
    private function slotCommands(): array {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT          `id`,
                                `start` AS `start_datetime`,  
                                DATE_FORMAT(`start`, \'%H:%i\') AS `start`,
                                DATE_FORMAT(`stop`, \'%H:%i\') AS `stop`,
                                `grid_kw`,
                                `battery_charge_kw`,
                                `battery_level_kwh`
                    FROM        `slots`
                    WHERE       `tariff_combination` = ?
                    ORDER BY    `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $start_datetime, $start, $stop, $grid_kw, $battery_charge_kw, $target_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slot_commands = [];
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
            $slot_commands[] = [
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
                            `slot`                 = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sdiii', $mode, $abs_charge_power_w, $target_level_percent, $tariff_combination_id, $slot)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($slot_commands as $slot => $slot_command) {
            $mode                 = $slot_command['mode'];
            $abs_charge_power_w   = $slot_command['abs_charge_power_w'];
            $target_level_percent = $slot_command['target_level_percent'];
            $stmt->execute();
        }
        $this->mysqli->commit();
        return $slot_commands;
    }

    /**
     * @throws Exception
     */
    private function insertSlotNextDayCostEstimates($costs, $nextSlotParameters): void
    {
        $slot = $nextSlotParameters['id'];
        $standing = $this->import_gbp_per_day + $this->export_gbp_per_day;
        $raw = $costs['raw'];
        $raw_import = round($raw['cost_import'], 3);
        $raw_export = round($raw['cost_export'], 3);
        $raw_import_kwh = round($raw['import_kwh'], 3);
        $raw_export_kwh = round($raw['export_kwh'], 3);

        $optimised = $costs['optimised'];
        $optimised_import = round($optimised['cost_import'], 3);
        $optimised_export = round($optimised['cost_export'], 3);
        $optimised_wear = round($optimised['cost_wear'], 3);
        $optimised_import_kwh = round($optimised['import_kwh'], 3);
        $optimised_export_kwh = round($optimised['export_kwh'], 3);
        $tariff_combination_id = $this->tariff_combination['id'];
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