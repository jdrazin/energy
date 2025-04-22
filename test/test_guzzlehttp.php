<?php
	namespace Energy;

    error_reporting(E_ALL);
	require_once __DIR__ . '/ThermalTank.php';
	
	$unit = 'Test 10kWh battery';
	$volume_m3 = 0.2;
	$half_life_days = 1.0;
	$value_gbp = 1000.0;
	$temperature_ambient_c = 20.0;
	
	$hot_water_tank = new ThermalTank($unit, $volume_m3, $half_life_days, $temperature_ambient_c, $value_gbp);
	
	echo 'Charge...' . PHP_EOL;
	for ($hour=0; $hour < 24; $hour++) {
		$charge = $hot_water_tank->transfer_consume_j(3600*1000.0,
										  3600.0,
			                              $temperature_ambient_c,
										  null,
			                              false);
		echo $charge['energy_j'] . PHP_EOL;
	}
	echo 'Discharge...' . PHP_EOL;
	for ($hour=0; $hour < 24; $hour++) {
		$charge = $hot_water_tank->transfer_consume_j(-3600*1000.0,
			                              $temperature_ambient_c,
										  null,
			                              false);
		echo $charge['energy_j'] . PHP_EOL;
	}
	