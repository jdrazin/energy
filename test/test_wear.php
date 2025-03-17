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

const  GRID_KW              = 6.0,
       CHARGE_KW            = 3.0,
       BATTERY_LEVEL_KWH    = 9.0,
       DURATION_HOUR        = 1.0/60.0;

$energy_cost = new EnergyCost(null, null);
$wear_gbp_per_hour = $energy_cost->wearGbpPerHour(GRID_KW, CHARGE_KW, BATTERY_LEVEL_KWH, DURATION_HOUR);
exit(0);


	
	
	