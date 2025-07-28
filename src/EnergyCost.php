<?php

namespace Src;

use DateTime;
use Exception;

class EnergyCost extends Root
{
    const int       OPTIMIZE_UNCONSTRAINED = 0,
                    OPTIMIZE_CONSTRAINED = 1;
    const float     ABS_ECO_GRID_THRESHOLD_KW = 0.5;

    const string    OPTIMISATION_LOG_PATHNAME_BASE = '/var/www/html/energy/test/optimisation_',
                    PYTHON_OPTIMIZER_SCRIPT_BASE   = 'python3 /var/www/html/energy/src/',
                    PYTHON_OPTIMIZER               = 'optimize.py';

    // setup parameters
    public    float $solarGenerationLimitKw,
                    $batteryCapacityKwh,
                    $batteryOneWayEfficiency,
                    $batteryWearEnergyCostAverageGbpPerKwh,
                    $batteryWearEnergyConstantCoefficient,
                    $batteryWearEnergyExponentialCoefficient,
                    $batteryWearEnergyActivationKwh,
                    $batteryWearPowerCostAverageGbpPerKwh,
                    $batteryWearPowerConstantCoefficient,
                    $batteryWearPowerExponentialCoefficient,
                    $batteryWearPowerActivationKw,
                    $powerActivationKw,
                    $batteryMaxChargeKw,
                    $batteryMaxDischargeKw,
                    $slotSliceDurationHour,
                    $slot_slice_duration_hour,
                    $batteryEnergyInitialKwh,
                    $importLimitKw,
                    $exportLimitKw,
                    $batteryWearEnergyNormalisationCoefficient,
                    $batteryWearPowerNormalisationCoefficient;

    private array   $problem,
                    $load_house_kws,        // house load, excluding EV
                    $solar_gross_kws,       // gross solar generation, excludes dynamic grid power clipping
                    $grid_kws,
                    $tariff_combination,
                    $costs,
                    $slots_db,
                    $slices_db;

    private ?array $parameters;

    public string   $string;
    private int     $number_slots_slices, $optimizer, $number_slices_per_slot;

    /**
     * @throws Exception
     */
    public function __construct($parameters) {
        parent::__construct();
        $this->use_local_config();
        $this->parameters = $parameters;
        if (!is_null($batteryLevelInitialKwh = $parameters['batteryLevelInitialKwh']) &&
            !is_null($tariff_combination     = $parameters['tariff_combination'])) { // make json instantiate
            $this->tariff_combination        = $tariff_combination;
            $this->slot_slice_duration_hour  = $this->parameters['type'] == 'slots' ? (float)(Slot::DURATION_MINUTES / 60) : (float)(Slice::DURATION_MINUTES / 60);
            $this->number_slots_slices       = $this->parameters['type'] == 'slots' ? 24*60 / Slot::DURATION_MINUTES       : Slot::DURATION_MINUTES / Slice::DURATION_MINUTES;
            if (!DEBUG_MINIMISER) {
                $this->batteryEnergyInitialKwh = $batteryLevelInitialKwh;
                if ($parameters['type'] == 'slots') {
                    $this->slots_db = $this->slots_db(false);                      // load intermediate (non-final) slots parameter data
                }
                else {                                                                  // type of minimisation: slots, slices
                    $this->slots_db = $this->slots_db(true);                       // load final slots solution
                    $this->number_slices_per_slot = Slot::DURATION_MINUTES/Slice::DURATION_MINUTES;
                    $this->slices_db              = $this->slices_db();                 // make slices for this/next slot
                }
                $loadSolarImportExports           = $this->loadSolarImportExports();
            }
            else {
                $loadSolarImportExports      = [
                                              'load_house_kws'      => [],
                                              'solar_gross_kws'     => [],
                                              'import_gbp_per_kwhs' => [],
                                              'export_gbp_per_kwhs' => [],
                                              'import_gbp_per_days' => [],
                                              'export_gbp_per_days' => []
                                          ];
            }
            switch ($this->parameters['type']) {
                case 'slots': {
                    $optimizer = self::OPTIMIZE_UNCONSTRAINED;
                    break;
                }
                case 'slices': {
                    $optimizer = self::OPTIMIZE_CONSTRAINED;
                    break;
                }
                default: {}
            }
            $this->problem              = [
                                            'solarGenerationLimitKw'                  => $this->config['solar_pv']['generation_limit_kw'],
                                            'batteryCapacityKwh'                      => $this->config['battery']['initial_raw_capacity_kwh'],
                                            'batteryOneWayEfficiency'                 => sqrt(($this->config['battery']['round_trip_efficiency_percent'] ?? 100.0)/100.0),
                                            'batteryWearEnergyCostAverageGbpPerKwh'   => $this->config['battery']['wear']['energy']['cost_average_gbp_per_kwh'],
                                            'batteryWearEnergyConstantCoefficient'    => $this->config['battery']['wear']['energy']['constant_coefficient'],
                                            'batteryWearEnergyExponentialCoefficient' => $this->config['battery']['wear']['energy']['exponential_coefficient'],
                                            'batteryWearEnergyActivationKwh'          => $this->config['battery']['wear']['energy']['activation_kwh'],
                                            'batteryWearPowerCostAverageGbpPerKwh'    => $this->config['battery']['wear']['power'] ['cost_average_gbp_per_kwh'],
                                            'batteryWearPowerConstantCoefficient'     => $this->config['battery']['wear']['power'] ['constant_coefficient'],
                                            'batteryWearPowerExponentialCoefficient'  => $this->config['battery']['wear']['power'] ['exponential_coefficient'],
                                            'batteryWearPowerActivationKw'            => $this->config['battery']['wear']['power'] ['activation_kw'],
                                            'batteryMaxChargeKw'                      => $this->config['battery']['max_charge_kw'],
                                            'batteryMaxDischargeKw'                   => $this->config['battery']['max_discharge_kw'],
                                            'importLimitKw'                           => $this->config['energy']['grid']['import']['limit_kw'],
                                            'exportLimitKw'                           => $this->config['energy']['grid']['export']['limit_kw'],
                                            'batteryEnergyInitialKwh'                 => $batteryLevelInitialKwh,
                                            'slotSliceDurationHour'                   => $this->slot_slice_duration_hour,
                                            'number_slots_slices'                     => $this->number_slots_slices,
                                            'optimizer'                               => $optimizer,
                                            'import_gbp_per_days'                     => $loadSolarImportExports['import_gbp_per_days'],
                                            'export_gbp_per_days'                     => $loadSolarImportExports['export_gbp_per_days'],
                                            'import_gbp_per_kwhs'                     => $loadSolarImportExports['import_gbp_per_kwhs'],
                                            'export_gbp_per_kwhs'                     => $loadSolarImportExports['export_gbp_per_kwhs'],
                                            'load_house_kws'                          => $loadSolarImportExports['load_house_kws'],
                                            'solar_gross_kws'                         => $loadSolarImportExports['solar_gross_kws'],
                                          ];
        }
        else { // instantiate from config
            $this->solarGenerationLimitKw                   = (float) $this->config['solar_pv']['inverter']['power_threshold_kw'];
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

            $this->batteryEnergyInitialKwh                  = (float) $batteryLevelInitialKwh;
        }
    }

    /**
     * @throws Exception
     */
    private function slots_db($final): array {
        /*
         * get house load
         */
        $sql = 'SELECT      `start`,
                            `stop`,
                            `battery_charge_kw`,
                            `grid_kw`,
                            `load_house_kw`,
                            `solar_gross_kw`,
                            `import_gbp_per_kwh`,
                            `export_gbp_per_kwh`,
                            `import_gbp_per_day`,
                            `export_gbp_per_day`
                   FROM     `slots`
                   WHERE    `tariff_combination` = ? AND
                            `final`              = ?
                   ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ii', $this->tariff_combination['id'], $final) ||
            !$stmt->bind_result($start, $stop, $battery_charge_kw, $grid_kw, $load_house_kw, $solar_gross_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $starts                     = [];
        $stops                      = [];
        $battery_charge_kws         = [];
        $grid_kws                   = [];
        $slots                      = [];
        $load_house_kws             = [];
        $solar_gross_kws            = [];
        $import_gbp_per_kwh         = [];
        $export_gbp_per_kwh         = [];
        $import_gbp_per_day         = [];
        $export_gbp_per_day         = [];
        while ($stmt->fetch()) { // throw exception if slots problem paramter null
            if (is_null($load_house_kw) || is_null($solar_gross_kw) || is_null($import_gbp_per_kwh) || is_null($export_gbp_per_kwh) || is_null($import_gbp_per_day) || is_null($export_gbp_per_day)) {
                $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'null value');
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            else {
                $starts[]              = $start;
                $stops[]               = $stop;
                $battery_charge_kws[]  = $battery_charge_kw;
                $grid_kws[]            = $grid_kw;
                $load_house_kws[]      = $load_house_kw;
                $solar_gross_kws[]     = $solar_gross_kw;
                $import_gbp_per_kwhs[] = $import_gbp_per_kwh;
                $export_gbp_per_kwhs[] = $export_gbp_per_kwh;
                $import_gbp_per_days[] = $import_gbp_per_day;
                $export_gbp_per_days[] = $export_gbp_per_day;
            }
        }
        $slots['starts']              = $starts;
        $slots['stops']               = $stops;
        $slots['battery_charge_kws']  = $battery_charge_kws;
        $slots['grid_kws']            = $grid_kws;
        $slots['load_house_kws']      = $load_house_kws;
        $slots['solar_kws']           = $solar_gross_kws;
        $slots['import_gbp_per_kwhs'] = $import_gbp_per_kwhs;
        $slots['export_gbp_per_kwhs'] = $export_gbp_per_kwhs;
        $slots['import_gbp_per_days'] = $import_gbp_per_days;
        $slots['export_gbp_per_days'] = $export_gbp_per_days;
        return $slots;
    }

    /**
     * @throws Exception
     */
    private function slices_db(): array {  // return slices from the first/seconds slot starting at current time
        /*
         * get house load
         */
        $sql = 'SELECT      `start`,
                            `stop`,
                            `battery_level_start_kwh`,
                            `battery_charge_kw`,
                            `grid_kw`,
                            `load_house_kw`,
                            `solar_gross_kw`,
                            `import_gbp_per_kwh`,
                            `export_gbp_per_kwh`,
                            `import_gbp_per_day`,
                            `export_gbp_per_day`
                   FROM     `slots`
                   WHERE    `tariff_combination` = ? AND
                            `final` AND
                             ? BETWEEN `start` AND `stop`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('is', $this->tariff_combination['id'], $datetime_string) ||
            !$stmt->bind_result($start, $stop, $battery_level_start_kwh, $battery_charge_kw, $grid_kw, $load_house_kw, $solar_gross_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        $slots                    = [];
        $starts                   = [];
        $slices                   = [];
        $battery_level_start_kwhs = [];
        $battery_charge_kws       = [];
        $grid_kws                 = [];
        $load_house_kws           = [];
        $solar_gross_kws          = [];
        $import_gbp_per_kwh       = [];
        $export_gbp_per_kwh       = [];
        $import_gbp_per_day       = [];
        $export_gbp_per_day       = [];
        $datetime = new DateTime();
        for ($slice = 0; $slice < $this->number_slices_per_slot; $slice++) {
            $datetime_string = $datetime->format(self::MYSQL_FORMAT_DATETIME);
            $stmt->execute();
            $stmt->fetch();
            // throw exception if slices problem paramter null
            if (!is_null($battery_level_start_kwh) && !is_null($battery_charge_kw) && !is_null($grid_kw) && !is_null($load_house_kw) && !is_null($solar_gross_kw) && !is_null($import_gbp_per_kwh) && !is_null($export_gbp_per_kwh) && !is_null($import_gbp_per_day) && !is_null($export_gbp_per_day)) {
                $starts[]                   = $datetime_string;
                $battery_level_start_kwhs[] = $battery_level_start_kwh;
                $battery_charge_kws[]       = $battery_charge_kw;
                $grid_kws[]                 = $grid_kw;
                $load_house_kws[]           = $load_house_kw;
                $solar_gross_kws[]          = $solar_gross_kw;
                $import_gbp_per_kwhs[]      = $import_gbp_per_kwh;
                $export_gbp_per_kwhs[]      = $export_gbp_per_kwh;
                $import_gbp_per_days[]      = $import_gbp_per_day;
                $export_gbp_per_days[]      = $export_gbp_per_day;
                $datetime->modify('+' . Slice::DURATION_MINUTES . ' minute');
                $slices['slots']                    = $slots;
                $slices['starts']                   = $starts;
                $slices['battery_level_start_kwhs'] = $battery_level_start_kwhs;
                $slices['battery_charge_kws']       = $battery_charge_kws;
                $slices['grid_kws']                 = $grid_kws;
                $slices['load_house_kws']           = $load_house_kws;
                $slices['solar_gross_kws']          = $solar_gross_kws;
                $slices['import_gbp_per_kwhs']      = $import_gbp_per_kwhs;
                $slices['export_gbp_per_kwhs']      = $export_gbp_per_kwhs;
                $slices['import_gbp_per_days']      = $import_gbp_per_days;
                $slices['export_gbp_per_days']      = $export_gbp_per_days;
            }
            else {
                $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'no slices or null value');
                $this->logDb('MESSAGE', $message, null, 'WARNING');
            }
        }
        return $slices;
    }

    /**
     * @throws Exception
     */
    public function minimise(): array {
        //
        // returns optimum battery charge level for next slots/slices
        //
        // see https://scipy-lectures.org/advanced/mathematical_optimization/#knowing-your-problem
        //
        // convex, non-smooth, exact cost
        //
        if (!DEBUG_MINIMISER) {
            $first_guess_charge_kws = [];
            switch ($this->parameters['type']) {
                case 'slots': {
                    (new Root())->LogDb('OPTIMISING', $this->tariff_combination['name'],  null, 'NOTICE');
                    $this->load_house_kws  = $this->slots_db['load_house_kws'];         // house load (excludes EV)
                    $this->solar_gross_kws = $this->slots_db['solar_kws'];              // gross solar forecast (excludes grid clipping)
                    foreach ($this->load_house_kws as $slot => $load_house_kw) {        // first guess zero charge
                        $first_guess_charge_kws[$slot] = 0.0;
                    }
                    break;
                }
                case 'slices': {
                    if (empty($this->slices_db)) {
                        return $this->sliceSolution($this->slotSolution(), 0.0); // skip with zero charge if slices not found
                    }
                    $this->load_house_kws   = $this->slices_db['load_house_kws'];       // house load (excludes EV)
                    $this->solar_gross_kws  = $this->slices_db['solar_gross_kws'];      // gross solar forecast (excludes grid clipping)
                    $first_guess_charge_kws = $this->slices_db['battery_charge_kws'];   // first guess slice solution to slot solution

                    // set first 2 slices to current load and solar powers
                    $this->load_house_kws[0]  = $this->load_house_kws[1]  = $this->parameters['load_house_kw'];
                    $this->solar_gross_kws[0] = $this->solar_gross_kws[1] = $this->parameters['solar_gross_kw'];
                    break;
                }
            }
            $command = $this->command($this->problem['first_guess_charge_kws'] = $first_guess_charge_kws); // make optimize command line using parameters and first guesses
        }
        else { // use debug JSON and make slot arrays as necessary
           $suffix                 = DEBUG_MINIMISER_USE_FAIL ? 'fail' : 'last_ok';
           $pathname_problem       = self::DEBUG_PATH . 'problem_' . $this->parameters['type'] . '_' . $suffix . '.json';
           $this->problem          = json_decode(file_get_contents($pathname_problem, true), true);
           $this->load_house_kws   = $this->problem['load_house_kws'];                    // get total house load from problem
           $this->solar_gross_kws  = $this->problem['solar_gross_kws'];                   // get solar forecast (excludes grid clipping) from problem
           $first_guess_charge_kws = $this->problem['first_guess_charge_kws'];            // first guess
           $pathname_command       = self::DEBUG_PATH . 'command_' . $this->parameters['type'] . '_' . $suffix . '.txt';
           $command                = file_get_contents($pathname_command, true);
        }
        $this->costs = [];
        $this->costs['raw'] = $this->costCLI($command, $first_guess_charge_kws);
        $output = shell_exec($command);                                                     // execute Python command and capture output
        $result = json_decode($output, true);                                     // decode JSON output from Python
        $text   = $command . PHP_EOL . $output . PHP_EOL;
        if (!$command ||
            !file_put_contents(self::OPTIMISATION_LOG_PATHNAME_BASE . $this->parameters['type'] . '.log', $command . PHP_EOL . 'Solution >>>' . PHP_EOL . $output)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write log');
            $this->logDb('MESSAGE', $message, null, 'FATAL');
            throw new Exception($message);
        }
        $energyCostGuess    = $result['energyCostGuess']    ?? null; // cost, first guess
        $energyCostSolution = $result['energyCostSolution'] ?? null; // cost, solution
        $optimum_charge_kws = $result['optimum_charge_kws'] ?? null; // solution charge rates
        if (!($converged = $result['converged'] ?? false)) { // write out problem and log warning if not converged
            $this->write_problem_command($command, 'fail');
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, $this->parameters['type'] . ' convergence failure: see failed problem and command');
            $this->logDb('MESSAGE', $message, $text, 'WARNING');
        }
        else {
            $this->write_problem_command($command, 'last_ok');
        }
        if (!DEBUG_MINIMISER) {      // use solution if non-null and has converged or is better than first guess
            $use_solution = !is_null($optimum_charge_kws) && ($converged || ((!is_null($energyCostGuess) && !is_null($energyCostSolution)) && ($energyCostSolution < $energyCostGuess)));
            if (!$use_solution && ($this->parameters['type'] == 'slots')) {  // halt if no slot solution
                throw new Exception('No slot solution');
            }
            $this->problem['first_guess_charge_kws'] = $first_guess_charge_kws;
            $this->problem['optimum_charge_kws']     = $optimum_charge_kws;
            $this->costs['optimised']                = $this->costCLI($command, $optimum_charge_kws);       // calculate php optimised cost elements using CLI command
            $this->costs['gbp_per_day']     = $this->problem['import_gbp_per_days'] + $this->problem['export_gbp_per_days'];
            if (DEBUG) {
                echo ucfirst(ltrim(($converged ? '' : 'NOT ') . 'converged, ' . ($use_solution ? '' : 'NOT ') . 'usable'                                        . PHP_EOL));
                $indent = '   ';
                echo 'Total costs: '                                                                                                                                . PHP_EOL;
                echo $indent . 'Python, guess:     ' . round($energyCostGuess                       + $this->costs['gbp_per_day'],4) . ' GBP' . PHP_EOL;
                echo $indent . 'Php,    guess:     ' . round($this->costs['raw']['total_gbp']       + $this->costs['gbp_per_day'],4) . ' GBP' . PHP_EOL;
                echo $indent . 'Python, optimised: ' . round($energyCostSolution                    + $this->costs['gbp_per_day'],4) . ' GBP' . PHP_EOL;
                echo $indent . 'Php,    optimised: ' . round($this->costs['optimised']['total_gbp'] + $this->costs['gbp_per_day'],4) . ' GBP' . PHP_EOL;
                echo                                                                                                                                                  PHP_EOL;
                echo 'Grid cost, optimised: ' . round($this->costs['optimised']['grid_gbp']         + $this->costs['gbp_per_day'],4) . ' GBP' . PHP_EOL;
                echo                                                                                                                                                  PHP_EOL;
            }
            switch ($this->parameters['type']) {
                case 'slots': { // insert for each slot: grid and battery discharge energies (kWh)
                    $this->insertOptimumChargeGridKw($optimum_charge_kws);
                    $slot_solution = $this->slotSolution();
                    $this->insertSlotNextDayCostEstimates();
                    return $slot_solution;
                }
                case 'slices': { // use slice_solution if available, otherwise use slot solution
                    $charge_kw = $use_solution ? round($optimum_charge_kws[0], 3) : $first_guess_charge_kws[0];
                    $this->insertSliceChargekW($use_solution ? $charge_kw : null);
                    return $this->sliceSolution($this->slotSolution(), $charge_kw);
                }
                default: {
                    throw new Exception('Bad type');
                }
            }
        }
        else {
                echo PHP_EOL;
                echo 'grid_kw        raw,   optimised' . PHP_EOL;
                foreach ($this->grid_kws as $k => $v) {
                    echo sprintf("%5.1f", (float)$k/2.0) . ':             ' . round($this->grid_kws[$k], 3) . ', ' . round($optimum_charge_kws[$k], 3) . PHP_EOL;
                }
                return [];
        }
    }

    /**
     * @throws Exception
     */
    private function write_problem_command($command, $suffix): void // writes problem and python optimiser command to files
    {
        $pathname_problem = self::DEBUG_PATH . 'problem_' . $this->parameters['type'] . '_' . $suffix . '.json';
        if (!($json_problem = json_encode($this->problem, JSON_PRETTY_PRINT)) || !file_put_contents($pathname_problem, $json_problem)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write json problem parameters');
            $this->logDb('MESSAGE', $message, null, 'FATAL');
            throw new Exception($message);
        }
        if (!file_put_contents(self::DEBUG_PATH . 'command_' . $this->parameters['type'] . '_' . $suffix . '.txt', $command)) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Could not write command');
            $this->logDb('MESSAGE', $message, null, 'FATAL');
            throw new Exception($message);
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
                                `battery_level_start_kwh`,
                                `battery_charge_kw`,
                                `grid_kw`,
                                `load_house_kw`,
                                `solar_gross_kw`
                    FROM        `slots`
                    WHERE       `slot` = 0 AND
                                `tariff_combination` = ? AND
                                NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $start, $stop, $battery_level_start_kwh, $battery_charge_kw, $grid_kw, $load_house_kw, $solar_gross_kw) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        $abs_charge_w = round(1000.0 * abs($battery_charge_kw));
        $battery_level_target_kwh = $battery_level_start_kwh + (($battery_charge_kw * (float) Slot::DURATION_MINUTES) / 60.0);
        $target_level_percent = min(100, max(0, (int) round((100.0 * $battery_level_target_kwh / $this->batteryCapacityKwh))));
        if (abs($grid_kw) < self::ABS_ECO_GRID_THRESHOLD_KW) {
            $mode = 'ECO';
        }
        else {
            $mode = $battery_charge_kw < 0.0 ? 'DISCHARGE' : 'CHARGE';
        }
        $slot_solution = [
                         'id'                    => $id,
                         'start'                 => $start,
                         'stop'                  => $stop,
                         'mode'                  => $mode,
                         'abs_charge_w'          => $abs_charge_w,
                         'target_level_percent'  => $target_level_percent,
                         'message'               => $mode . ($mode == 'ECO' ? '' : '@' . $abs_charge_w . 'W') . ' to ' . $target_level_percent . '%'
                         ];
         return $slot_solution;
    }
    private function sliceSolution($slot_solution, $charge_kw): array {
        $slice_solution = $slot_solution;
        $slice_solution['abs_charge_w'] = (int) abs(1000.0 * $charge_kw);
        return $slice_solution;
    }
    private function command($first_guess_charge_kws): string {
        //
        // make CLI command string
        //
        $command = self::PYTHON_OPTIMIZER_SCRIPT_BASE . self::PYTHON_OPTIMIZER . ' ';
        $command .= $this->parameter_name_value('solarGenerationLimitKw');
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
        $command .= $this->parameter_name_value('batteryEnergyInitialKwh');
        $command .= $this->parameter_name_value('slotSliceDurationHour');
        $command .= $this->parameter_name_value('number_slots_slices');
        $command .= $this->parameter_name_value('optimizer');

        $number_slots_slices = $this->problem['number_slots_slices'];

        $import_gbp_per_kwhs = $this->problem['import_gbp_per_kwhs'];
        $command .= 'import_gbp_per_kwhs= ';
        for ($slot_slice = 0; $slot_slice < $number_slots_slices; $slot_slice++) {
            $command .= $import_gbp_per_kwhs[$slot_slice] . ' ';
        }

        $export_gbp_per_kwhs = $this->problem['export_gbp_per_kwhs'];
        $command .= 'export_gbp_per_kwhs= ';
        for ($slot_slice = 0; $slot_slice < $number_slots_slices; $slot_slice++) {
            $command .= $export_gbp_per_kwhs[$slot_slice] . ' ';
        }
        $command .= 'load_house_kws= ';
        for ($slot_slice = 0; $slot_slice < $number_slots_slices; $slot_slice++) {
            $command .= $this->load_house_kws[$slot_slice] . ' ';
        }
        $command .= 'solar_gross_kws= ';
        for ($slot_slice = 0; $slot_slice < $number_slots_slices; $slot_slice++) {
            $command .= $this->solar_gross_kws[$slot_slice] . ' ';
        }
        $command .= 'first_guess_charge_kws= ';
        for ($slot_slice = 0; $slot_slice < $number_slots_slices; $slot_slice++) {
            $command .= $first_guess_charge_kws[$slot_slice] . ' ';
        }
        return $command;
    }
    private function costCLI($command, $charge_kws): array {
        //
        // calculates cost using SciPy command line arguments and $grid_kw solution
        //
        // excludes standing costs
        //
        $this->string = $command;
        $this->strip(' '); $this->strip(' '); // removes PYTHON_SCRIPT_COMMAND
        $this->strip('=');
        $this->solarGenerationLimitKw                   = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryCapacityKwh                       = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryOneWayEfficiency                  = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearEnergyCostAverageGbpPerKwh    = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearEnergyConstantCoefficient     = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearEnergyExponentialCoefficient  = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearEnergyActivationKwh           = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearPowerCostAverageGbpPerKwh     = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearPowerConstantCoefficient      = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearPowerExponentialCoefficient   = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryWearPowerActivationKw             = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryMaxChargeKw                       = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryMaxDischargeKw                    = (float) $this->strip(' ');
        $this->strip('=');
        $this->importLimitKw                            = (float) $this->strip(' ');
        $this->strip('=');
        $this->exportLimitKw                            = (float) $this->strip(' ');
        $this->strip('=');
        $this->batteryEnergyInitialKwh                  = (float) $this->strip(' ');
        $this->strip('=');
        $this->slotSliceDurationHour                    = (float) $this->strip(' ');
        $this->strip('=');
        $this->number_slots_slices                      = (int)   $this->strip(' ');
        $this->strip('=');
        $this->optimizer                                = (int)   $this->strip(' ');
        $this->strip('=');
        $this->strip(' ');
        $import_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots_slices; $slot_count++) {
            $import_gbp_per_kws[]                       = (float) $this->strip(' ');
        }
        $this->strip('=');
        $this->strip(' ');
        $export_gbp_per_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots_slices; $slot_count++) {
            $export_gbp_per_kws[]                       = (float) $this->strip(' ');
        }
        $this->strip('=');
        $this->strip(' ');
        $load_house_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots_slices; $slot_count++) {
            $load_house_kws[]                           = (float) $this->strip(' ');
        }
        $this->strip('=');
        $this->strip(' ');
        $solar_gross_kws = [];
        for ($slot_count = 0; $slot_count < $this->number_slots_slices; $slot_count++) {
            $solar_gross_kws[]                          = (float) $this->strip(' ');
        }
        return $this->dayCostGbp($charge_kws, $import_gbp_per_kws, $export_gbp_per_kws, $load_house_kws, $solar_gross_kws);
    }
    private function dayCostGbp($battery_charge_kws, $import_gbp_per_kws, $export_gbp_per_kws, $load_house_kws, $solar_gross_kws): array {
        /*
         * calculate cost components: does not include standing costs
         */
        $cost_energy_average_per_kwh_acc = 0.0;                       // accumulator for calculating average energy cost
        $battery_level_kwh = $this->batteryEnergyInitialKwh;          // battery level at beginning of day
        $this->makeNormalisationCoefficients();
        $import_gbp             = 0.0;
        $export_gbp             = 0.0;
        $wear_gbp               = 0.0;
        $out_of_spec_gbp  = 0.0;
        $import_kwh             = 0.0;
        $export_kwh             = 0.0;
        for ($slot_count = 0; $slot_count < $this->number_slots_slices; $slot_count++) {
            $tariff_import_per_kwh = $import_gbp_per_kws[$slot_count];
            $tariff_export_per_kwh = $export_gbp_per_kws[$slot_count];
            $battery_charge_kw     = $battery_charge_kws[$slot_count];
            $load_house_kw         = $load_house_kws[$slot_count];
            $solar_clipped_kw      = min($solar_gross_kws[$slot_count], $this->solarGenerationLimitKw);     // clip solar to generation limit
            $grid_kw               = $solar_clipped_kw - $load_house_kw - $battery_charge_kw;
            $grid_kw               = min($grid_kw, $this->exportLimitKw);                                   // clip grid export to limit
            if ($solar_clipped_kw - $load_house_kw < 0.0) {                                                 // tie export to battery discharge limit when no net solar
                $grid_kw = min($grid_kw, $this->batteryMaxDischargeKw);
            }
            $this->grid_kws[$slot_count] = $grid_kw;
            $grid_kwh              = $grid_kw           * $this->slotSliceDurationHour;
            $battery_charge_kwh    = $battery_charge_kw * $this->slotSliceDurationHour;

            // grid
            if ($grid_kwh > 0.0) {
                $export_kwh       += $grid_kwh;
                $export_gbp -= $tariff_export_per_kwh * $grid_kwh;
            } else {
                $import_kwh       += -$grid_kwh;
                $import_gbp -= $tariff_import_per_kwh * $grid_kwh;
            }

            // battery
            if ($battery_charge_kwh > 0.0) {
               $battery_level_kwh += $battery_charge_kwh * $this->batteryOneWayEfficiency;
            }
            else {
               $battery_level_kwh += $battery_charge_kwh / $this->batteryOneWayEfficiency;
            }

            // operational and out of spec wear
            $wear_gbp      += $this->wearPerKwh(   $battery_level_kwh,
                                                   0.0,
                                                   $this->batteryCapacityKwh,
                                                   $this->batteryWearEnergyCostAverageGbpPerKwh,
                                                   $this->batteryWearEnergyConstantCoefficient,
                                                   $this->batteryWearEnergyExponentialCoefficient,
                                                   $this->batteryWearEnergyActivationKwh,
                                                   $this->batteryWearEnergyNormalisationCoefficient)*abs($battery_charge_kwh);
            // battery charge/discharge power out of spec
            $out_of_spec_gbp += $this->wearPerKwh(   $battery_charge_kw,
                                                           -$this->batteryMaxDischargeKw,
                                                            $this->batteryMaxChargeKw,
                                                            $this->batteryWearPowerCostAverageGbpPerKwh,
                                                            $this->batteryWearPowerConstantCoefficient,
                                                            $this->batteryWearPowerExponentialCoefficient,
                                                            $this->batteryWearPowerActivationKw,
                                                            $this->batteryWearPowerNormalisationCoefficient)*abs($battery_charge_kwh);
            $cost_energy_average_per_kwh_acc += 0.5 * ($tariff_import_per_kwh + $tariff_export_per_kwh);    // accumulate average energy cost
        }
        $level_change_gbp = ($this->batteryEnergyInitialKwh - $battery_level_kwh) * $cost_energy_average_per_kwh_acc / ((float) $this->number_slots_slices);
        $total_gbp = $import_gbp + $export_gbp + $wear_gbp + $out_of_spec_gbp + $level_change_gbp;
        return [
                    'total_gbp'         => $total_gbp,
                    'grid_gbp'          => $import_gbp+$export_gbp,
                    'import_gbp'        => $import_gbp,
                    'export_gbp'        => $export_gbp,
                    'wear_gbp'          => $wear_gbp,
                    'out_of_spec_gbp'   => $out_of_spec_gbp,
                    'level_change_gbp'  => $level_change_gbp,
                    'import_kwh'        => $import_kwh,
                    'export_kwh'        => $export_kwh
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
    public function wearGbpPerHour($charge_kw, $battery_level_kwh, $duration_hour): array {
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
        $battery_energy_per_hour = $battery_energy_per_kwh * abs($battery_charge_kwh)/$duration_hour;
        $battery_power_per_hour  = $battery_power_per_kwh  * abs($battery_charge_kwh)/$duration_hour;
        return ['battery_energy' => $battery_energy_per_hour,
                'battery_power'  => $battery_power_per_hour];
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
    }
    public function normalisationCoefficient($constant_coefficient, $exponential_coefficient, $activation, $x_min, $x_max): float {
        return 12.0/(1.0+(11.0*$constant_coefficient)+(24.0*$exponential_coefficient*$activation/($x_max - $x_min)));
    }

    /**
     * @throws Exception
     */
    private function loadSolarImportExports(): array { // get load, import and export tariffs for each slot/slice
        switch ($this->parameters['type']) {
            case ('slots'): {
                $sql = 'SELECT      `load_house_kw`,
                                    `solar_gross_kw`,
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
                    !$stmt->bind_result($load_house_kw, $solar_gross_kw, $import_gbp_per_kwh, $export_gbp_per_kwh, $import_gbp_per_day, $export_gbp_per_day) ||
                    !$stmt->execute()) {
                    $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $message, null, 'ERROR');
                    throw new Exception($message);
                }
                $load_house_kws       = [];
                $solar_gross_kws      = [];
                $import_gbp_per_kwhs  = [];
                $export_gbp_per_kwhs  = [];
                while ($stmt->fetch()) {
                    $load_house_kws[]      = $load_house_kw;
                    $solar_gross_kws[]     = $solar_gross_kw;
                    $import_gbp_per_kwhs[] = $import_gbp_per_kwh;
                    $export_gbp_per_kwhs[] = $export_gbp_per_kwh;
                }
                $load_solar_import_export = [
                                                'load_house_kws'        => $load_house_kws,
                                                'solar_gross_kws'       => $solar_gross_kws,
                                                'import_gbp_per_kwhs'   => $import_gbp_per_kwhs,
                                                'export_gbp_per_kwhs'   => $export_gbp_per_kwhs,
                                                'import_gbp_per_days'   => $import_gbp_per_day,
                                                'export_gbp_per_days'   => $export_gbp_per_day
                                            ];
                break;
            }
            case ('slices'): {
                $load_solar_import_export = [
                                                'load_house_kws'        => $this->slices_db['load_house_kws'],
                                                'solar_gross_kws'       => $this->slices_db['solar_gross_kws'],
                                                'import_gbp_per_kwhs'   => $this->slices_db['import_gbp_per_kwhs'],
                                                'export_gbp_per_kwhs'   => $this->slices_db['export_gbp_per_kwhs'],
                                                'import_gbp_per_days'   => end($this->slices_db['import_gbp_per_days']),
                                                'export_gbp_per_days'   => end($this->slices_db['export_gbp_per_days'])
                                           ];
                break;
            }
            default: {
                $load_solar_import_export = [];
            }
        }
        return $load_solar_import_export;
    }

    private function parameter_name_value($parameter_name): string {  // make parameter substring
        return $parameter_name . '=' . $this->problem[$parameter_name] . ' ';
    }

    /**
     * @throws Exception
     */
    private function insertOptimumChargeGridKw($optimum_charge_kws): void {
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'UPDATE      `slots`
                   SET      `battery_level_start_kwh` = ROUND(?, 3),
                            `battery_charge_kw`       = ROUND(?, 3),
                            `grid_kw`                 = ROUND(`solar_gross_kw` - `load_house_kw` - `battery_charge_kw`, 3)
                   WHERE    `slot`                    = ? AND
                            `tariff_combination`      = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ddii',  $battery_level_start_kwh, $battery_charge_kw, $slot, $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $battery_level_start_kwh = $this->batteryEnergyInitialKwh;
        foreach ($optimum_charge_kws as $slot => $battery_charge_kw) {
            $stmt->execute();
            $battery_level_start_kwh += $battery_charge_kw * Slot::DURATION_MINUTES / 60;
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function insertSliceChargekW($optimum_charge_kw): void {
        $sql = 'INSERT INTO `slice_solutions` (`charge_kw`) VALUES (?)';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('d',  $optimum_charge_kw) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
        $this->mysqli->commit();
    }

    private function strip($char): string
    {
        $pos          = strpos($this->string, $char);
        $argument     = substr($this->string, 0, $pos);
        $this->string = substr($this->string, $pos + 1);
        return $argument;
    }

    /**
     * @throws Exception
     */
    private function insertSlotNextDayCostEstimates(): void     {
        $raw                   = $this->costs['raw'];
        $raw_import            = round($raw['import_gbp'], 3);
        $raw_export            = round($raw['export_gbp'], 3);
        $raw_import_kwh        = round($raw['import_kwh'], 3);
        $raw_export_kwh        = round($raw['export_kwh'], 3);
        $optimised             = $this->costs['optimised'];
        $optimised_import      = round($optimised['import_gbp'], 3);
        $optimised_export      = round($optimised['export_gbp'], 3);
        $optimised_wear        = round($optimised['wear_gbp'], 3);
        $optimised_import_kwh  = round($optimised['import_kwh'], 3);
        $optimised_export_kwh  = round($optimised['export_kwh'], 3);
        $standing              = $this->costs['gbp_per_day'];
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'INSERT INTO `slot_next_day_cost_estimates` (`tariff_combination`, `standing`, `raw_import`, `raw_export`, `raw_import_kwh`, `raw_export_kwh`, `optimised_import`, `optimised_export`, `optimised_wear`, `optimised_import_kwh`, `optimised_export_kwh`)
                                                    VALUES (?,                   ?,          ?,            ?,            ?,                ?,                ?,                  ?,                  ?,                ?,                      ?                     )
                 ON DUPLICATE KEY UPDATE  `standing`                = ?, 
                                          `raw_import`              = ?,
                                          `raw_export`              = ?,
                                          `raw_import_kwh`          = ?,
                                          `raw_export_kwh`          = ?, 
                                          `optimised_import`        = ?, 
                                          `optimised_export`        = ?, 
                                          `optimised_wear`          = ?, 
                                          `optimised_import_kwh`    = ?, 
                                          `optimised_export_kwh`    = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('idddddddddddddddddddd', $tariff_combination_id, $standing, $raw_import, $raw_export, $raw_import_kwh, $raw_export_kwh, $optimised_import, $optimised_export, $optimised_wear, $optimised_import_kwh, $optimised_export_kwh,
                                                                                               $standing, $raw_import, $raw_export, $raw_import_kwh, $raw_export_kwh, $optimised_import, $optimised_export, $optimised_wear, $optimised_import_kwh, $optimised_export_kwh) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }
}