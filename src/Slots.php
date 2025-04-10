<?php
namespace Src;
use DateTime;
use Exception;
use DateMalformedStringException;

class Slots
{
    /**
     * @throws Exception
     */

    private DateTime $datetime;
    private string $until;

    /**
     * @throws DateMalformedStringException
     */
    public function __construct($datetime_earliest, $until)
    {
        // find beginning of earliest slot after $datetime_earliest
        $datetime_earliest = new Datetime($datetime_earliest);
        $hour = (int)$datetime_earliest->format('H');
        $minutes = (int)$datetime_earliest->format('i');
        $day_minutes = ($minutes + 60 * $hour);
        $day_slot = (int)($day_minutes / DbSlots::SLOT_DURATION_MINUTES);
        if ($day_minutes % DbSlots::SLOT_DURATION_MINUTES) {
            $day_slot++;
        }
        $datetime_earliest->setTime(0, 0);
        $datetime_earliest->modify('+' . DbSlots::SLOT_DURATION_MINUTES * $day_slot . ' minute');
        $this->datetime = $datetime_earliest;
        $this->until = $until;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function next_slot(): ?array
    {
        /*
         *  return next slot ending before $until, null otherwise
         */
        $start = $this->datetime->format(Root::MYSQL_FORMAT_DATETIME);
        $start_unix_timestamp = $this->datetime->getTimestamp();
        $this->datetime->modify('+' . DbSlots::SLOT_DURATION_MINUTES / 2 . ' minute');
        $mid = $this->datetime->format(Root::MYSQL_FORMAT_DATETIME);
        $this->datetime->modify('+' . DbSlots::SLOT_DURATION_MINUTES / 2 . ' minute');
        $stop = $this->datetime->format(Root::MYSQL_FORMAT_DATETIME);
        $stop_unix_timestamp = $this->datetime->getTimestamp();
        return ($stop < $this->until) ? ['start' => $start,
            'start_unix_timestamp' => $start_unix_timestamp,
            'mid' => $mid,
            'stop' => $stop,
            'stop_unix_timestamp' => $stop_unix_timestamp] : null;
    }
}