<?php
namespace Src;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class Sliver extends Root
{
    const int   SLIVER_DURATION_MINUTES = 1,
                CHARGE_POWER_LEVELS     = 100;
    const float CHARGE_DISCHARGE_MIN_KW = 0.5;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function charge_w(): string {
        $givenergy                    = new GivEnergy();
        $energy_cost                  = new EnergyCost(null, null);
        if (!($slot_target_parameters = $this->slotTargetParameters())) { // no slot solution target, no sliver to solve so place in ECO mode
            $command = [
                        'slot_solution'         =>  null,
                        'start'                 =>  null,
                        'stop'                  =>  null,
                        'mode'                  =>  'ECO',
                        'abs_charge_power_w'    =>  null,
                        'target_level_percent'  =>  null
                        ];
        }
        else { // insert sliver solution row get get primary key
            $slot_solution = $slot_target_parameters['slot_solution'];
            $sql = 'INSERT INTO `sliver_solutions` (`slot_solution`) VALUES (?)';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('i', $slot_solution) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $sliver_solution_id         =  $this->mysqli->insert_id;
            $slot_mode                  =  $slot_target_parameters['mode'];
            $battery                    =  $givenergy->latest();                                // get battery data
            $ev_load_kw                 =  $givenergy->evLatestPowerW()/1000.0;                 // ev load
            $battery_level_percent      =  $battery['battery']['percent'];
            $battery_level_kwh          =  $battery_level_percent*$energy_cost->batteryCapacityKwh/100.0;
            $house_load_kw              =  $battery['consumption']/1000.0;
            $solar_kw                   =  $battery['solar']['power']/1000.0;
            $net_load_kw                =  $house_load_kw+$ev_load_kw-$solar_kw;
            $charge_min_kw              = -$givenergy->battery['max_discharge_kw'];
            $charge_max_kw              =  $givenergy->battery['max_charge_kw'];
            $charge_increment_kw        = ($charge_max_kw - $charge_min_kw)/self::CHARGE_POWER_LEVELS;
            $duration_hour              =  self::SLIVER_DURATION_MINUTES / 60.0;
            $charge_kw                  =  $charge_min_kw;
            $data                       =  [];
            $optimum_total_gbp_per_hour =  null;
            $energy_cost->makeNormalisationCoefficients();
            $sql = 'TRUNCATE TABLE `slivers`';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $sql = 'INSERT INTO `slivers` (`id`, `grid_kw`,  `grid_tariff_gbp_per_kwh`, `charge_kw`, `battery_level_kwh`, `grid_gbp_per_hour`, `wear_gbp_per_hour`, `total_gbp_per_hour`, `cost_total_wear_gbp_per_hour`)
                               VALUES (?,     ?,          ?,                         ?,           ?,                   ?,                   ?,                   ?,                    ?                            )';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('idddddddd', $id, $grid_kw,  $grid_tariff_gbp_per_kwh, $charge_kw, $battery_level_kwh, $grid_gbp_per_hour, $wear_gbp_per_hour, $total_gbp_per_hour, $cost_wear_gbp_per_hour)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            for ($id = 0; $id <= self::CHARGE_POWER_LEVELS; $id++) {
                $grid_kw                  = -($net_load_kw + $charge_kw);
                $grid_tariff_gbp_per_kwh  =   $grid_kw < 0.0 ? $slot_target_parameters['import_gbp_per_kwh'] : $slot_target_parameters['export_gbp_per_kwh'];
                $grid_gbp_per_hour        =  -$grid_tariff_gbp_per_kwh*$grid_kw;
                $wear_gbp_per_hour        =  $energy_cost->wearGbpPerHour($grid_kw, $charge_kw, $battery_level_kwh, $duration_hour);
                $cost_wear_gbp_per_hour   =  $wear_gbp_per_hour['grid_power'];
                $total_gbp_per_hour       =  $grid_gbp_per_hour + $cost_wear_gbp_per_hour;
                $data[$id] = ['grid_kw'                         => $grid_kw,
                    'grid_tariff_gbp_per_kwh'         => $grid_tariff_gbp_per_kwh,
                    'charge_kw'                       => $charge_kw,
                    'battery_level_kwh'               => $battery_level_kwh,
                    'grid_gbp_per_hour'               => $grid_gbp_per_hour,
                    'wear_gbp_per_hour'               => $wear_gbp_per_hour,
                    'total_gbp_per_hour'              => $total_gbp_per_hour,
                    'cost_total_wear_gbp_per_hour'    => $cost_wear_gbp_per_hour];
                $stmt->execute();
                if (is_null($optimum_total_gbp_per_hour) || ($total_gbp_per_hour < $optimum_total_gbp_per_hour)) {
                    $optimum_total_gbp_per_hour = $total_gbp_per_hour;
                    $optimum_id = $id;
                }
                $charge_kw += $charge_increment_kw;
            }
            $sql = 'UPDATE    `slivers`
                    SET   `optimum` = TRUE
                    WHERE `id` = ?';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('i', $optimum_id) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $sql = 'UPDATE  `sliver_solutions`
                    SET `charge_kw` = ?,
                        `level_percent` = ?,
                        `cost_total_gbp_per_hour` = ?,
                        `cost_grid_gbp_per_hour` = ?,
                        `cost_wear_gbp_per_hour` = ?,
                        `house_load_kw` = ?,
                        `solar_kw` = ?
                  WHERE `slot_solution` = ?';
            $optimum                 = $data[$optimum_id];
            $optimum_charge_kw       = $optimum['charge_kw'];
            $cost_total_gbp_per_hour = round($optimum['total_gbp_per_hour'], 3);
            $cost_grid_gbp_per_hour  = round($optimum['grid_gbp_per_hour'], 3);
            $cost_wear_gbp_per_hour  = round($optimum['cost_total_wear_gbp_per_hour'], 3);
            switch ($slot_mode) {
                case 'CHARGE':          // calculate target percent level into slot
                case 'DISCHARGE': {
                    $sign                          = $slot_mode == 'CHARGE' ? -1.0 : 1.0;
                    $slot_end_target_level_percent = $slot_target_parameters['target_level_percent'];
                    $abs_charge_power_w            = $slot_target_parameters['abs_charge_power_w'];
                    $slot_duration_minutes         = (new DateTime($slot_target_parameters['start']))->diff(new DateTime($slot_target_parameters['stop']))->i;
                    $slot_progress_minutes         = (new DateTime($slot_target_parameters['start']))->diff(new DateTime())->i;
                    $slot_energy_kwh               = ($abs_charge_power_w/1000.0)*((float) $slot_progress_minutes)/60.0;
                    $slot_start_percent            = ((float) $slot_end_target_level_percent) + $sign * (100.0 * $slot_energy_kwh / $this->config['battery']['initial_raw_capacity_kwh']);
                    $target_percent                = round($slot_start_percent + ($slot_progress_minutes/$slot_duration_minutes) * ($slot_end_target_level_percent - $slot_start_percent));
                    break;
                }
                default:
                    $target_percent                = null;
            }
            switch ($slot_mode) {
                case 'CHARGE': {    // only charge if above min efficient power and target not reached
                    $charge_kw = (($optimum_charge_kw > self::CHARGE_DISCHARGE_MIN_KW)  && ($battery_level_percent < $target_percent)) ? round($optimum_charge_kw, 3) : 0.0;
                    break;
                }
                case 'DISCHARGE': { // only discharge if above min efficient power and target not reached
                    $charge_kw = (($optimum_charge_kw < -self::CHARGE_DISCHARGE_MIN_KW) && ($battery_level_percent > $target_percent)) ? round($optimum_charge_kw, 3) : 0.0;
                    break;
                }
                case 'ECO':
                case 'IDLE': {
                    $charge_kw = 0.0;
                    break;
                }
            }
            $charge_power_w = round(1000.0 * $charge_kw);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('ididddddi', $slot_solution, $charge_kw,$battery_level_percent, $cost_total_gbp_per_hour, $cost_grid_gbp_per_hour, $cost_wear_gbp_per_hour, $house_load_kw, $solar_kw, $sliver_solution_id) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $this->mysqli->commit();
            $command  = [
                            'mode'                  => $slot_mode,
                            'start'                 => (new DateTime($slot_target_parameters['start']))->format('H:i'),
                            'stop'                  => (new DateTime($slot_target_parameters['stop'])) ->format('H:i'),
                            'abs_charge_power_w'    => abs($charge_power_w),
                            'target_level_percent'  => $slot_end_target_level_percent,
                            'message'               => 'sliver control'
                        ];
        }
        if (ENABLE_SLIVER_COMMAND) {                        // control battery for active combination on completion of countdown to next slot
            $givenergy->control($command);
        }
        return (string) $charge_power_w;
    }

    /**
     * @throws Exception
     */
    public function slotTargetParameters(): array {
        $sql = 'SELECT  `id`,
                        `start`,
                        `stop`,
                        `mode`,
                        `abs_charge_power_w`,
                        `target_level_percent`,
                        `import_gbp_per_kwh`,
                        `export_gbp_per_kwh`
                   FROM `slot_solutions`
                   WHERE NOW() BETWEEN `start` AND `stop`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot_solution, $start, $stop, $mode, $abs_charge_power_w, $target_level_percent, $import_gbp_per_kwh, $export_gbp_per_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        if (!$stmt->fetch()) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'No slot data');
            $this->logDb('MESSAGE', $message, null, 'WARNING');
            return [];
        }
        else {
            return ['slot_solution'         =>  $slot_solution,
                    'start'                 =>  $start,
                    'stop'                  =>  $stop,
                    'mode'                  =>  $mode,
                    'abs_charge_power_w'    =>  $abs_charge_power_w,
                    'target_level_percent'  =>  $target_level_percent,
                    'import_gbp_per_kwh'    =>  $import_gbp_per_kwh,
                    'export_gbp_per_kwh'    =>  $export_gbp_per_kwh];
        }
    }
}