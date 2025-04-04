<?php

namespace Src;

use Exception;

class EnergyCost extends Root
{
    const bool      DEBUG_MINIMISER         = false;

    const string    JSON_PROBLEM            = '/var/www/html/energy/test/problem.json',
                    JSON_PROBLEM_DEBUG      = '/var/www/html/energy/test/problem_debug.json',
                    OPTIMISATION_LOG        = '/var/www/html/energy/test/optimisation.log',
                    PYTHON_SCRIPT_COMMAND   = 'python3 /var/www/html/energy/src/optimize.py';

    const array     HOURLY_WEIGHTED_PARAMETER_NAMES = [
                                                        'load_house_kws',
                                                        'import_gbp_per_kwhs',
                                                        'export_gbp_per_kwhs'
                                                      ];

    // setup parameters
    public    float $batteryCapacityKwh,
                    $batteryOneWayEfficiency,
                    $batteryWearEnergyCostAverageGbpPerKwh,
                    $batteryWearEnergyConstantCoefficient,
                    $batteryWearEnergyExponentialCoefficient,
                    $batteryWearEnergyActivationKwh,
                    $batteryWearPowerCostAverageGbpPerKwh,
                    $batteryWearPowerConstantCoefficient,
                    $batteryWearPowerExponentialCoefficient,
                    $batteryWearPowerActivationKw,
                    $gridWearPowerCostAverageGbpPerKwh,
                    $gridWearPowerConstantCoefficient,
                    $gridWearPowerExponentialCoefficient,
                    $gridWearPowerActivationKw,
                    $powerActivationKw,
                    $batteryMaxChargeKw,
                    $batteryMaxDischargeKw,
                    $slotDurationHour,
                    $batteryEnergyInitialKwh,
                    $importLimitKw,
                    $exportLimitKw,
                    $batteryWearEnergyNormalisationCoefficient,
                    $batteryWearPowerNormalisationCoefficient,
                    $gridWearPowerNormalisationCoefficient;

    private array   $problem,
                    $load_house_kws,        // house load, excluding EV
                    $solar_gross_kws,       // gross solar generation, excludes dynamic grid power clipping
                    $total_load_kws,
                    $tariff_combination,
                    $costs;

    public array   $slotCommands;

    public string $string;
    private int $number_slots;
    private mixed $db_slots;

    /**
     * @throws Exception
     */
    public function __construct($batteryLevelInitialKwh, $db_slots)
    {
        parent::__construct();
        if (!is_null($batteryLevelInitialKwh) && !is_null($db_slots)) {             // make json instantiate
            $this->slotDurationHour     = (float)(DbSlots::SLOT_DURATION_MIN / 60);
            $this->number_slots         = 24 * 60 / DbSlots::SLOT_DURATION_MIN;
            if (!self::DEBUG_MINIMISER) {
                $this->batteryEnergyInitialKwh  = $batteryLevelInitialKwh;
                $this->db_slots                 = $db_slots;
                $this->tariff_combination       = $this->db_slots->tariff_combination;
                $loadImportExports              = $this->loadImportExport();
            }
            else {
                $loadImportExports      = [
                                              'load_house_kws'        => [],
                                              'import_gbp_per_kwhs'   => [],
                                              'export_gbp_per_kwhs'   => [],
                                              'import_gbp_per_day'    => [],
                                              'export_gbp_per_day'    => []
                ];
            }
            $this->problem              = [
                                            'batteryCapacityKwh'                        => $this->config['battery']['initial_raw_capacity_kwh'],
                                            'batteryOneWayEfficiency'                   => sqrt(($this->config['battery']['round_trip_efficiency_percent'] ?? 100.0)/100.0),
                                            'batteryWearEnergyCostAverageGbpPerKwh'     => $this->config['battery']['wear']['energy']['cost_average_gbp_per_kwh'],
                                            'batteryWearEnergyConstantCoefficient'      => $this->config['battery']['wear']['energy']['constant_coefficient'],
                                            'batteryWearEnergyExponentialCoefficient'   => $this->config['battery']['wear']['energy']['exponential_coefficient'],
                                            'batteryWearEnergyActivationKwh'            => $this->config['battery']['wear']['energy']['activation_kwh'],
                                            'batteryWearPowerCostAverageGbpPerKwh'      => $this->config['battery']['wear']['power'] ['cost_average_gbp_per_kwh'],
                                            'batteryWearPowerConstantCoefficient'       => $this->config['battery']['wear']['power'] ['constant_coefficient'],
                                            'batteryWearPowerExponentialCoefficient'    => $this->config['battery']['wear']['power'] ['exponential_coefficient'],
                                            'batteryWearPowerActivationKw'              => $this->config['battery']['wear']['power'] ['activation_kw'],
                                            'batteryMaxChargeKw'                        => $this->config['battery']['max_charge_kw'],
                                            'batteryMaxDischargeKw'                     => $this->config['battery']['max_discharge_kw'],
                                            'importLimitKw'                             => $this->config['energy']['grid']['import']['limit_kw'],
                                            'exportLimitKw'                             => $this->config['energy']['grid']['export']['limit_kw'],
                                            'gridWearPowerCostAverageGbpPerKwh'         => $this->config['energy']['grid']['wear']['power']['cost_average_gbp_per_kwh'],
                                            'gridWearPowerConstantCoefficient'          => $this->config['energy']['grid']['wear']['power']['constant_coefficient'],
                                            'gridWearPowerExponentialCoefficient'       => $this->config['energy']['grid']['wear']['power']['exponential_coefficient'],
                                            'gridWearPowerActivationKw'                 => $this->config['energy']['grid']['wear']['power']['activation_kw'],
                                            'batteryEnergyInitialKwh'                   => $batteryLevelInitialKwh,
                                            'slotDurationHour'                          => $this->slotDurationHour,
                                            'number_slots'                              => $this->number_slots,
                                            'import_gbp_per_day'                        => $loadImportExports['import_gbp_per_day'],
                                            'export_gbp_per_day'                        => $loadImportExports['export_gbp_per_day'],
                                            'import_gbp_per_kwhs'                       => $loadImportExports['import_gbp_per_kwhs'],
                                            'export_gbp_per_kwhs'                       => $loadImportExports['export_gbp_per_kwhs'],
                                            'load_house_kws'                            => $loadImportExports['load_house_kws'],
                                          ];
            if (!($json_problem = json_encode($this->problem, JSON_PRETTY_PRINT)) ||
                !file_put_contents(self::JSON_PROBLEM, $json_problem)) {
                $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write json problem parameters');
                $this->logDb('MESSAGE', $message, null, 'FATAL');
                throw new Exception($message);
            }
        }
        else { // instantiate from config
            $this->batteryCapacityKwh                       = (float) $this->config['battery']['initial_raw_capacity_kwh'];
            $this->batteryOneWayEfficiency                  = sqrt(($this->config['battery']['round_trip_efficiency_percent'] ?? 100.0)/100.0);

            $this->batteryWearEnergyCostAverageGbpPerKwh    = (float) $this->config['battery']['wear']['energy']['cost_average_gbp_per_kwh'];
            $this->batteryWearEnergyConstantCoefficient     = (float) $this->config['battery']['wear']['energy']['constant_coefficient'];
            $this->batteryWearEnergyExponentialCoefficient  = (float) $this->config['battery']['wear']['energy']['exponential_coefficient'];
            $this->batteryWearEnergyActivationKwh           = (float) $this->config['battery']['wear']['energy']['activation_kwh'];

            $this->batteryWearPowerCostAverageGbpPerKwh     = (float) $this->config['battery']['wear']['power']['cost_average_gbp_per_kwh'];
            $this->batteryWearPowerConstantCoefficient      = (float) $this->config['battery']['wear']['power']['constant_coefficient'];
            $this->batteryWearPowerExponentialCoefficient   = (float) $this->config['battery']['wear']['power']['exponential_coefficient'];
            $this->batteryWearPowerActivationKw             = (float) $this->config['battery']['wear']['power']['activation_kw'];

            $this->batteryWearPowerExponentialCoefficient   = (float) $this->config['battery']['wear']['power']['exponential_coefficient'];
            $this->powerActivationKw                        = (float) $this->config['battery']['wear']['power']['activation_kw'];

            $this->batteryMaxChargeKw                       = (float) $this->config['battery']['max_charge_kw'];
            $this->batteryMaxDischargeKw                    = (float) $this->config['battery']['max_discharge_kw'];

            $this->importLimitKw                            = (float) $this->config['energy']['grid']['import']['limit_kw'];
            $this->exportLimitKw                            = (float) $this->config['energy']['grid']['export']['limit_kw'];

            $this->gridWearPowerCostAverageGbpPerKwh        = (float) $this->config['energy']['grid']['wear']['power']['cost_average_gbp_per_kwh'];
            $this->gridWearPowerConstantCoefficient         = (float) $this->config['energy']['grid']['wear']['power']['constant_coefficient'];
            $this->gridWearPowerExponentialCoefficient      = (float) $this->config['energy']['grid']['wear']['power']['exponential_coefficient'];
            $this->gridWearPowerActivationKw                = (float) $this->config['energy']['grid']['wear']['power']['activation_kw'];
            $this->batteryEnergyInitialKwh                  = (float) $batteryLevelInitialKwh;
        }
    }

    /**
     * @throws Exception
     */
    public function minimise(): array // returns optimum battery charge level for next slot
    {
        //
        // see https://scipy-lectures.org/advanced/mathematical_optimization/#knowing-your-problem
        //
        // convex, non-smooth, exact cost
        //
        if (!self::DEBUG_MINIMISER) {
            (new Root())->LogDb('OPTIMISING', $this->tariff_combination['name'],  null, 'NOTICE');
            $this->problem         = json_decode(file_get_contents(self::JSON_PROBLEM), true);
            $slot_kws              = $this->slot_kws();                           // get house load from db (excludes EV)
            $this->load_house_kws  = $slot_kws['load_house_kws'];                 // house load (excludes EV)
            $this->solar_gross_kws = $slot_kws['solar_kws'];                      // gross solar forecast (excludes grid clipping)
        }
        else { // use debug JSON and make slot arrays as necessary
           $this->problem         = $this->makeSlotsArrays(json_decode(file_get_contents(self::JSON_PROBLEM_DEBUG), true));
           $this->load_house_kws  = $this->problem['load_house_kws'];            // get total house load from problem
           $this->solar_gross_kws = $this->problem['solar_gross_kws'];           // get solar forecast (excludes grid clipping) from problem
        }
        $this->costs = [];
        $grid_kws = [];
        foreach ($this->load_house_kws as $slot => $load_house_kw) {              // match pre-optimised first guess to total load
            $load_house_kw   = $this->load_house_kws [$slot];
            $solar_gross_kw  = $this->solar_gross_kws[$slot];
            $grid_kws[$slot] = $solar_gross_kw - $load_house_kw;
        }
        $this->costs['raw'] = $this->costCLI($command = $this->command(), $grid_kws);
        $output = shell_exec($command);                                           // execute Python command and capture output
        $result = json_decode($output, true);                           // decode JSON output from Python
        $text   = $command . PHP_EOL . $output . PHP_EOL;
        if (!($result['success'] ?? false)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Convergence failure');
            $this->logDb('MESSAGE', $message, $text, 'FATAL');
            throw new Exception($message);
        }
        if (!$command ||
            !file_put_contents(self::OPTIMISATION_LOG, $command . PHP_EOL . 'Solution >>>' . PHP_EOL . $output)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write log');
            $this->logDb('MESSAGE', $message, null, 'FATAL');
            throw new Exception($message);
        }
        $this->optimisation_result($result);                                       // save optimiser performance parameters

        // calculate optimised cost elements using CLI command
        $this->costs['optimised'] = $this->costCLI($command, $optimumGridKws = $result['optimumGridKws']);
        $standing_costs_gbp_per_day = $this->problem['import_gbp_per_day'] + $this->problem['export_gbp_per_day'];
        echo 'Php    raw cost:            ' . round($this->costs['raw']['cost']            +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Python optimised cost:      ' . round($result['energyCost']                  +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Php    optimised cost:      ' . round($this->costs['optimised']['cost']      +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Php    optimised grid_cost: ' . round($this->costs['optimised']['cost_grid'] +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        if (self::DEBUG_MINIMISER) {
            echo PHP_EOL;
            echo 'grid_kw        raw,   optimised' . PHP_EOL;
            foreach ($this->total_load_kws as $k => $v) {
                echo sprintf("%5.1f", (float)$k/2.0) . ':             ' . round($this->total_load_kws[$k], 3) . ', ' . round($optimumGridKws[$k], 3) . PHP_EOL;
            }
            return [];
        }
        else {
            $this->insertOptimumGridInverterKw($optimumGridKws);                      // insert for each slot: grid and battery discharge energies (kWh)
            $slot_solution = $this->slotSolution();                                   // make slot solution
            $this->insertSlotNextDayCostEstimates($slot_solution['id']);
            return $slot_solution;
        }
    }

    /**
     * @throws Exception
     */
    private function slotSolution(): array {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT          `id`,
                                `start`,
                                `stop`,
                                `grid_kw`,
                                `battery_charge_kw`,
                                `battery_level_kwh`,
                                `import_gbp_per_kwh`,
                                `export_gbp_per_kwh`
                    FROM        `slots`
                    WHERE       `slot` = 0 AND
                                `tariff_combination` = ? AND
                                NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $start, $stop, $grid_kw, $battery_charge_kw, $battery_level_kwh, $import_gbp_per_kwh, $export_gbp_per_kwh) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $this->slotCommands = [];
        if ($battery_charge_kw > 0.0) {                                 // CHARGE
            $charge_power_w = (int) round(1000.0 * min($battery_charge_kw, $this->batteryMaxChargeKw));
        } else {                                                        // DISCHARGE
            $charge_power_w = (int) round(1000.0 * max($battery_charge_kw, -$this->batteryMaxDischargeKw));
        }
        $mode = $charge_power_w > 0 ? 'CHARGE' : 'DISCHARGE';
        $abs_charge_power_w   = abs($charge_power_w);
        $target_level_percent = min(100, max(0, (int) round(100.0 * ($battery_level_kwh + $battery_charge_kw * $this->slotDurationHour) / $this->batteryCapacityKwh)));
        $message              = $mode . ' @' . $abs_charge_power_w . 'W to ' . $target_level_percent .  '%';
        return [
            'id'                    => $id,
            'start'                 => $start,
            'stop'                  => $stop,
            'mode'                  => $mode,
            'abs_charge_power_w'    => $abs_charge_power_w,
            'target_level_percent'  => $target_level_percent,
            'import_gbp_per_kwh'    => $import_gbp_per_kwh,
            'export_gbp_per_kwh'    => $export_gbp_per_kwh,
            'message'               => $message
        ];
    }

    /**
     * @throws Exception
     */
    private function optimisation_result($result): void {
        $sql = 'UPDATE  `tariff_combinations` 
                  SET   `result` = ?
                  WHERE `id`     = ?';
        $result = 'ended=' . date('Y-m-d H:i:s') . ', elapsed=' . round($result['elapsed_s'], 1) . 's, evaluations=' . $result['evaluations'];
        $tariff_combination_id = $this->tariff_combination['id'];
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('si', $result, $tariff_combination_id) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
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
        $command .= $this->parameter_name_value('batteryOneWayEfficiency');
        $command .= $this->parameter_name_value('batteryWearEnergyCostAverageGbpPerKwh');
        $command .= $this->parameter_name_value('batteryWearEnergyConstantCoefficient');
        $command .= $this->parameter_name_value('batteryWearEnergyExponentialCoefficient');
        $command .= $this->parameter_name_value('batteryWearEnergyActivationKwh');
        $command .= $this->parameter_name_value('batteryWearPowerCostAverageGbpPerKwh');
        $command .= $this->parameter_name_value('batteryWearPowerConstantCoefficient');
        $command .= $this->parameter_name_value('batteryWearPowerExponentialCoefficient');
        $command .= $this->parameter_name_value('batteryWearPowerActivationKw');
        $command .= $this->parameter_name_value('batteryMaxChargeKw');
        $command .= $this->parameter_name_value('batteryMaxDischargeKw');
        $command .= $this->parameter_name_value('importLimitKw');
        $command .= $this->parameter_name_value('exportLimitKw');
        $command .= $this->parameter_name_value('gridWearPowerCostAverageGbpPerKwh');
        $command .= $this->parameter_name_value('gridWearPowerConstantCoefficient');
        $command .= $this->parameter_name_value('gridWearPowerExponentialCoefficient');
        $command .= $this->parameter_name_value('gridWearPowerActivationKw');
        $command .= $this->parameter_name_value('batteryEnergyInitialKwh');
        $command .= $this->parameter_name_value('slotDurationHour');
        $command .= $this->parameter_name_value('number_slots');

        $number_slots = $this->problem['number_slots'];

        $command .= 'import_gbp_per_kwhs= ';
        $import_gbp_per_kwhs = $this->problem['import_gbp_per_kwhs'];
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $command .= $import_gbp_per_kwhs[$slot] . ' ';
        }
        $command .= 'export_gbp_per_kwhs= ';
        $export_gbp_per_kwhs = $this->problem['export_gbp_per_kwhs'];
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $command .= $export_gbp_per_kwhs[$slot] . ' ';
        }
        $command .= 'load_house_kws= ';
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $command .= $this->load_house_kws[$slot] . ' ';
        }
        $command .= 'solar_gross_kws= ';
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $command .= $this->solar_gross_kws[$slot] . ' ';
        }
        // make first guess
        $grid_first_guesses_kws = $this->grid_first_guesses_kws($number_slots);
        $command .= 'grid_first_guesses_kws= ';
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $command .= $grid_first_guesses_kws[$slot] . ' ';
        }
        // use boundary grid values
        $grid_boundary_pairs_kws = $this->grid_boundary_pairs_kws($number_slots);
        $command .= 'grid_boundary_pairs_kws= ';
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $grid_boundary_pair_kw = $grid_boundary_pairs_kws[$slot];
            $command .= $grid_boundary_pair_kw['lower'] . ' ';
            $command .= $grid_boundary_pair_kw['upper'] . ' ';
        }
        return $command;
    }

    private function grid_first_guesses_kws($number_slots): array {
        $import_limit_kw = $this->problem['importLimitKw'];
        $export_limit_kw = $this->problem['exportLimitKw'];
        $first_guess_grid_kws = [];
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $load_house_kw               = $this->load_house_kws[$slot];
            $solar_gross_kw              = $this->solar_gross_kws[$slot];
            $grid_gross_kw               = $solar_gross_kw - $load_house_kw;
            $first_guess_grid_kw         = max(-$import_limit_kw, min($grid_gross_kw, $export_limit_kw));
            $first_guess_grid_kws[$slot] = $first_guess_grid_kw;
        }
        return $first_guess_grid_kws; // limit first guess to zero charge
    }

    private function grid_boundary_pairs_kws($number_slots): array {
        $import_limit_kw = $this->problem['importLimitKw'];
        $export_limit_kw = $this->problem['exportLimitKw'];
        for ($slot = 0; $slot < $number_slots; $slot++) {
            $grid_boundary_pairs_kws[] = [
                                            'lower' => -$import_limit_kw - $this->problem['gridWearPowerActivationKw'],
                                            'upper' =>  $export_limit_kw + $this->problem['gridWearPowerActivationKw']
                                         ];
        }
        return $grid_boundary_pairs_kws;
    }

    private function costCLI($command, $grid_kws): array
    {
        //
        // calculates cost using SciPy command line arguments and $grid_kw solution
        //
        // excludes standing costs
        //
        $this->string = $command;
        $this->strip();
        $this->strip(); // removes PYTHON_SCRIPT_COMMAND
        $this->strip();
        $this->batteryCapacityKwh                       = (float) $this->strip();
        $this->strip();
        $this->batteryOneWayEfficiency                  = (float) $this->strip();
        $this->strip();
        $this->batteryWearEnergyCostAverageGbpPerKwh    = (float) $this->strip();
        $this->strip();
        $this->batteryWearEnergyConstantCoefficient     = (float) $this->strip();
        $this->strip();
        $this->batteryWearEnergyExponentialCoefficient  = (float) $this->strip();
        $this->strip();
        $this->batteryWearEnergyActivationKwh           = (float) $this->strip();
        $this->strip();
        $this->batteryWearPowerCostAverageGbpPerKwh     = (float) $this->strip();
        $this->strip();
        $this->batteryWearPowerConstantCoefficient      = (float) $this->strip();
        $this->strip();
        $this->batteryWearPowerExponentialCoefficient   = (float) $this->strip();
        $this->strip();
        $this->batteryWearPowerActivationKw             = (float) $this->strip();
        $this->strip();
        $this->batteryMaxChargeKw                       = (float) $this->strip();
        $this->strip();
        $this->batteryMaxDischargeKw                    = (float) $this->strip();
        $this->strip();
        $this->importLimitKw                            = (float) $this->strip();
        $this->strip();
        $this->exportLimitKw                            = (float) $this->strip();
        $this->strip();
        $this->gridWearPowerCostAverageGbpPerKwh        = (float) $this->strip();
        $this->strip();
        $this->gridWearPowerConstantCoefficient         = (float) $this->strip();
        $this->strip();
        $this->gridWearPowerExponentialCoefficient      = (float) $this->strip();
        $this->strip();
        $this->gridWearPowerActivationKw                = (float) $this->strip();
        $this->strip();
        $this->batteryEnergyInitialKwh                  = (float) $this->strip();
        $this->strip();
        $this->slotDurationHour                         = (float) $this->strip();
        $this->strip();
        $this->number_slots                             = (int)   $this->strip();
        $this->strip();
        $import_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $import_gbp_per_kws[]                       = (float) $this->strip();
        }
        $this->strip();
        $export_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $export_gbp_per_kws[]                       = (float) $this->strip();
        }
        $this->strip();
        $load_house_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $load_house_kws[]                           = (float) $this->strip();
        }
        $this->strip();
        $solar_gross_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $solar_gross_kws[]                          = (float) $this->strip();
        }
        return $this->dayCostGbp($grid_kws, $import_gbp_per_kws, $export_gbp_per_kws, $load_house_kws, $solar_gross_kws);
    }

    private function dayCostGbp($grid_kws, $import_gbp_per_kws, $export_gbp_per_kws, $load_house_kws, $solar_gross_kws): array {
        /*
         * calculate cost components: does not include standing costs
         */
        $cost_energy_average_per_kwh_acc = 0.0;                       // accumulator for calculating average energy cost
        $battery_level_kwh = $this->batteryEnergyInitialKwh;          // battery level at beginning of day
        $this->makeNormalisationCoefficients();
        $cost_grid_import       = 0.0;
        $cost_grid_export       = 0.0;
        $cost_grid_out_of_spec  = 0.0;
        $cost_energy_wear       = 0.0;
        $cost_power_out_of_spec = 0.0;
        $import_kwh             = 0.0;
        $export_kwh             = 0.0;
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $grid_power_slot_kw    = $grid_kws[$slot_count];
            $load_house_kw         = $load_house_kws[$slot_count];
            $solar_gross_kw        = $solar_gross_kws[$slot_count];
            $tariff_import_per_kwh = $import_gbp_per_kws[$slot_count];
            $tariff_export_per_kwh = $export_gbp_per_kws[$slot_count];
            $total_load_kw         = $load_house_kw - $solar_gross_kw;
            $energy_grid_kwh       = $grid_power_slot_kw * $this->slotDurationHour;
            $total_load_kwh        = $total_load_kw * $this->slotDurationHour;

            // grid
            if ($energy_grid_kwh > 0.0) {
                $export_kwh       += $energy_grid_kwh;
                $cost_grid_export -= $tariff_export_per_kwh * $energy_grid_kwh;
            } else {
                $import_kwh       += -$energy_grid_kwh;
                $cost_grid_import -= $tariff_import_per_kwh * $energy_grid_kwh;
            }

            // battery
            $battery_charge_kwh = -$energy_grid_kwh - $total_load_kwh;
            $battery_charge_kw  = -$grid_power_slot_kw - $total_load_kw;
            if ($battery_charge_kwh > 0.0) {
               $battery_level_kwh += $battery_charge_kwh * $this->batteryOneWayEfficiency;
            }
            else {
               $battery_level_kwh += $battery_charge_kwh / $this->batteryOneWayEfficiency;
            }
            // operational and out of spec wear
            $cost_energy_wear               += $this->wearPerKwh(       $battery_level_kwh,
                                                                        0.0,
                                                                         $this->batteryCapacityKwh,
                                                                         $this->batteryWearEnergyCostAverageGbpPerKwh,
                                                                         $this->batteryWearEnergyConstantCoefficient,
                                                                         $this->batteryWearEnergyExponentialCoefficient,
                                                                         $this->batteryWearEnergyActivationKwh,
                                                                         $this->batteryWearEnergyNormalisationCoefficient)*abs($battery_charge_kwh);
            // battery charge/discharge power out of spec
            $cost_power_out_of_spec         += $this->wearPerKwh(       $battery_charge_kw,
                                                                       -$this->batteryMaxDischargeKw,
                                                                        $this->batteryMaxChargeKw,
                                                                        $this->batteryWearPowerCostAverageGbpPerKwh,
                                                                        $this->batteryWearPowerConstantCoefficient,
                                                                        $this->batteryWearPowerExponentialCoefficient,
                                                                        $this->batteryWearPowerActivationKw,
                                                                        $this->batteryWearPowerNormalisationCoefficient)*abs($battery_charge_kwh);
            // grid power out of spec
            $cost_grid_out_of_spec          += $this->wearPerKwh(       $grid_power_slot_kw,
                                                                       -$this->importLimitKw,
                                                                        $this->exportLimitKw,
                                                                        $this->batteryWearEnergyCostAverageGbpPerKwh,
                                                                        $this->gridWearPowerConstantCoefficient,
                                                                        $this->gridWearPowerExponentialCoefficient,
                                                                        $this->gridWearPowerActivationKw,
                                                                        $this->gridWearPowerNormalisationCoefficient)*abs($energy_grid_kwh);
            $cost_energy_average_per_kwh_acc += 0.5 * ($tariff_import_per_kwh + $tariff_export_per_kwh);    // accumulate average energy cost
        }
        $cost_energy_level_change = ($this->batteryEnergyInitialKwh - $battery_level_kwh) * $cost_energy_average_per_kwh_acc / ((float) $this->number_slots);
        $cost = $cost_grid_import + $cost_grid_export + $cost_grid_out_of_spec + $cost_energy_wear + $cost_power_out_of_spec + $cost_energy_level_change;
        return [
                    'cost'          => $cost,
                    'cost_grid'     => $cost_grid_import+$cost_grid_export,
                    'cost_import'   => $cost_grid_import,
                    'cost_export'   => $cost_grid_export,
                    'cost_wear'     => $cost_energy_wear,
                    'import_kwh'    => $import_kwh,
                    'export_kwh'    => $export_kwh
        ];
    }

    private function wearPerKwh($x, $x_min, $x_max, $wear_cost_average, $constant_coefficient, $exponential_coefficient, $activation, $normalisation_coefficient): float {
        $X  = ((($x - $x_min) / ($x_max - $x_min)) - 0.5);
        $X2 = $X * $X;
        $t1 = $constant_coefficient;
        $t2 = (1.0 - $constant_coefficient) * $X2;
        if ($X < 0.0) {
            $exponent = ($x_min - $x)/$activation;
        }
        else {
            $exponent = ($x - $x_max)/$activation;
        }
        $t3 = $exponential_coefficient*exp($exponent);
        $wear = $normalisation_coefficient*$wear_cost_average*($t1+$t2+$t3);
        return $wear;
    }

    public function wearGbpPerHour($grid_kw, $charge_kw, $battery_level_kwh, $duration_hour): array {
        $battery_charge_kwh = $charge_kw * $duration_hour;
        $battery_energy_per_kwh     = $this->wearPerKwh( $battery_level_kwh,
                                                        0.0,
                                                         $this->batteryCapacityKwh,
                                                         $this->batteryWearEnergyCostAverageGbpPerKwh,
                                                         $this->batteryWearEnergyConstantCoefficient,
                                                         $this->batteryWearEnergyExponentialCoefficient,
                                                         $this->batteryWearEnergyActivationKwh,
                                                         $this->batteryWearEnergyNormalisationCoefficient);
        $battery_power_per_kwh      = $this->wearPerKwh( $charge_kw,
                                                        -$this->batteryMaxDischargeKw,
                                                         $this->batteryMaxChargeKw,
                                                         $this->batteryWearPowerCostAverageGbpPerKwh,
                                                         $this->batteryWearPowerConstantCoefficient,
                                                         $this->batteryWearPowerExponentialCoefficient,
                                                         $this->batteryWearPowerActivationKw,
                                                         $this->batteryWearPowerNormalisationCoefficient);
        $grid_power_per_kwh         = $this->wearPerKwh( $grid_kw,
                                                        -$this->importLimitKw,
                                                         $this->exportLimitKw,
                                                         $this->gridWearPowerCostAverageGbpPerKwh,
                                                         $this->gridWearPowerConstantCoefficient,
                                                         $this->gridWearPowerExponentialCoefficient,
                                                         $this->gridWearPowerActivationKw,
                                                         $this->gridWearPowerNormalisationCoefficient);
        $battery_energy_per_hour = $battery_energy_per_kwh * abs($battery_charge_kwh)/$duration_hour;
        $battery_power_per_hour  = $battery_power_per_kwh  * abs($battery_charge_kwh)/$duration_hour;
        $grid_power_per_hour     = $grid_power_per_kwh     * abs($battery_charge_kwh)/$duration_hour;
        return ['battery_energy' => $battery_energy_per_hour,
                'battery_power'  => $battery_power_per_hour,
                'grid_power'     => $grid_power_per_hour];
    }

    public function makeNormalisationCoefficients(): void
    {
        $this->batteryWearEnergyNormalisationCoefficient    = $this->normalisationCoefficient(  $this->batteryWearEnergyConstantCoefficient,
                                                                                                $this->batteryWearEnergyExponentialCoefficient,
                                                                                                $this->batteryWearEnergyActivationKwh,
                                                                                                0.0,
                                                                                                $this->batteryCapacityKwh);
        $this->batteryWearPowerNormalisationCoefficient     = $this->normalisationCoefficient(  $this->batteryWearPowerConstantCoefficient,
                                                                                                $this->batteryWearPowerExponentialCoefficient,
                                                                                                $this->batteryWearPowerActivationKw,
                                                                                               -$this->batteryMaxDischargeKw,
                                                                                                $this->batteryMaxChargeKw);
        $this->gridWearPowerNormalisationCoefficient        = $this->normalisationCoefficient(  $this->gridWearPowerConstantCoefficient,
                                                                                                $this->gridWearPowerExponentialCoefficient,
                                                                                                $this->gridWearPowerActivationKw,
                                                                                               -$this->importLimitKw,
                                                                                                $this->exportLimitKw);
    }
    public function normalisationCoefficient($constant_coefficient, $exponential_coefficient, $activation, $x_min, $x_max): float {
        return 12.0/(1.0+(11.0*$constant_coefficient)+(24.0*$exponential_coefficient*$activation/($x_max - $x_min)));
    }

    /**
     * @throws Exception
     */
    private function loadImportExport(): array
    { // get slot load, import and export tariffs for each slot
        $sql = 'SELECT      `load_house_kw`,
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
            !$stmt->bind_result($load_house_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $load_house_kw = [];
        $import_gbp_per_kwh = [];
        $export_gbp_per_kwh = [];
        while ($stmt->fetch()) {
            $load_house_kws[]      = $load_house_kw;
            $import_gbp_per_kwhs[] = $import_gbp_per_kwh;
            $export_gbp_per_kwhs[] = $export_gbp_per_kwh;
        }
        return [
                'load_house_kws'        => $load_house_kws,
                'import_gbp_per_kwhs'   => $import_gbp_per_kwhs,
                'export_gbp_per_kwhs'   => $export_gbp_per_kwhs,
                'import_gbp_per_day'    => $import_gbp_per_day,
                'export_gbp_per_day'    => $export_gbp_per_day
        ];
    }

    private function parameter_name_value($parameter_name): string {  // make parameter substring
        return $parameter_name . '= ' . $this->problem[$parameter_name] . ' ';
    }

    /**
     * @throws Exception
     */
    private function insertOptimumGridInverterKw($optimum_grid_kws): void
    {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'UPDATE      `slots` 
                   SET      `grid_kw`            = ROUND(?, 3),
                            `battery_charge_kw`  = ROUND(?, 3),
                            `battery_level_kwh`  = ROUND(?, 3)
                   WHERE    `slot`               = ? AND
                            `tariff_combination` = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('dddii', $optimum_grid_kw, $battery_charge_kw, $battery_level_kwh, $slot, $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $battery_level_kwh = $this->batteryEnergyInitialKwh;
        foreach ($optimum_grid_kws as $slot => $optimum_grid_kw) {
            $battery_charge_kw   = -$optimum_grid_kw - $this->total_load_kws[$slot];
            $stmt->execute();
            $battery_level_kwh  += $battery_charge_kw * DbSlots::SLOT_DURATION_MIN / 60;
        }
        $this->mysqli->commit();
    }

    private function slot_kws(): array {
        /*
         * get house load
         */
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT      `load_house_kw`, `solar_gross_kw` 
                   FROM     `slots`
                   WHERE    `tariff_combination` = ? AND
                            NOT `final`
                   ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($load_house_kw, $solar_gross_kw) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $slot_kws        = [];
        $load_house_kws  = [];
        $solar_gross_kws = [];
        while ($stmt->fetch()) {
            $load_house_kws[] = $load_house_kw;
            $solar_gross_kws[]      = $solar_gross_kw;
        }
        $slot_kws['load_house_kws'] = $load_house_kws;
        $slot_kws['solar_kws']      = $solar_gross_kws;
        return $slot_kws;
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
    private function insertSlotNextDayCostEstimates($slot): void
    {
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
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
    }
}