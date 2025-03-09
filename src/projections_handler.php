<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const PID_FOLDER                        = '/var/www/html/energy/',
      USE_PID_SEMAPHORE                 = false,
      USE_CRONTAB                       = true,
      ARGS                              = ['CRON' => 1],
      INITIALISE_ON_EXCEPTION           = true,
      EMAIL_NOTIFICATION_ON_ERROR       = true,
      REPLACE_WITH_STUB                 = false,
      ENABLE_SLOT_COMMANDS              = true,
      ACTIVE_TARIFF_COMBINATION_ONLY    = false;

try {
    $pid_filename = PID_FOLDER . basename(__FILE__, '.php') . '.pid';
    if (USE_PID_SEMAPHORE) {
        if (file_exists($pid_filename)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents($pid_filename, getmypid());
        }
    }
    if ((($cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) && USE_CRONTAB) || !$cron) {
        if (REPLACE_WITH_STUB) {

        }
        else {
           (new Energy(null))->processNextProjection();
        }
    }
    if (USE_PID_SEMAPHORE) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_NOTIFICATION_ON_ERROR) {
        (new SMTPEmail())->email(['subject'   => 'EnergyController: Error',
                                  'html'      => false,
                                  'bodyHTML'  => $message,
                                  'bodyAlt'   => strip_tags($message)]);
    }
    $root = new Root();
    $root->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    if (INITIALISE_ON_EXCEPTION) {
        $root->logDb('MESSAGE', 'Attempting to initialise ...', 'NOTICE');
        (new GivEnergy())->reset_inverter();
        $root->logDb('MESSAGE', '... initialise done', 'NOTICE');
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}