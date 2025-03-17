<?php
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class Sliver extends Root
{
    const int   SLIVER_DURATION_MINUTES = 1,
                SLIVER_DB_MAX_AGE_DAY   = 7,
                CHARGE_POWER_LEVELS     = 100;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    public function slotTargetParameters(): array {
        $sql = 'SELECT  `st`.`slot`,
                        `st`.`start`,
                        `st`.`stop`,
                        `st`.`mode`,
                        `st`.`abs_charge_power_w`,
                        `st`.`target_level_percent`,
                        `st`.`import_gbp_per_kwh`,
                        `st`.`export_gbp_per_kwh`
                   FROM `slots` `st`
                   INNER JOIN `tariff_combinations` `tc` ON `tc`.`id` = `st`.`tariff_combination`
                   WHERE `st`.`final` AND
                         `tc`.`active` AND 
                         NOW() BETWEEN `st`.`start` AND `st`.`stop`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $start, $stop, $mode, $abs_charge_power_w, $target_level_percent, $import_gbp_per_kwh, $export_gbp_per_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if (!$stmt->fetch()) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'No slot data');
            throw new Exception($message);
        }
        if ($slot) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Data slot not 0: ' . $slot);
            $this->logDb('MESSAGE', $message, 'WARNING');
        }
        return ['slot'                  =>  $slot,
                'start'                 =>  $start,
                'stop'                  =>  $stop,
                'mode'                  =>  $mode,
                'abs_charge_power_w'    =>  $abs_charge_power_w,
                'target_level_percent'  =>  $target_level_percent,
                'import_gbp_per_kwh'    =>  $import_gbp_per_kwh,
                'export_gbp_per_kwh'    =>  $export_gbp_per_kwh];
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function optimum_charge_w(): int {
        $givenergy                      = new GivEnergy();
        $energy_cost                    = new EnergyCost(null, null);
        $slot_target_parameters         =  $this->slotTargetParameters();             // get slot target parameters
        $slot_mode                      = $slot_target_parameters['mode'];
        $slot_abs_charge_power_w        = $slot_target_parameters['abs_charge_power_w'];
        $slot_target_level_percent      = $slot_target_parameters['target_level_percent'];
        $battery                        =  $givenergy->latest();      // get battery data
        $battery_level_percent          =  $battery['battery']['percent'];
        $battery_level_kwh              =  $battery_level_percent*$energy_cost->batteryCapacityKwh/100.0;
        $house_load_kw                  =  $battery['consumption']/1000.0;
        $solar_kw                       =  $battery['solar']['power']/1000.0;
        $net_load_kw                    =  $house_load_kw-$solar_kw;
        $charge_min_kw                  = -$givenergy->battery['max_discharge_kw'];
        $charge_max_kw                  =  $givenergy->battery['max_charge_kw'];
        $charge_increment_kw            = ($charge_max_kw - $charge_min_kw)/self::CHARGE_POWER_LEVELS;
        $duration_hour                  = self::SLIVER_DURATION_MINUTES / 60.0;
        $charge_kw                      = $charge_min_kw;
        $data                           = [];
        $optimum                        = [];
        for ($level = 0; $level <= self::CHARGE_POWER_LEVELS; $level++) {
            $grid_kw                        = - ($net_load_kw + $charge_kw);
            $cost_grid_per_hour             = - ($grid_kw < 0.0 ? $slot_target_parameters['import_gbp_per_kwh'] : $slot_target_parameters['export_gbp_per_kwh'])*$grid_kw;
            $wear_gbp_per_hour              = $energy_cost->wearGbpPerHour($grid_kw, $charge_kw, $battery_level_kwh, $duration_hour);
            $cost_total_wear_gbp_per_hour   = ($wear_gbp_per_hour['battery_energy'] + $wear_gbp_per_hour['battery_power'] + $wear_gbp_per_hour['grid_power']);
            $total_cost_per_hour            = $cost_grid_per_hour + $cost_total_wear_gbp_per_hour;
            $datum = ['grid_kw'             => $grid_kw,
                      'charge_kw'           => $charge_kw,
                      'battery_level_kwh'   => $battery_level_kwh,
                      'cost_grid_per_hour'  => $cost_grid_per_hour,
                      'wear_gbp_per_hour'   => $wear_gbp_per_hour,
                      'total_cost_per_hour' => $total_cost_per_hour,];
            if (!$optimum || ($total_cost_per_hour < $optimum['total_cost_per_hour'])) {
                $optimum = $datum;
            }
            $charge_kw += $charge_increment_kw;
            $data[$level] = $datum;
        }
        $sql = 'INSERT INTO `slivers`  (`optimum_charge_kw`, `level_percent`,    `slot_mode`,    `slot_abs_charge_power_w`,  `slot_target_level_percent`,    `house_load_kw`,    `solar_kw`) 
                                VALUES (?,                   ?,                  ?,              ?,                          ?,                              ?,                  ?)';
        $optimum_charge_kw = round($optimum['charge_kw'], 4);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('disdidd', $optimum_charge_kw,$battery_level_percent, $slot_mode, $slot_abs_charge_power_w, $slot_target_level_percent, $house_load_kw, $solar_kw) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $sql = 'DELETE FROM `slivers`
                    WHERE `timestamp` + INTERVAL ' . self::SLIVER_DB_MAX_AGE_DAY . ' DAY < NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
        return round(1000.0 * $optimum_charge_kw);
    }

    private function optimumChargeW(): float {

    }
}