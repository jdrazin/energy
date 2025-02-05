<?php //
	namespace Energy;

    error_reporting(E_ALL);
	require_once __DIR__ . "/Battery.php";
	
	$unit = 'Test 10kWh battery';
	$capacity_effective_kwh = 9.0;
	$efficiency = 0.9;
	$max_put_kw = 4.5;
	$max_get_kw = 4.5;
	$value_gbp = 7000.0;
	
	$battery = new Battery($unit, $capacity_effective_kwh, $efficiency, $max_put_kw, $max_get_kw, $value_gbp);
	
	echo 'Charge...' . PHP_EOL;
	for ($hour=0; $hour < 24; $hour++) {
		echo $battery->transfer_consume_j(3600*1000.0, 3600.0)['energy_j'] . PHP_EOL;
	}
	echo 'Discharge...' . PHP_EOL;
	for ($hour=0; $hour < 24; $hour++) {
		echo $battery->transfer_consume_j(-3600*1000.0, 3600.0)['energy_j'] . PHP_EOL;
	}
	
	
	