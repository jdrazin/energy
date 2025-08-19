<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * initialise controller
 *
 */

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const FOLDER_PID                     = '/var/www/html/energy/pids/',
      DEBUG                          = false;  // disable cron and semaphore single thread control

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
    $root = new Root();
    $root->logDb('MESSAGE', 'Initialising ...', null,'NOTICE');
    (new GivEnergy())->initialise(true);              // set charge discharge blocks
    $root->logDb('MESSAGE', 'Initialise done', null,'NOTICE');

    if (!DEBUG) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    if (DEBUG) {
        echo 'Done';
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_ON_ERROR) {
        (new SMTPEmail())->email(['subject'  => 'EnergyController: Error',
                                  'html'     => false,
                                  'bodyHTML' => $message,
                                  'bodyAlt'  => strip_tags($message)]);
    }
    $root = new Root();
    $root->logDb('MESSAGE', $message,  null, 'FATAL');
    if (DEBUG) {
        echo $message . PHP_EOL;
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, null, 'FATAL');
    if (DEBUG) {
        echo $message . PHP_EOL;
    }
    exit(1);
}