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

const     DEBUG                       = true,
          FOLDER_PID                  = '/var/www/html/energy/pids/',
          FOLDER_TEST                 = '/var/www/html/energy/test/',
          CONFIG_JSON                 = 'test.config.json',
          JSON_PROJECTION_ID          = 0,
          TEST_PROJECTION_ID          = 2867885348,
          ARGS                        = ['CRON' => 1],
          INITIALISE_ON_EXCEPTION     = true,
          EMAIL_NOTIFICATION_ON_ERROR = false,
          MODE                        = 'cron';

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
    if ((MODE == 'cron') && (strtolower(trim($argv[ARGS['CRON']] ?? '')) == 'cron')) { // handle as cron
        (new Energy(null))->processNextProjection(null);
    }
    else {
        switch (MODE) {
            case 'json': {
                (new Energy(null))->deleteProjection(JSON_PROJECTION_ID);
                $config_json = file_get_contents(FOLDER_TEST . CONFIG_JSON);
                (new Energy(null))->combine(JSON_PROJECTION_ID, json_decode($config_json, true));
                break;
            }
            case 'id': {
                (new Energy(null))->processNextProjection(TEST_PROJECTION_ID);
                break;
            }
            default: {

            }
        }
    }
    if (!DEBUG) {
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
    $root->logDb('MESSAGE', $message, null, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, null, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}