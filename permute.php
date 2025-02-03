<?php
declare(strict_types=1);
set_time_limit(1200);
ini_set('mysql.connect_timeout','1200');
ini_set('max_execution_time', '1200');

use GuzzleHttp\Exception\GuzzleException;

error_reporting(E_ALL);

require_once __DIR__ . '/Battery.php';
require_once __DIR__ . '/Boiler.php';
require_once __DIR__ . '/Climate.php';
require_once __DIR__ . '/Demand.php';
require_once __DIR__ . '/EmonCms.php';
require_once __DIR__ . "/Energy.php";
require_once __DIR__ . "/Powers.php";
require_once __DIR__ . "/GivEnergy.php";
require_once __DIR__ . '/HeatPump.php';
require_once __DIR__ . '/Inverter.php';
require_once __DIR__ . '/MetOffice.php';
require_once __DIR__ . '/Npv.php';
require_once __DIR__ . "/Octopus.php";
require_once __DIR__ . "/EnergyCost.php";
require_once __DIR__ . '/ParameterPermutations.php';
require_once __DIR__ . '/DbSlots.php';
require_once __DIR__ . '/Slots.php';
require_once __DIR__ . '/Solar.php';
require_once __DIR__ . '/SolarCollectors.php';
require_once __DIR__ . '/Solcast.php';
require_once __DIR__ . '/Supply.php';
require_once __DIR__ . '/ThermalInertia.php';
require_once __DIR__ . '/ThermalTank.php';
require_once __DIR__ . '/Time.php';

require 'vendor/autoload.php';

const DEBUG      = true;

try {
    if (!DEBUG) {
        if (file_exists(PID_FILENAME)) {
            echo 'Cannot start: semaphore exists';
            exit(1);
        }
        else {
            file_put_contents(PID_FILENAME, getmypid());
        }
    }

    $energy = new Energy();
    $energy->permute();

    if (!DEBUG) {
        if (!unlink(PID_FILENAME)) {
            throw new Exception('Cannot delete semaphore');
        }
    }
    exit(0);
}
catch (exception $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    if (!DEBUG) {
        echo 'Attempting to reset ...';
        (new GivEnergy())->initialise();
        echo ' done';
    }
    exit(1);
}
catch (GuzzleException $e) {
    $message = $e->getMessage();
    (new Root())->logDb('MESSAGE', $message, 'FATAL');
    echo $message . PHP_EOL;
    exit(1);
}
