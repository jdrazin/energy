<?php
declare(strict_types=1);

use Src\GivEnergy;

set_time_limit(36000);
error_reporting(E_ALL);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');

require_once 'vendor/autoload.php';

$givenergy = new GivEnergy();
// $energy = new \Energy(null);
$a = 0;


