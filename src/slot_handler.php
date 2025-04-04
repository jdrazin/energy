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

const PID_FOLDER                     = '/var/www/html/energy/',
      DEBUG                          = true,  // disable cron and semaphore single thread control
      ARGS                           = ['CRON' => 1],
      INITIALISE_ON_EXCEPTION        = false,
      EMAIL_NOTIFICATION_ON_ERROR    = false,
      REPLACE_WITH_STUB              = false,
      ACTIVE_TARIFF_COMBINATION_ONLY = true;

try {
    $pid_filename = PID_FOLDER . basename(__FILE__, '.php') . '.pid';
    if (!DEBUG) {
        if (file_exists($pid_filename)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents($pid_filename, getmypid());
        }
    }
    if ((($cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) && !DEBUG) || !$cron) {
        if (REPLACE_WITH_STUB) {
            $octopus = (new Octopus());
            $octopus->makeActiveTariffCombinationDbSlotsLast24hrs();
            $octopus->slots_make_cubic_splines();
        }
        else {
           (new Octopus())->traverseTariffs($cron);       // traverse all tariffs
        }
    }
    if (!DEBUG) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    echo 'Done';
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_NOTIFICATION_ON_ERROR) {
        (new SMTPEmail())->email(['subject'  => 'EnergyController: Error',
                                  'html'     => false,
                                  'bodyHTML' => $message,
                                  'bodyAlt'  => strip_tags($message)]);
    }
    $root = new Root();
    $root->logDb('MESSAGE', $message,  null, 'FATAL');
    echo $message . PHP_EOL;
    if (INITIALISE_ON_EXCEPTION) {
        $root->logDb('MESSAGE', 'Attempting to initialise ...', 'NOTICE');
        (new GivEnergy())->reset_inverter(true);              // set charge discharge blocks
        $root->logDb('MESSAGE', '... initialise done', 'NOTICE');
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, null, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}