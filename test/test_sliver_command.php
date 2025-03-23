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

const SETTING = 'AC Charge Upper % Limit';

$givenergy = new GivEnergy();
//$givenergy->reset_inverter();
try {
    echo $givenergy->command('read',  SETTING, null, null, null) . PHP_EOL;
    $givenergy->command('write',  SETTING, 95, null, null);
    echo $givenergy->command('read',  SETTING, null, null, null) . PHP_EOL;
}
catch (GuzzleException|Exception $e) {

}
exit(0);

