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

try {
    $slivers = new Sliver();
    $givenergy = new GivEnergy();
    $slot_target_parameters = $slivers->slotTargetParameters();        // get slither target parameters
    $battery_now = $givenergy->batteryNow();                         // get current battery charge state
    // get instantaneous net_load = house_load - solar_generation

}
catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
}
exit(0);

