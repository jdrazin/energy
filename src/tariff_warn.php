<?php
declare(strict_types=1);
namespace Src;
use Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * daily e-mail warning when current tariff sub-optimal
 */

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const FOLDER_PID = '/var/www/html/energy/pids/',
      DEBUG      = false,  // disable cron and semaphore single thread control
      ARGS       = ['CRON' => 1];

try {
    $pid_filename = FOLDER_PID . basename(__FILE__, '.php') . '.pid';
    if (!DEBUG) {
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
        if ($message = (new Energy(null))->tariffWarn()) {   // warn if better tariff
            (new SMTPEmail())->email([  'subject'  => 'EnergyController: Error',
                                        'html'     => false,
                                        'bodyHTML' => $message,
                                        'bodyAlt'  => strip_tags($message)]);
        }
    }
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
    $root = new Root();
    $root->logDb('MESSAGE', $message,  null, 'FATAL');
    if (DEBUG) {
        echo $message . PHP_EOL;
    }
    exit(1);
}