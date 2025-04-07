<?php

$opcache =  opcache_get_status();
var_export($opcache['jit']);
echo PHP_EOL;

const ITERATIONS = 100000000;
$time_start = microtime(true);
$a = array();
for($i=0; $i < ITERATIONS; $i++) {
    $a[] = $i;
}
$time_end   = microtime(true);
$time_diff  = $time_end-$time_start;
echo 'elapsed: ' . round($time_diff, 3) . 's' . PHP_EOL;



