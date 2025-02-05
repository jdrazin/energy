<?php
	namespace Energy;

    error_reporting(E_ALL);
	require_once __DIR__ . '/Time.php';
	require_once __DIR__ . '/HeatPump.php';
	
	const   PARAMETERS_JSON_PATHNAME = '/var/www/html/development/energy/config.json',
			JSON_MAX_DEPTH           = 10;
	
	$time_units = ['HOUR_OF_DAY'   => 24,
	               'MONTH_OF_YEAR' => 12,
	               'DAY_OF_YEAR'   => 366];
	
	$config = json_decode(file_get_contents(PARAMETERS_JSON_PATHNAME), true, JSON_MAX_DEPTH);
	
	$time      = new Time('2024-01-01 00:00:00', 1, 864, $time_units);
	$npv       = [
					'name'              => 'npv',
                    'discount_rate_pa'  => 0.04
                 ];
	$heat_pump = new HeatPump($config['heat_pump'], $time, $npv);
	$cop = $heat_pump->cop(11);
	exit();