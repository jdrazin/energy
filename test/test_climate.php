<?php
	namespace Energy;

    require_once __DIR__ . '/Climate.php';
	
	$unit = 'Test climate temperature';

	$climate = new Climate();
	
	$a = 0;
	$b = 1;
	$c = $b / $a;
	
	$fraction_years = [
					 	'1 Jan' => 0.0,
	                    '1 Apr' => 0.25,
	                    '1 Jul' => 0.5,
	                    '1 Oct' => 0.75
	                   ];
	
	$interval_hours = 1;
	$number_intervals = 24 / $interval_hours ;
	
	foreach ($fraction_years as $date => $fraction_year) {
		echo $date . ' >>>' . PHP_EOL;
		for ($interval_count = 0; $interval_count < $number_intervals; $interval_count++) {
			$hour = $interval_count*$interval_hours;
			$fraction_day = (float) $hour / 24.0;
			echo $hour . '00hrs : ' . round((new \Energy\Climate)->temperature_fraction($fraction_year, $fraction_day), 1) . PHP_EOL;
		}
		echo PHP_EOL;
	}
	
	