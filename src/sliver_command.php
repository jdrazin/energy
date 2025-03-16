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

const   PID_FOLDER                          = '/var/www/html/energy/',
        USE_PID_SEMAPHORE                   = false,
        USE_CRONTAB                         = false,
        ARGS                                = ['CRON' => 1],
        INITIALISE_ON_EXCEPTION             = true,
        EMAIL_NOTIFICATION_ON_ERROR         = true,
        ENABLE_SLOT_COMMANDS                = false,
        ACTIVE_TARIFF_COMBINATION_ONLY      = false;

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
        echo (new Sliver())->optimum_charge_w();
    }
    if (USE_PID_SEMAPHORE) {
        if (!unlink($pid_filename)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
}
catch (Exception $e) {
    echo $e->getMessage();
    exit(1);
} catch (GuzzleException $e) {
}
exit(0);

