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
    $data                   = $givenergy->latest();                         // get battery data
    $battery_level_kwh      = $data['battery']['percent']*$energy_cost->batteryCapacityKwh/100.0;
    $charge_power_min_kw    = -$givenergy->battery['max_discharge_kw'];
    $charge_power_max_kw    =  $givenergy->battery['max_charge_kw'];
    for ($charge_level = 0; $charge_level <= CHARGE_POWER_LEVELS; $charge_level++) {
        $battery_charge_kw = $charge_power_min_kw + ($charge_power_max_kw - $charge_power_min_kw) * $charge_level/CHARGE_POWER_LEVELS;
        $cost_wear_per_charge_kwh = $energy_cost->costWearOutOfSpecGbp($grid_power_slot_kw, $battery_charge_kw, $battery_level_kwh, SLIVER_DURATION_MINUTES / 60.0);
    }
    $wear_cost_per_kwh = // get wear charge cost per kwh for battery

    $net_load_w = $battery['consumption']-$battery['solar']['power'];


}
catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
} catch (GuzzleException $e) {
    exit(1);
}
exit(0);

