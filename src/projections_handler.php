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

const     PID_FOLDER                        = '/var/www/html/energy/',
          CONFIG_JSON                       = 'config.json',
          JSON_PROJECTION_ID                = 0,
          TEST_PROJECTION_ID                = 2628906042,
          USE_PID_SEMAPHORE                 = false,
          USE_CRONTAB                       = false,
          ARGS                              = ['CRON' => 1],
          INITIALISE_ON_EXCEPTION           = true,
          EMAIL_NOTIFICATION_ON_ERROR       = false,
          MODE                              = 'id';

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
        switch (MODE) {
            case 'json': {
                (new Energy(null))->deleteProjection(JSON_PROJECTION_ID);
                $config_json = file_get_contents(PID_FOLDER . CONFIG_JSON);
                (new Energy(null))->permute(JSON_PROJECTION_ID, json_decode($config_json, true));
                break;
            }
            case 'id': {
                (new Energy(null))->processNextProjection(TEST_PROJECTION_ID);
                break;
            }
            case 'cron': {
                (new Energy(null))->processNextProjection(null);
                break;
            }
            default: {

            }
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