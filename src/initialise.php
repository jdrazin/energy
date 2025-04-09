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

const PID_FOLDER                     = '/var/www/html/energy/pids/',
      DEBUG                          = false;  // disable cron and semaphore single thread control

try {
    if (!DEBUG) {
        $pid_filename = PID_FOLDER . basename(__FILE__, '.php') . '.pid';
        if (file_exists($pid_filename)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents($pid_filename, getmypid());
        }
    }
    $root = new Root();
    $root->logDb('MESSAGE', 'Initialising ...', 'NOTICE');
    (new GivEnergy())->initialise(true);              // set charge discharge blocks
    $root->logDb('MESSAGE', 'Initialise done', 'NOTICE');

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
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, null, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}