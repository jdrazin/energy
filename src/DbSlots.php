<?php
namespace Src;
use DateMalformedStringException;
use DateTime;
use Exception;

class DbSlots extends Root
{
    public const int SLOTS_PER_DAY = 48,
        SLOT_DURATION_MIN = 30;

    public array $previous_slot = [], $slots = [], $tariff_combination = [];

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $sql = 'DELETE `slots` FROM `slots`
                  INNER JOIN `tariff_combinations` `tc` ON `tc`.`id` = `slots`.`tariff_combination`
                  WHERE NOT `slots`.`final` OR
                        (`slots`.`final` AND NOT IFNULL(`tc`.`active`, FALSE) AND `slots`.`slot` >= 0)';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $slot_time = $this->dayFirstSlotStart();
        $half_slot_duration_min = self::SLOT_DURATION_MIN / 2;
        $slot_time->modify(-2 * self::SLOT_DURATION_MIN . ' minute');     // back up two slots to beginning of last slot
        $this->previous_slot['start'] = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
        $this->previous_slot['start_unix_timestamp'] = $slot_time->getTimestamp();
        $slot_time->modify($half_slot_duration_min . ' minute');      // middle of last slot
        $this->previous_slot['mid'] = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
        $this->previous_slot['mid_unix_timestamp'] = $slot_time->getTimestamp();
        $slot_time->modify($half_slot_duration_min . ' minute');      // end of last slot
        $this->previous_slot['stop'] = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
        $this->previous_slot['stop_unix_timestamp'] = $slot_time->getTimestamp();
        $slot_time->modify(self::SLOT_DURATION_MIN . ' minute');      // skip through current slot to beginning of first slot
        for ($slot = 0; $slot < self::SLOTS_PER_DAY; $slot++) {
            $start = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
            $slot_time->modify($half_slot_duration_min . ' minute');  //  add half slot duration
            $mid = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
            $slot_time->modify($half_slot_duration_min . ' minute');  //  add half slot duration
            $stop = $slot_time->format(Root::MYSQL_FORMAT_DATETIME);
            $this->slots[$slot] = ['start' => $start, 'mid' => $mid, 'stop' => $stop];
        }
    }

    /**
     * @throws DateMalformedStringException|Exception
     */
    public function makeDbSlotsNext24hrs($tariff_combination): void
    {
        $this->tariff_combination = $tariff_combination;
        $tariff_combination_id = $this->tariff_combination['id'];
        $sql = 'INSERT INTO `slots` (`tariff_combination`, `slot`, `start`, `stop`, `final`)
                             VALUES (?,                    ?,      ?,       ?     , FALSE)
                    ON DUPLICATE KEY UPDATE `slot`                   = ?,
                                            `load_house_kw`          = NULL,
                                            `grid_kw`                = NULL,
                                            `battery_charge_kw`      = NULL,
                                            `battery_level_stop_kwh` = NULL,
                                            `import_gbp_per_kwh`     = NULL,
                                            `export_gbp_per_kwh`     = NULL,
                                            `import_gbp_per_day`     = NULL,
                                            `export_gbp_per_day`     = NULL,
                                            `solar_gross_kw`         = NULL,
                                            `load_non_heating_kw`    = NULL,
                                            `load_heating_kw`        = NULL,
                                            `final`                  = FALSE';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('iisss', $tariff_combination_id, $slot, $start, $stop, $slot)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        foreach ($this->slots as $slot => $v) {
            $start = $v['start'];
            $stop = $v['stop'];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws DateMalformedStringException
     */
    public function dayFirstSlotStart(): DateTime
    {                            // return DateTime object for beginning of first day slot
        $now = new DateTime();                                                 // returns last 48x 30 minute slots, with last slow ending on the last half hour
        $slot_time = clone $now;                                               // align slot time to end of last slot
        $slot_time->setTime($slot_time->format('G'), 0);           // set to current hour, 0 minute, 0 second
        $slot_time->modify(self::SLOT_DURATION_MIN . ' minute');
        if ($now < $slot_time) {                                               // if now in 2nd half-hour then slot ends on half hour
            $slot_time->modify(-self::SLOT_DURATION_MIN . ' minute');   // otherwise wind back
        }
        $slot_time->modify(self::SLOT_DURATION_MIN . ' minute');        // first slot begins with beginning of next half hour slot
        return $slot_time;
    }

    /**
     * @throws Exception
     */
    public function getDbNextDaySlots($tariff_combination): array
    {  // returns slot times
        $sql = 'SELECT  `id`, `slot`, `start`, `stop`, `start` + INTERVAL CEIL(TIMESTAMPDIFF(SECOND, `start`, `stop`)/2.0) SECOND `mid`
                  FROM  `slots`
                  WHERE `tariff_combination` = ? AND
                        `slot` IS NOT NULL AND
                        NOT `final`
                  ORDER BY `slot` ASC';
        $tariff_combination_id = $tariff_combination['id'];
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->bind_result($id, $key, $start, $stop, $mid) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$key] = [
                'id' => $id,
                'start' => $start,
                'mid' => $mid,
                'stop' => $stop
            ];
        }
        return $slots;
    }
}