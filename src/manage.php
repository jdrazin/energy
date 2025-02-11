<?php
declare(strict_types=1);
namespace Src;
require_once __DIR__ . '/../vendor/autoload.php';
use Exception;
use GuzzleHttp\Exception\GuzzleException;

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
