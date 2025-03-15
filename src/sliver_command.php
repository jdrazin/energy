<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
require_once __DIR__ . '/../vendor/autoload.php';

/*
 * "slither" is single battery charging control command, sent multiple times during each time slot.
 *
 */

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const       SLIVER_DURATION_MINUTES = 1,
            CHARGE_POWER_LEVELS = 100;

try {
    $slivers                = new Sliver();
    $givenergy              = new GivEnergy();
    $energy_cost            = new EnergyCost(null, null);
    $slot_target_parameters = $slivers->slotTargetParameters();             // get slot target parameters
    $battery                = $givenergy->latest()['data']['battery'];      // get battery data
    $battery_level_kwh      = $battery['percent']*$energy_cost->batteryCapacityKwh/100.0;
    $net_load_kw            = ($battery['consumption']-$battery['solar']['power'])/1000.0;
    $charge_power_min_kw    = -$givenergy->battery['max_discharge_kw'];
    $charge_power_max_kw    =  $givenergy->battery['max_charge_kw'];
    $charge_power_increment_kw = ($charge_power_max_kw - $charge_power_min_kw)/CHARGE_POWER_LEVELS;
    $levels = [];
    $duration_hour = SLIVER_DURATION_MINUTES / 60.0;
    for ($charge_power_kw = $charge_power_min_kw; $charge_power_min_kw <= $charge_power_max_kw; $charge_power_kw += $charge_power_increment_kw) {
        $grid_power_kw            = $net_load_kw + $charge_power_kw;
        $grid_cost_per_kwh        = $grid_power_kw < 0.0 ? $slot_target_parameters['import_gbp_per_kwh'] : $slot_target_parameters['export_gbp_per_kwh'];
        $cost_wear_per_charge_kwh = $energy_cost->costWearOutOfSpecGbp($grid_power_kw, $charge_power_kw, $battery_level_kwh, $duration_hour);
        $levels[] = [];
    }





}
catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
} catch (GuzzleException $e) {
    exit(1);
}
exit(0);

