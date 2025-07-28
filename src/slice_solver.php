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

const   FOLDER_PID                  = '/var/www/html/energy/pids/',
        DEBUG                       = true,         // disable cron and semaphore single thread control
        DEBUG_MINIMISER             = false,
        DEBUG_MINIMISER_USE_FAIL    = false,        // otherwise use last OK
        ARGS                        = ['CRON' => 1],
        INITIALISE_ON_EXCEPTION     = false,
        EMAIL_NOTIFICATION_ON_ERROR = false,
        GIVENERGY_ENABLE            = true;

try {
    if (!DEBUG) {
        $pid_filename = FOLDER_PID . basename(__FILE__, '.php') . '.pid';
        if (file_exists($pid_filename)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents($pid_filename, getmypid());
        }
    }
    $cron = (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron');
    if (($cron && !DEBUG) || !$cron) {
        (new Slice())->command();
    }
    if (!DEBUG) {
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
    if (DEBUG) {
        echo $message . PHP_EOL;
    }
    if (INITIALISE_ON_EXCEPTION) {
        $root->logDb('MESSAGE', 'Attempting to initialise ...', null,'NOTICE');
        (new GivEnergy())->initialise(true); // set charge discharge blocks
        $root->logDb('MESSAGE', '... initialise done', null, 'NOTICE');
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message,  null, 'FATAL');
    if (DEBUG) {
        echo $message . PHP_EOL;
    }
    exit(1);
} catch (Exception $e) {
} catch (Exception $e) {
}