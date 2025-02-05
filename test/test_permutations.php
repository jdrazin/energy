<?php
	namespace Energy;

    error_reporting(E_ALL);
	require_once __DIR__ . '/HeatPump.php';
	
	const   PARAMETERS_JSON_PATHNAME = '/var/www/html/development/energy/config.json',
			JSON_MAX_DEPTH           = 10;
	$config = json_decode(file_get_contents(PARAMETERS_JSON_PATHNAME), true, JSON_MAX_DEPTH);
	$permutations = new ParameterPermutations($config);
	$parameter_permutations = $permutations->permutations;
	exit();