<?php
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class Sliver extends Root
{
    const int   SLIVER_DURATION_MINUTES = 1,
                CHARGE_POWER_LEVELS = 100;


    public function __construct()
    {
        parent::__construct();
    }

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
        $givenergy              = new GivEnergy();
        $energy_cost            = new EnergyCost(null, null);
        $energy_cost->makeNormalisationEnergyCoefficient();
        $energy_cost->makeNormalisationPowerCoefficient();
        $slot_target_parameters = $this->slotTargetParameters();             // get slot target parameters
        $battery                = $givenergy->latest();      // get battery data
        $battery_level_kwh      = $battery['battery']['percent']*$energy_cost->batteryCapacityKwh/100.0;
        $net_load_kw            = ($battery['consumption']-$battery['solar']['power'])/1000.0;
        $charge_power_min_kw    = -$givenergy->battery['max_discharge_kw'];
        $charge_power_max_kw    =  $givenergy->battery['max_charge_kw'];
        $charge_power_increment_kw = ($charge_power_max_kw - $charge_power_min_kw)/self::CHARGE_POWER_LEVELS;
        $duration_hour = self::SLIVER_DURATION_MINUTES / 60.0;
        $charge_power_kw = $charge_power_min_kw;
        $optimum_cost_per_kwh = null;
        for ($level = 0; $level <= self::CHARGE_POWER_LEVELS; $level++) {
            $grid_power_kw                      = $net_load_kw + $charge_power_kw;
            $cost_grid_per_kwh                  = $grid_power_kw < 0.0 ? $slot_target_parameters['import_gbp_per_kwh'] : $slot_target_parameters['export_gbp_per_kwh'];
            $cost_wear_out_of_spec_gbp_per_kwh  = $energy_cost->costWearOutOfSpecGbp($grid_power_kw, $charge_power_kw, $battery_level_kwh, $duration_hour);
            $cost_per_kwh                       = $cost_grid_per_kwh + $cost_wear_out_of_spec_gbp_per_kwh;
            if (is_null($optimum_cost_per_kwh)) {
                $optimum_charge_power_kw = $charge_power_kw;
                $optimum_cost_per_kwh    = $cost_per_kwh;
            }
            if ($cost_per_kwh < $optimum_cost_per_kwh) {
                $optimum_charge_power_kw = $charge_power_kw;
                $optimum_cost_per_kwh    = $cost_per_kwh;
            }
            $charge_power_kw += $charge_power_increment_kw;
        }
        $optimum_grid_power_kw = $net_load_kw + $optimum_charge_power_kw;
        return round(1000.0 * $optimum_grid_power_kw);
    }
}