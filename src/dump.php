<?php
declare(strict_types=1);
namespace Src;
use DateTime;
use DateInterval;

/*
   dumps house data to tan;e to "dump"
*/

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * "slither" is single battery charging control command, sent multiple times during each time slot
 */
error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const   DATETIME_START              = '2025-10-20 00:00:00',
        DATETIME_STOP               = '2026-10-20 00:00:00',
        INTERVAL_SECONDS            = 1800;

truncate( $mysqli = (new Root())->mysqli());
$datetime       = new DateTime(DATETIME_START);
$datetime_stop  = new DateTime(DATETIME_STOP);
$increment       = new DateInterval('PT' . INTERVAL_SECONDS . 'S');
$count = 0;
while ($datetime < $datetime_stop) {
    $datetime->add($increment);
}
exit;

function truncate($mysqli): void
{
    $mysqli->query('TRUNCATE `dump`');
}







