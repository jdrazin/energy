<?php
declare(strict_types=1);
namespace Src;
use Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * log message to db
 *
 */
error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const FOLDER_PID = '/var/www/html/energy/pids/',
      ARGS       = [
                    'MESSAGE' => 1,
                    'URGENCY' => 2
                    ],
      DEBUG      = false;  // disable cron and semaphore single thread control

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
if (($message = trim($argv[ARGS['MESSAGE']] ?? '')) && !DEBUG) {
    $urgency = strtoupper(trim($argv[ARGS['URGENCY']]  ?? 'FATAL'));
    $root = new Root();
    $root->logDb('MESSAGE', $message, null,$urgency);
    echo 'Logged to db: ' . $message . PHP_EOL;
}
if (!DEBUG) {
    if (!unlink($pid_filename)) {
        throw new Exception('Cannot delete semaphore');
    }
}
echo 'Done';
exit(0);


