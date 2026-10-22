<?php
declare(strict_types=1);
namespace Src;
use DateTime;
use DateInterval;

/*
   dumps house data to table "dump"
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

const   DATETIME_START              = '2025-10-21 00:15:00',
        DATETIME_STOP               = '2026-10-21 00:15:00',
        INTERVAL_SECONDS            = 1800,
        DESCRIPTORS = [
                        [
                            'type'      => 'ABSOLUTE',
                            'field'     => 'grid_w',
                            'entity'    => 'GRID_W'
                        ],
                        [
                            'type'      => 'RATE',
                            'field'     => 'battery_charge_w',
                            'entity'    => 'BATTERY_LEVEL_KWH',
                            'factor'    =>  3.6E6
                        ],
                        [
                            'type'      => 'ABSOLUTE',
                            'field'     => 'ev_charge_w',
                            'entity'    => 'LOAD_EV_W'
                        ],
                        [
                            'type'      => 'ABSOLUTE',
                            'field'     => 'load_w',
                            'entity'    => 'LOAD_HOUSE_W'
                        ],
                        [
                            'type'      => 'ABSOLUTE',
                            'field'     => 'solar_w',
                            'entity'    => 'SOLAR_W',
                        ],
                        [
                            'type'      => 'TARIFF',
                            'field'     => 'gbp_per_kwh',
                            'entity'    => 'SOLAR_W',
                        ]
                    ],
        DIRECTIONS = ['import', 'export'];

truncate( $mysqli = (new Root())->mysqli());
$datetime       = new DateTime(DATETIME_START);
$datetime_stop  = new DateTime(DATETIME_STOP);
$increment      = new DateInterval('PT' . INTERVAL_SECONDS . 'S');
while ($datetime < $datetime_stop) {
    $dt = $datetime->format('Y-m-d H:i:s');
    echo $dt . PHP_EOL;
    $datetime->add($increment);
    make_row($mysqli, $dt);
}
exit;

function truncate($mysqli): void {
    $mysqli->query('TRUNCATE `dump`');
}

function make_row($mysqli, $mysql_datetime): void
{
    $values = [];
    foreach (DESCRIPTORS as $descriptor) {
        $field  = $descriptor['field'];
        $entity = $descriptor['entity'];
        $factor = $descriptor['factor'] ?? 1.0;
        switch($descriptor['type']) {
            case 'ABSOLUTE': {
                $values[$field] = value_average($mysqli, $mysql_datetime, $entity);
                break;
            }
            case 'RATE': {
                $values[$field] = value_rate($mysqli, $mysql_datetime, $entity, $factor);
                break;
            }
            case 'TARIFF': {
                $tariffs = [];
                foreach (DIRECTIONS as $direction) {
                    $tariffs[$direction] = tariff_average($mysqli, $mysql_datetime, $direction);
                }
                $tariffs[$field] = $values['grid_w'] < 0.0 ? $tariffs['import'] : $tariffs['export'];
                break;
            }
            default: {
            }
        }
    }
    insert_row($mysqli, $mysql_datetime, $values);
}

function value_average($mysqli, $mysql_datetime, $entity):int {
    $interval_seconds = INTERVAL_SECONDS/2;
    $sql = "SELECT AVG(`value`) AS `average`
                   FROM  `values`
                   WHERE `entity` = '$entity' AND 
                         `datetime` BETWEEN '$mysql_datetime' - INTERVAL $interval_seconds SECOND AND '$mysql_datetime' + INTERVAL $interval_seconds SECOND AND
                         `type` = 'MEASURED'";
    $result = $mysqli->query($sql);
    $fetch_assoc = $result->fetch_assoc();
    $value_average = $fetch_assoc['average'];
    return intval(round(floatval($value_average)));
}

function tariff_average($mysqli, $mysql_datetime, $direction):float {
    $sql = "SELECT  AVG(`tr`.`rate`) AS `tariff`
              FROM `tariff_rates_$direction` `tr`
              INNER JOIN `tariff_combinations` `tc` ON `tc`.`$direction` = `tr`.`tariff`
              WHERE '$mysql_datetime' BETWEEN `start` AND `stop` AND
                    `tr`.`per` = 'KWH' AND
                    `tr`.`status` IN('CURRENT', 'TO_DROP') AND 
                    `tc`.`active`";
    $result = $mysqli->query($sql);
    $fetch_assoc = $result->fetch_assoc();
    $value_average = $fetch_assoc['tariff'];
    return floatval($value_average);
}

function value_rate($mysqli, $mysql_datetime, $entity, $factor):int {
    $signs = ['-', '+'];
    $interval_half = INTERVAL_SECONDS/2;
    $upper_lower_datetime = [];
    foreach ($signs as $sign) {
        $sql = "SELECT   `datetime`
                   FROM  `values`
                   WHERE `entity` = '$entity' AND 
                         `type` = 'MEASURED'
                   ORDER BY ABS(TIMESTAMPDIFF(SECOND, '$mysql_datetime' $sign INTERVAL $interval_half SECOND, `datetime`))
                   LIMIT 1";
        $result = $mysqli->query($sql);
        $fetch_assoc        = $result->fetch_assoc();
        $datetime           = $fetch_assoc['datetime'];
        $value              = value($mysqli, $datetime, $entity);
        $upper_lower_datetime[$sign] = [
            'datetime'           => $datetime,
            'value'              => $value
        ];
    }
    // calculate rate
    $diff_seconds = strtotime($upper_lower_datetime['+']['datetime']) - strtotime($upper_lower_datetime['-']['datetime']);
    $va   = $upper_lower_datetime['-']['value'];
    $vb   = $upper_lower_datetime['+']['value'];
    if (($rate = $vb - $va) && $diff_seconds) {
        $rate /= $diff_seconds;
    }
    return intval(round($factor * $rate));
}

function value($mysqli, $mysql_datetime, $entity): float {
    $sql = "SELECT   `value`
               FROM  `values`
               WHERE `entity` = '$entity' AND 
                     `datetime` = '$mysql_datetime'";
    $result = $mysqli->query($sql);
    return floatval($result->fetch_assoc()['value']);
}

function insert_row($mysqli, $mysql_datetime, $values): void
{
    $sql = 'INSERT INTO `dump` (`datetime`,';
    foreach ($values as $field => $value) {
        $sql .= '`' . $field . '`,';
    }
    $sql = rtrim($sql, ',') . ') VALUES (\'' . $mysql_datetime . '\',';
    foreach ($values as $field => $value) {
        switch ($field) {
            case 'gbp_per_kwh': {
                $sql .= round($value, 6) . ',';
                break;
            }
            default: {
                $sql .= intval(round($value)) . ',';
            }
        }
    }
    $sql = rtrim($sql, ',') . ')';
    $result = $mysqli->query($sql);
    $mysqli->commit();
}




