<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * woken by crontab every 5 mins
 *
 * Steps:
 *
 * + energy model: get current solar generation
 *
 * + weather API: get present to next 24 hours
 *   - temperature (celsius)         - Tf
 *
 * + solcast API: get solar forecase for next 24 hours
 *   - cloud cover                   - cf
 *
 * + GivEnergy AIO: log powers
 *   - grid (kW)                     - g
 *   - solar generation (kW)         - s
 *   - load (kW)                     - l
 *   - battery charge level (kWh)    - B
 *
 * + energy model: get current solar generation
 *
 * + openenergymonitor: log last time slot
 *   - heating power (kW)            - h
 *   - outside temperature (celsius) - Ta
 *
 * + calibrate solar generation model
 *
 */

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const DISABLE_COUNTDOWN = true;
const TEST_SLOT_COMMAND = [
                            'start'                 => '18:00',
                            'stop'                  => '19:00',
                            'mode'                  => 'ECO',
                            'abs_charge_power_w'    => 6000,
                            'target_level_percent'  => 67
                           ];

$givenergy = new GivEnergy();
//$givenergy->reset_inverter();
try {
    $givenergy->control(TEST_SLOT_COMMAND);
}
catch (GuzzleException|Exception $e) {

}
exit(0);

