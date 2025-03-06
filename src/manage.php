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

const PID_FILENAME                      = '/var/www/html/energy/manage.pid',
      USE_PID_SEMAPHORE                 = false,
      BLOCK_CRON                        = true,
      INITIALISE_ON_EXCEPTION           = false,
      EMAIL_NOTIFICATION                = true,
      ARGS                              = ['CRON' => 1],
      USE_STUB                          = false,
      DISABLE_COUNTDOWN                 = false,
      ENABLE_SLOT_COMMANDS              = false,
      ACTIVE_TARIFF_COMBINATION_ONLY    = true,
      TEST_SLOT_COMMAND         = [
                                    'start'                 => '',
                                    'stop'                  => '',
                                    'mode'                  => '',
                                    'abs_charge_power_w'    => 3000,
                                    'target_level_percent'  => 80
                                  ];

try {
    if (USE_PID_SEMAPHORE) {
        if (file_exists(PID_FILENAME)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents(PID_FILENAME, getmypid());
        }
    }
    if ((($cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) && !BLOCK_CRON) || !$cron) {
        if (USE_STUB) {
        //    (new GivEnergy())->reset_inverter();
        //    (new GivEnergy())->control(TEST_SLOT_COMMAND);
        }
        else {
           (new Octopus())->traverseTariffs($cron);       // traverse all tariffs
        }
    }
    if (USE_PID_SEMAPHORE) {
        if (!unlink(PID_FILENAME)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_NOTIFICATION) {
        (new SMTPEmail())->email(['subject'   => 'EnergyController: Error',
                                  'html'      => false,
                                  'bodyHTML'  => '',
                                  'bodyAlt'   => $message]);
    }
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    if (INITIALISE_ON_EXCEPTION) {
        (new Root())->logDb('MESSAGE', 'Attempting to initialise ...', 'INFO');
        (new GivEnergy())->reset_inverter();
        (new Root())->logDb('MESSAGE', '... initialise done', 'INFO');
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}