<?php // comme
	namespace Energy;
	use Slots;
    error_reporting(E_ALL);
    require_once __DIR__ . '/../Root.php';
    require_once __DIR__ . '/../Slots.php';
    require_once __DIR__ . '/../DbSlots.php';


    $slots = new Slots('2024-04-03 01:43:45');
    $stop  = '';

    do {
        try {
            $slot = $slots->next_slot('2025-01-16 11:18:45');
        } catch (\DateMalformedStringException $e) {

        }
        $stop_previous = $stop;
        $stop = $slot['stop'];
    } while ($stop);
    echo 'last slot stop: ' . $stop_previous . PHP_EOL;


	
	
	