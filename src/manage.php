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

const PID_FILENAME      = '/var/www/html/energy/manage.pid',
      USE_PID_SEMAPHORE = false,
      ARGS              = ['CRON' => 1],
      BLOCK_CRON        = false;

try {
   // (new GivEnergy())->initialise();
    if (!USE_PID_SEMAPHORE) {
        if (file_exists(PID_FILENAME)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents(PID_FILENAME, getmypid());
        }
    }
    if ((($cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) && !BLOCK_CRON) || !$cron) {
        (new Octopus())->traverseTariffs($cron);       // traverse all tariffs
    }
    if (!USE_PID_SEMAPHORE) {
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
    if (!USE_PID_SEMAPHORE) {
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
