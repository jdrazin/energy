<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * "slither" is single battery charging control command, sent multiple times during each time slot
 */
error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const   PID_FOLDER                  = '/var/www/html/energy/',
        USE_PID_SEMAPHORE           = true,
        USE_CRONTAB                 = false,
        ARGS                        = ['CRON' => 1],
        INITIALISE_ON_EXCEPTION     = false,
        EMAIL_NOTIFICATION_ON_ERROR = false,
        ENABLE_SLIVER_COMMAND       = false;

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
        echo (new Sliver())->charge_w() . 'W';
    }
    if (USE_PID_SEMAPHORE) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_NOTIFICATION_ON_ERROR) {
        (new SMTPEmail())->email([  'subject'  => 'EnergyController: Error',
                                    'html'     => false,
                                    'bodyHTML' => $message,
                                    'bodyAlt'  => strip_tags($message)]);
    }
    $root = new Root();
    $root->logDb('MESSAGE', $message, null, 'FATAL');
    echo $message . PHP_EOL;
    if (INITIALISE_ON_EXCEPTION) {
        $root->logDb('MESSAGE', 'Attempting to initialise ...', null,'NOTICE');
        (new GivEnergy())->reset_inverter(true); // set charge discharge blocks
        $root->logDb('MESSAGE', '... initialise done', null, 'NOTICE');
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message,  null, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}