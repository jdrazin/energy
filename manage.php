<?php
declare(strict_types=1);
set_time_limit(36000);
ini_set('mysql.connect_timeout','36000');
ini_set('max_execution_time', '36000');

use GuzzleHttp\Exception\GuzzleException;

error_reporting(E_ALL);

require_once __DIR__ . '/Battery.php';
require_once __DIR__ . '/Boiler.php';
require_once __DIR__ . '/Climate.php';
require_once __DIR__ . '/Demand.php';
require_once __DIR__ . '/EmonCms.php';
require_once __DIR__ . "/Energy.php";
require_once __DIR__ . "/Powers.php";
require_once __DIR__ . "/GivEnergy.php";
require_once __DIR__ . '/HeatPump.php';
require_once __DIR__ . '/Inverter.php';
require_once __DIR__ . '/MetOffice.php';
require_once __DIR__ . '/Npv.php';
require_once __DIR__ . "/Octopus.php";
require_once __DIR__ . "/EnergyCost.php";
require_once __DIR__ . '/ParameterPermutations.php';
require_once __DIR__ . '/DbSlots.php';
require_once __DIR__ . '/Solar.php';
require_once __DIR__ . '/SolarCollectors.php';
require_once __DIR__ . '/Slots.php';
require_once __DIR__ . '/Solcast.php';
require_once __DIR__ . '/Supply.php';
require_once __DIR__ . '/ThermalInertia.php';
require_once __DIR__ . '/ThermalTank.php';
require_once __DIR__ . '/Time.php';

require 'vendor/autoload.php';

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

const PID_FILENAME = '/var/www/html/energy/manage.pid',
      ARGS       = ['CRON' => 1],
      BLOCK_CRON = false,
      DEBUG      = true;

try {
   // (new GivEnergy())->initialise();
    if (!DEBUG) {
        if (file_exists(PID_FILENAME)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents(PID_FILENAME, getmypid());
        }
    }
    if ((($cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) && !BLOCK_CRON) || !$cron) {
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'START', null, 'NOTICE');
        if (!Root::DEBUG) {
            (new Solcast())->getSolarActualForecast();       // solar actuals & forecasts > 'powers'
        }
        (new Octopus())->traverseTariffCombinations();       // traverse all tariffs
        (new Root())->logDb(($cron ? 'CRON_' : '') . 'STOP', null, 'NOTICE');
    }
    if (!DEBUG) {
        if (!unlink(PID_FILENAME)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    if (!DEBUG) {
        echo 'Attempting to reset ...';
        (new GivEnergy())->initialise();
        echo ' done';
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}
