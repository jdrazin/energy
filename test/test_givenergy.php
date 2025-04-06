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


try {
    $givenergy = new GivEnergy();
    $givenergy->get_local_timezone();
}
catch (GuzzleException|Exception $e) {
    exit(1);
}
exit(0);

