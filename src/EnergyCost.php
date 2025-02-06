<?php

namespace Src;

use Exception;
use GuzzleHttp\Exception\GuzzleException;

class EnergyCost extends Root
{
    const bool  DEBUG = false;
    const float DEBUG_BATTERY_LEVEL_PERCENT = 50.0,
        DEBUG_BATTERY_RAW_CAPACITY_KWH = 13.5,
        DEBUG_DEPTH_OF_DISCHARGE_PERCENT = 90.0,
        THRESHOLD_POWER_W = 100.0;
    const array DEBUG_FIRST_GUESS_KW = [
        //             0 => 2.0, 8 => 5.8, 9 => 5.9, 10 => 6.0
    ];

    const string PYTHON_SCRIPT_COMMAND = 'python3 /var/www/html/energy/src/optimize.py';

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

    private array $load_kws,
        $grid_kws,
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
        $this->db_slots = $db_slots;
        $this->tariff_combination = $this->db_slots->tariff_combination;
        $this->slotDurationHour = (float)(DbSlots::SLOT_DURATION_MIN / 60);
        $this->number_slots = 24 * 60 / DbSlots::SLOT_DURATION_MIN;
        $this->batteryOneWayStorageEfficiency = $this->config['battery']['inverter']['one_way_storage_efficiency'];
        $this->batteryWearCostGbpPerKwh = $this->config['battery']['wear_cost_gbp_per_kwh'];
        $this->batteryWearRatio = $this->config['battery']['wear_ratio'];
        $this->batteryOutOfSpecCostMultiplier = $this->config['battery']['out_of_spec_cost_multiplier'];
        $this->batteryMaxChargeKw = $this->config['battery']['max_charge_kw'];
        $this->batteryMaxDischargeKw = $this->config['battery']['max_discharge_kw'];
        $this->importLimitKw = $this->config['energy']['electric']['import']['limit_kw'];
        $this->exportLimitKw = $this->config['energy']['electric']['export']['limit_kw'];
        if (self::DEBUG) {
            $this->batteryDepthOfDischargePercent = self::DEBUG_DEPTH_OF_DISCHARGE_PERCENT;
            $this->batteryCapacityKwh = self::DEBUG_BATTERY_RAW_CAPACITY_KWH;
            $this->batteryEnergyInitialKwh = $this->batteryCapacityKwh * $this->batteryDepthOfDischargePercent * self::DEBUG_BATTERY_LEVEL_PERCENT / 10000.0;
        } else {
            $this->batteryDepthOfDischargePercent = $this->config['battery']['permitted_depth_of_discharge_percent'];
            $this->batteryCapacityKwh = $this->config['battery']['initial_raw_capacity_kwh'];
        }
        $loadImportExports = $this->loadImportExport();
        $this->load_kws = $loadImportExports['load_kws'];
        $this->import_gbp_per_kws = $loadImportExports['import_gbp_per_kwhs'];
        $this->export_gbp_per_kws = $loadImportExports['export_gbp_per_kwhs'];
        $this->import_gbp_per_day = $loadImportExports['import_gbp_per_day'];
        $this->export_gbp_per_day = $loadImportExports['export_gbp_per_day'];
        $this->batteryEnergyInitialKwh = (new GivEnergy())->battery($this->db_slots)['effective_stored_kwh']; // get battery state of charge and extrapolate to beginning of slots
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
        $command = $this->command(null);
        $costs = [];
        $costs['raw'] = $this->costCLI($command, $this->grid_kws); // calculate pre-optimised cost using load with CLI command
        if (self::DEBUG) {
            echo 'Php    raw cost: ' . $costs['raw']['cost'] . ' GBP' . PHP_EOL;
        }
        $output = shell_exec($command);                           // execute Python command and capture output
        $result = json_decode($output, true);           // decode JSON output from Python
        if (!($result['success'] ?? false)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Convergence failure');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
        $optimumGridKws = $result['optimumGridKws'];
        $command = $this->command($optimumGridKws);   // made CLI with grid solution
        $costs['optimised'] = $this->costCLI($command, $optimumGridKws); // calculate optimised cost elements using CLI command
        if (self::DEBUG) {
            echo 'Python optimised cost: ' . $result['energyCost'] . ' GBP' . PHP_EOL;
            echo 'Php    optimised cost: ' . $costs['optimised']['cost'] . ' GBP' . PHP_EOL;
            echo 'CLI: ' . $command . PHP_EOL;
        }
        $this->insertOptimumGridInverterKw($optimumGridKws); // insert for each slot: grid and battery discharge energies (kWh)
        $this->insertSlotNextDayCostEstimates($costs, $slot_command = $this->slotCommand());
        return $slot_command;
    }

    private function command($optimumGridKws): string
    {
        //
        // make CLI command string
        //
        $command = self::PYTHON_SCRIPT_COMMAND . ' ';
        $command .= $this->argSubstring($this->batteryCapacityKwh);
        $command .= $this->argSubstring($this->batteryDepthOfDischargePercent);
        $command .= $this->argSubstring($this->batteryOneWayStorageEfficiency);
        $command .= $this->argSubstring($this->batteryWearCostGbpPerKwh);
        $command .= $this->argSubstring($this->batteryWearRatio);
        $command .= $this->argSubstring($this->batteryOutOfSpecCostMultiplier);
        $command .= $this->argSubstring($this->batteryMaxChargeKw);
        $command .= $this->argSubstring($this->batteryMaxDischargeKw);
        $command .= $this->argSubstring($this->importLimitKw);
        $command .= $this->argSubstring($this->exportLimitKw);
        $command .= $this->argSubstring($this->slotDurationHour);
        $command .= $this->argSubstring($this->batteryEnergyInitialKwh);
        $command .= $this->argSubstring($this->number_slots);
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $command .= $this->argSubstring($this->load_kws[$slot_count]);
            $command .= $this->argSubstring($this->import_gbp_per_kws[$slot_count]);
            $command .= $this->argSubstring($this->export_gbp_per_kws[$slot_count]);
        }
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {   // equate grid power to load power (i.e. zero battery power) as first guess
            if (isset($optimumGridKws[$slot_count])) {
                $grid_kw = $optimumGridKws[$slot_count];                            // use grid values if provided
            } elseif (Root::DEBUG && ($first_guess_kw = self::DEBUG_FIRST_GUESS_KW[$slot_count] ?? false)) {
                $grid_kw = $first_guess_kw;                                         // if no grid array element, use DEBUG array element if exists
            } else {
                $grid_kw = $this->load_kws[$slot_count];                            // by default: equate grid power to load power
            }
            $this->grid_kws[$slot_count] = $grid_kw;
            $command .= $this->argSubstring($grid_kw);
        }
        return $command;
    }

    private function costCLI($command, $grid_kws): array
    {
        //
        // calculates cost using SciPy command line arguments and $grid_kw solution
        //
        $this->load_kws = [];
        $this->import_gbp_per_kws = [];
        $this->export_gbp_per_kws[] = [];
        $this->string = $command;
        $this->strip();
        $this->strip(); // removes PYTHON_SCRIPT_COMMAND
        $this->batteryCapacityKwh = (float)$this->strip();
        $this->batteryDepthOfDischargePercent = (float)$this->strip();
        $this->batteryOneWayStorageEfficiency = (float)$this->strip();
        $this->batteryWearCostGbpPerKwh = (float)$this->strip();
        $this->batteryWearRatio = (float)$this->strip();
        $this->batteryOutOfSpecCostMultiplier = (float)$this->strip();
        $this->batteryMaxChargeKw = (float)$this->strip();
        $this->batteryMaxDischargeKw = (float)$this->strip();
        $this->importLimitKw = (float)$this->strip();
        $this->exportLimitKw = (float)$this->strip();
        $this->slotDurationHour = (float)$this->strip();
        $this->batteryEnergyInitialKwh = (float)$this->strip();
        $this->number_slots = (int)$this->strip();
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $this->load_kws[] = (float)$this->strip();
            $this->import_gbp_per_kws[] = (float)$this->strip();
            $this->export_gbp_per_kws[] = (float)$this->strip();
        }
        return $this->dayCosts($grid_kws);
    }

    private function dayCosts($grid_kws): array
    {                     // calculate cost components
        $cost_energy_average_per_kwh_acc = 0.0;                       // accumulator for calculating average energy cost
        $battery_level_kwh = $this->batteryEnergyInitialKwh;      // initial battery level
        $battery_level_mid_kwh = $this->batteryCapacityKwh / 2.0;     // midpoint battery level
        $battery_level_max_kwh = (100.0 + $this->batteryDepthOfDischargePercent) * $this->batteryCapacityKwh / 200.0; // max battery operating level
        $cost_min_per_kwh = 2.0 * $this->batteryWearCostGbpPerKwh / (1.0 + $this->batteryWearRatio);             // minimum wear cost at midpoint level
        $cost_grid_import = 0.0;
        $cost_grid_export = 0.0;
        $cost_wear = 0.0;
        $cost_out_of_spec = 0.0;
        $import_kwh = 0.0;
        $export_kwh = 0.0;
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
        $cost_level_change = ($this->batteryEnergyInitialKwh - $battery_level_kwh) * $cost_energy_average_per_kwh_acc / ((float)$this->number_slots);
        $cost = $cost_grid_import + $cost_grid_export + $cost_wear + $cost_out_of_spec + $cost_level_change;
        return ['cost' => $cost,
            'cost_import' => $cost_grid_import,
            'cost_export' => $cost_grid_export,
            'cost_wear' => $cost_wear,
            'import_kwh' => $import_kwh,
            'export_kwh' => $export_kwh];
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
        if (Root::DEBUG) {
            $battery_kwh = self::DEBUG_BATTERY_LEVEL_PERCENT * self::DEBUG_BATTERY_RAW_CAPACITY_KWH / 100.0;
        } else {
            $battery_kwh = $this->batteryEnergyInitialKwh;
        }
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
    private function slotCommand(): array
    {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT    `id`,
                          `start` AS `start_datetime`,  
                          DATE_FORMAT(`start`, \'%H:%i\') AS `start`,
                          DATE_FORMAT(`stop`, \'%H:%i\') AS `stop`,
                          `grid_kw`,
                          `battery_charge_kw`,
                          `battery_level_kwh`
                    FROM  `slots`
                    WHERE `tariff_combination` = ? AND
                          `slot` = 0';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $start_datetime, $start, $stop, $grid_kw, $battery_charge_kw, $target_level_kwh) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $grid_w = (int)(1000.0 * $grid_kw);
        $dod_margin = (100.0 - $this->batteryDepthOfDischargePercent) / 2.0;
        $target_level_percent_min = $dod_margin;
        $target_level_percent_max = 100.0 - $dod_margin;
        if ($battery_charge_kw > 0.0) {  // CHARGE
            $charge_power_w = (int)round(1000.0 * min($battery_charge_kw, $this->batteryMaxChargeKw));
        } else {                           // DISCHARGE
            $charge_power_w = (int)round(1000.0 * max($battery_charge_kw, -$this->batteryMaxDischargeKw));
        }
        if (abs($grid_w) < self::THRESHOLD_POWER_W) {                               // ECO if no appreciable import/export
            $direction = 'ECO';
            $abs_charge_power_w = null;
            $target_level_percent = null;
            $message = ' between ' . $start . ' and ' . $stop;
        } elseif (abs($charge_power_w) > self::THRESHOLD_POWER_W) {                   // CHARGE, DISCHARGE when above threshold charge power
            $direction = $charge_power_w > 0 ? 'CHARGE' : 'DISCHARGE';
            $abs_charge_power_w = abs($charge_power_w);
            $target_level_percent = (int)round(min(max(round(100.0 * $target_level_kwh / $this->batteryCapacityKwh), $target_level_percent_min), $target_level_percent_max));
            $message = '@' . round($abs_charge_power_w) . 'W to ' . $target_level_percent . '% between ' . $start . ' and ' . $stop;
        } else {                                                                       // otherwise IDLE
            $direction = 'IDLE';
            $abs_charge_power_w = null;
            $target_level_percent = $target_level_percent_min;
            $message = '';
        }
        return ['id' => $id,
            'start_datetime' => $start_datetime,
            'start' => $start,
            'stop' => $stop,
            'direction' => $direction,
            'abs_charge_power_w' => $abs_charge_power_w,
            'target_level_percent' => $target_level_percent,
            'message' => $direction . $message];
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