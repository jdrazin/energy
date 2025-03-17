<?php

namespace Src;

use Exception;

class EnergyCost extends Root
{
    const bool      DEBUG_MINIMISER = false;
    const float     THRESHOLD_POWER_W = 100.0;

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
                    $wearCostAverageGbpPerKwh,
                    $wearConstantCoefficient,
                    $energyExponentialCoefficient,
                    $energyActivationKwh,
                    $powerExponentialCoefficient,
                    $powerActivationKw,
                    $batteryMaxChargeKw,
                    $batteryMaxDischargeKw,
                    $slotDurationHour,
                    $batteryEnergyInitialKwh,
                    $importLimitKw,
                    $exportLimitKw,
                    $energyNormalisationCoefficient,
                    $powerNormalisationCoefficient;

    private array   $problem,
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
                                            'batteryCapacityKwh'           => $this->config['battery']['initial_raw_capacity_kwh'],
                                            'batteryOneWayEfficiency'      => sqrt(($this->config['battery']['round_trip_efficiency_percent'] ?? 100.0)/100.0),
                                            'wearCostAverageGbpPerKwh'     => $this->config['battery']['wear']['cost_average_gbp_per_kwh'],
                                            'wearConstantCoefficient'      => $this->config['battery']['wear']['constant_coefficient'],
                                            'energyExponentialCoefficient' => $this->config['battery']['wear']['energy_exponential_coefficient'],
                                            'energyActivationKwh'          => $this->config['battery']['wear']['energy_activation_kwh'],
                                            'powerExponentialCoefficient'  => $this->config['battery']['wear']['power_exponential_coefficient'],
                                            'powerActivationKw'            => $this->config['battery']['wear']['power_activation_kw'],
                                            'batteryMaxChargeKw'           => $this->config['battery']['max_charge_kw'],
                                            'batteryMaxDischargeKw'        => $this->config['battery']['max_discharge_kw'],
                                            'importLimitKw'                => $this->config['energy']['electric']['import']['limit_kw'],
                                            'exportLimitKw'                => $this->config['energy']['electric']['export']['limit_kw'],
                                            'batteryEnergyInitialKwh'      => $batteryLevelInitialKwh,
                                            'slotDurationHour'             => $this->slotDurationHour,
                                            'number_slots'                 => $this->number_slots,
                                            'import_gbp_per_day'           => $loadImportExports['import_gbp_per_day'],
                                            'export_gbp_per_day'           => $loadImportExports['export_gbp_per_day'],
                                            'import_gbp_per_kwhs'          => $loadImportExports['import_gbp_per_kwhs'],
                                            'export_gbp_per_kwhs'          => $loadImportExports['export_gbp_per_kwhs'],
                                            'load_house_kws'               => $loadImportExports['load_house_kws'],
                                          ];
            if (!($json_problem = json_encode($this->problem, JSON_PRETTY_PRINT)) ||
                !file_put_contents(self::JSON_PROBLEM, $json_problem)) {
                $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write json problem parameters');
                $this->logDb('MESSAGE', $message, 'FATAL');
                throw new Exception($message);
            }
        }
        else { // instantiate from config
            $this->batteryCapacityKwh                       = (float) $this->config['battery']['initial_raw_capacity_kwh'];
            $this->batteryOneWayEfficiency                  = sqrt(($this->config['battery']['round_trip_efficiency_percent'] ?? 100.0)/100.0);
            $this->wearCostAverageGbpPerKwh                 = (float) $this->config['battery']['wear']['cost_average_gbp_per_kwh'];
            $this->wearConstantCoefficient                  = (float) $this->config['battery']['wear']['constant_coefficient'];
            $this->energyExponentialCoefficient             = (float) $this->config['battery']['wear']['energy_exponential_coefficient'];
            $this->energyActivationKwh                      = (float) $this->config['battery']['wear']['energy_activation_kwh'];
            $this->powerExponentialCoefficient              = (float) $this->config['battery']['wear']['power_exponential_coefficient'];
            $this->powerActivationKw                        = (float) $this->config['battery']['wear']['power_activation_kw'];
            $this->batteryMaxChargeKw                       = (float) $this->config['battery']['max_charge_kw'];
            $this->batteryMaxDischargeKw                    = (float) $this->config['battery']['max_discharge_kw'];
            $this->importLimitKw                            = (float) $this->config['energy']['electric']['import']['limit_kw'];
            $this->exportLimitKw                            = (float) $this->config['energy']['electric']['export']['limit_kw'];
            $this->batteryEnergyInitialKwh                  = (float) $batteryLevelInitialKwh;
        }
    }

    /**
     * @throws Exception
     */
    public function minimise(): ?array // returns optimum battery charge level for next slot
    {
        //
        // see https://scipy-lectures.org/advanced/mathematical_optimization/#knowing-your-problem
        //
        // convex, non-smooth, exact cost
        //
        (new Root())->LogDb('OPTIMISING', $this->tariff_combination['name'], 'NOTICE');
        if (self::DEBUG_MINIMISER) {  // use debug JSON and make slot arrays as necessary
           $this->problem           = $this->makeSlotsArrays(json_decode(file_get_contents(self::JSON_PROBLEM_DEBUG), true));
           $this->total_load_kws    = $this->problem['total_load_kws'];          // get total load from problem
        }
        else {
            $this->problem          = json_decode(file_get_contents(self::JSON_PROBLEM), true);
            $this->total_load_kws   = $this->total_load_kws();                   // get total load from db
        }
        $this->costs = [];
        $grid_kws = [];
        foreach ($this->total_load_kws as $slot => $total_load_kw) {              // match pre-optimised first guess to total load
            $grid_kws[$slot] = -$total_load_kw;
        }
        $this->costs['raw'] = $this->costCLI($command = $this->command(), $grid_kws);
        $output = shell_exec($command);                                           // execute Python command and capture output
        $result = json_decode($output, true);                           // decode JSON output from Python
        if (!($result['success'] ?? false)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Convergence failure');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
        if (!$command ||
            !file_put_contents(self::OPTIMISATION_LOG, $command . PHP_EOL . 'Solution >>>' . PHP_EOL . $output)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write log');
            $this->logDb('MESSAGE', $message, 'FATAL');
            throw new Exception($message);
        }
        $this->optimisation_result($result);                                       // save optimiser performance parameters

        // calculate optimised cost elements using CLI command
        $this->costs['optimised'] = $this->costCLI($command, $optimumGridKws = $result['optimumGridKws']);
        $standing_costs_gbp_per_day = $this->problem['import_gbp_per_day'] + $this->problem['export_gbp_per_day'];
        echo 'Php    raw cost:            '  . round($this->costs['raw']['cost']            +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Python optimised cost:      '  . round($result['energyCost']                  +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Php    optimised cost:      '  . round($this->costs['optimised']['cost']      +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        echo 'Php    optimised grid_cost: '  . round($this->costs['optimised']['cost_grid'] +$standing_costs_gbp_per_day,2) . ' GBP' . PHP_EOL;
        if (self::DEBUG_MINIMISER) {
            echo PHP_EOL;
            echo 'grid_kw        raw,   optimised' . PHP_EOL;
            foreach ($this->total_load_kws as $k => $v) {
                echo sprintf("%5.1f", (float)$k/2.0) . ':             ' . round($this->total_load_kws[$k], 3) . ', ' . round($optimumGridKws[$k], 3) . PHP_EOL;
            }
            return null;
        }
        else {
            $this->insertOptimumGridInverterKw($optimumGridKws);                      // insert for each slot: grid and battery discharge energies (kWh)
            $this->slotCommands();
            $this->insertSlotNextDayCostEstimates();
            return $this->slotCommands[0];
        }
    }

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
            $this->logDb('MESSAGE', $message, 'ERROR');
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
        $command .= $this->parameter_name_value('wearCostAverageGbpPerKwh');
        $command .= $this->parameter_name_value('wearConstantCoefficient');
        $command .= $this->parameter_name_value('energyExponentialCoefficient');
        $command .= $this->parameter_name_value('energyActivationKwh');
        $command .= $this->parameter_name_value('powerExponentialCoefficient');
        $command .= $this->parameter_name_value('powerActivationKw');
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
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= $this->total_load_kws[$slot_count] . ' ';
        }
        // use total load power for first guess
        $command .= 'FIRST_GUESS_grid_kws= ';
        for ($slot_count = 0; $slot_count < $number_slots; $slot_count++) {
            $command .= -$this->total_load_kws[$slot_count] . ' ';
        }
        return $command;
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
        $this->wearCostAverageGbpPerKwh                 = (float) $this->strip();
        $this->strip();
        $this->wearConstantCoefficient                  = (float) $this->strip();
        $this->strip();
        $this->energyExponentialCoefficient             = (float) $this->strip();
        $this->strip();
        $this->energyActivationKwh                      = (float) $this->strip();
        $this->strip();
        $this->powerExponentialCoefficient              = (float) $this->strip();
        $this->strip();
        $this->powerActivationKw                        = (float) $this->strip();
        $this->strip();
        $this->batteryMaxChargeKw                       = (float) $this->strip();
        $this->strip();
        $this->batteryMaxDischargeKw                    = (float) $this->strip();
        $this->strip();
        $this->importLimitKw                            = (float) $this->strip();
        $this->strip();
        $this->exportLimitKw                            = (float) $this->strip();
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
        $total_load_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $total_load_kws[]                           = (float) $this->strip();
        }
        return $this->dayCostGbp($grid_kws, $import_gbp_per_kws, $export_gbp_per_kws, $total_load_kws);
    }

    private function dayCostGbp($grid_kws, $import_gbp_per_kws, $export_gbp_per_kws, $total_load_kws): array {
        /*
         * calculate cost components: does not include standing costs
         */
        $cost_energy_average_per_kwh_acc = 0.0;                       // accumulator for calculating average energy cost
        $battery_level_kwh = $this->batteryEnergyInitialKwh;          // battery level at beginning of day
        $this->makeEnergyNormalisationCoefficient();
        $this->makePowerNormalisationCoefficient();
        $cost_grid_import                     = 0.0;
        $cost_grid_export                     = 0.0;
        $cost_grid_out_of_spec                = 0.0;
        $cost_energy_wear         = 0.0;
        $cost_power_out_of_spec               = 0.0;
        $import_kwh                           = 0.0;
        $export_kwh                           = 0.0;
        for ($slot_count = 0; $slot_count < $this->number_slots; $slot_count++) {
            $grid_power_slot_kw = $grid_kws[$slot_count];

            $total_load_kw         = $total_load_kws[$slot_count];
            $tariff_import_per_kwh = $import_gbp_per_kws[$slot_count];
            $tariff_export_per_kwh = $export_gbp_per_kws[$slot_count];
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
                                                                         $this->wearCostAverageGbpPerKwh,
                                                                         $this->wearConstantCoefficient,
                                                                         $this->energyExponentialCoefficient,
                                                                         $this->energyActivationKwh,
                                                                         $this->energyNormalisationCoefficient)*abs($battery_charge_kwh);

            // battery charge/discharge power out of spec
            $cost_power_out_of_spec         += $this->wearPerKwh(       $battery_charge_kw,
                                                                        -$this->batteryMaxDischargeKw,
                                                                        $this->batteryMaxChargeKw,
                                                                        $this->wearCostAverageGbpPerKwh,
                                                                        0.0,
                                                                        $this->powerExponentialCoefficient,
                                                                        $this->powerActivationKw,
                                                                        $this->powerNormalisationCoefficient)*abs($battery_charge_kwh);

            // grid power out of spec
            $cost_grid_out_of_spec          += $this->wearPerKwh(       $grid_power_slot_kw,
                                                                        -$this->importLimitKw,
                                                                        $this->exportLimitKw,
                                                                        $this->wearCostAverageGbpPerKwh,
                                                                        0.0,
                                                                        $this->powerExponentialCoefficient,
                                                                        $this->powerActivationKw,
                                                                        $this->powerNormalisationCoefficient)*abs($energy_grid_kwh);

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
        $energy_grid_kwh    = $grid_kw * $duration_hour;
        $battery_charge_kwh = $charge_kw * $duration_hour;
        return [
                'battery_energy' => $this->wearPerKwh(      $battery_level_kwh,
                                                            0.0,
                                                            $this->batteryCapacityKwh,
                                                            $this->wearCostAverageGbpPerKwh,
                                                            $this->wearConstantCoefficient,
                                                            $this->energyExponentialCoefficient,
                                                            $this->energyActivationKwh,
                                                            $this->energyNormalisationCoefficient)*abs($battery_charge_kwh)/$duration_hour,
                'battery_power' =>  $this->wearPerKwh(      $charge_kw,
                                                           -$this->batteryMaxDischargeKw,
                                                            $this->batteryMaxChargeKw,
                                                            $this->wearCostAverageGbpPerKwh/$duration_hour,
                                                            0.0,
                                                            $this->powerExponentialCoefficient,
                                                            $this->powerActivationKw,
                                                            $this->powerNormalisationCoefficient)*abs($battery_charge_kwh)/$duration_hour,
                'grid_power'     =>  $this->wearPerKwh(     $grid_kw,
                                                           -$this->importLimitKw,
                                                            $this->exportLimitKw,
                                                            $this->wearCostAverageGbpPerKwh/$duration_hour,
                                                            0.0,
                                                            $this->powerExponentialCoefficient,
                                                            $this->powerActivationKw,
                                                            $this->powerNormalisationCoefficient)*abs($energy_grid_kwh)/$duration_hour
                ];
    }

    public function makeEnergyNormalisationCoefficient(): void
    {
        $this->energyNormalisationCoefficient = 12.0/(1.0+(11.0*$this->wearConstantCoefficient)+(24.0*$this->energyExponentialCoefficient*$this->energyActivationKwh/$this->batteryCapacityKwh));
    }

    public function makePowerNormalisationCoefficient(): void
    {
        $this->powerNormalisationCoefficient = 12.0/(1.0+(24.0*$this->powerActivationKw*$this->powerExponentialCoefficient/($this->batteryMaxDischargeKw+$this->batteryMaxChargeKw)));
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
            $this->logDb('MESSAGE', $message, 'ERROR');
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
            $this->logDb('MESSAGE', $message, 'ERROR');
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

    private function total_load_kws(): array {
        /*
         * calculate total load (L) net of solar generation
         */
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'SELECT      `load_house_kw` - `solar_kw` AS `total_load_kw`
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
            !$stmt->bind_result($id, $start_datetime, $start, $stop, $grid_kw, $battery_charge_kw, $battery_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->slotCommands = [];
        while ($stmt->fetch()) {
            $grid_w = (int)(1000.0 * $grid_kw);
            if ($battery_charge_kw > 0.0) {  // CHARGE
                $charge_power_w = (int) round(1000.0 * min($battery_charge_kw, $this->batteryMaxChargeKw));
            } else {                           // DISCHARGE
                $charge_power_w = (int) round(1000.0 * max($battery_charge_kw, -$this->batteryMaxDischargeKw));
            }
            if (abs($grid_w) < self::THRESHOLD_POWER_W) {                                 // ECO if no appreciable import/export
                $mode = 'ECO';
                $abs_charge_power_w = null;
                $target_level_percent = null;
                $message = ' between ' . $start . ' and ' . $stop;
            } elseif (abs($charge_power_w) > self::THRESHOLD_POWER_W) {                   // CHARGE, DISCHARGE when above threshold charge power
                $mode = $charge_power_w > 0 ? 'CHARGE' : 'DISCHARGE';
                $abs_charge_power_w = abs($charge_power_w);
                $target_level_percent = (int) round(100.0 * ($battery_level_kwh + $battery_charge_kw * $this->slotDurationHour) / $this->batteryCapacityKwh);
                $message = '@' . round($abs_charge_power_w) . 'W ' . ($mode == 'CHARGE' ? ' to ' . $target_level_percent . '% ': '') . 'between ' . $start . ' and ' . $stop;
            } else {                                                                      // otherwise IDLE
                $mode = 'IDLE';
                $abs_charge_power_w = null;
                $target_level_percent = null;
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