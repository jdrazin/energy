<?php
declare(strict_types=1);
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time',    '36000');
ini_set('mysql.connect_timeout', '36000');

const     DEBUG                     = false,
          TEST_PROJECTION_ID        = null,
          FILENAME_TEST_PROJECTION  = 'config.json',
          ARGS                      = ['CRON' => 1],
          INITIALISE_ON_EXCEPTION   = true,
          EMAIL_ON_COMPLETION       = true,
          EMAIL_ON_ERROR            = false;

try {
    $debug = DEBUG  || !TEST_PROJECTION_ID;
    $pid_filename = Root::PATH_HOME . Root::FOLDER_PIDS . basename(__FILE__, '.php') . '.pid';
    if (!$debug) {
        if (file_exists($pid_filename)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents($pid_filename, getmypid());
        }
    }
    if (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron') { // handle as cron
        (new Energy(null))->processNextProjection(null);
    }
    elseif(TEST_PROJECTION_ID) {
        (new Energy(null))->processNextProjection(TEST_PROJECTION_ID);
    }
    else {
        $config = json_decode($config_json = file_get_contents(Root::PATH_PROJECT . Root::FOLDER_TEST . FILENAME_TEST_PROJECTION), true);
        $energy = new Energy($config);
        $energy->insertProjection(0, $config_json, '', 'DEBUG');
        $energy->processNextProjection(0);
    }
    if (!$debug) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    if (EMAIL_ON_ERROR) {
        (new SMTPEmail())->email(['subject'   => 'EnergyController: Error',
                                  'html'      => false,
                                  'bodyHTML'  => $message,
                                  'bodyAlt'   => strip_tags($message)]);
    }
    $root = new Root();
    $root->logDb('MESSAGE', $message, null, 'FATAL');
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