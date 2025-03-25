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

const SLIVER_COMMAND = [
                            'mode'                  => 'DISCHARGE',
                            'start'                 => '09:55',
                            'stop'                  => '10:56',
                            'abs_charge_power_w'    => 2000,
                            'target_level_percent'  => 40,
                        ];

try {
    $givenergy = new GivEnergy();
    $givenergy->control(SLIVER_COMMAND);
}
catch (GuzzleException|Exception $e) {
    exit(1);
}
exit(0);

