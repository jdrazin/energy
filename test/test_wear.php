<?php
declare(strict_types=1);
namespace Src;

require_once __DIR__ . '/../vendor/autoload.php';

/*
* "slither" is single battery charging control command, sent multiple times during each time slot
*/
error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

$energy_cost = new EnergyCost(null, null);
exit(0);


	
	
	