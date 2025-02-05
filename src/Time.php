<?php

namespace Src;
use Energy;

class Time
{
    const SECONDS_PER_DAY = 60 * 60 * 24,
        SECONDS_PER_YEAR = 60 * 60 * 24 * 365.25;

    public DateTime $time, $time_start, $time_end;
    public DateInterval $timestep;

    public bool $year_end;
    public float $fraction_year, $fraction_day, $step_s;
    public string $timestamp;
    public int $step_count, $year0, $year;
    public array $units;

    /**
     * @throws Exception
     * @throws DateMalformedStringException
     * @throws DateMalformedIntervalStringException
     */
    public function __construct(string $time_start, int $project_duration_years, int $step_s, array $time_units)
    {
        $this->time_start = new DateTime($time_start);
        $this->time_end = clone $this->time_start;
        $this->time_end->modify('+' . $project_duration_years . ' year');
        $this->timestep = new DateInterval('PT' . $step_s . 'S');
        $this->step_count = 1;
        $this->year = 0;
        $this->step_s = (float)$step_s;
        $this->time = new DateTime($time_start);
        $this->units = $time_units;
        $this->units['YEAR'] = $project_duration_years + 1;
        $this->update();
        $this->year_end = true;
    }

    /**
     * @throws Exception
     */
    public function next_timestep(): bool
    {
        if ($this->time < $this->time_end) {
            $this->time->add($this->timestep);
            $this->step_count++;
            $this->update();
            return true;
        } else {
            return false;
        }
    }

    public function year(): string
    {
        return $this->time->format('Y');
    }

    public function month(): string
    {
        return $this->time->format('m');
    }

    public function day_of_month(): string
    {
        return $this->time->format('d');
    }

    /**
     * @throws Exception
     */
    private function update(): void
    {
        $this->timestamp = $this->time->format('Y-m-d H:i:s');
        $this->fraction_year();
        $this->fraction_day();
        $year = (int)date_diff($this->time_start, $this->time)->format('%R%y');
        $this->year_end = !($year == $this->year);
        $this->year = $year;
    }

    /**
     * @throws Exception
     */
    public function fraction_day(): void
    {
        $timestamp = $this->time->getTimestamp();
        $day_begin = new DateTime($this->year() . '-' . $this->month() . '-' . $this->day_of_month() . ' 00:00:00');
        $timestamp_day_begin = $day_begin->getTimestamp();
        $this->fraction_day = doubleval(($timestamp - $timestamp_day_begin) / self::SECONDS_PER_DAY);
    }

    /**
     * @throws Exception
     */
    public function fraction_year(): void
    {
        $timestamp = $this->time->getTimestamp();
        $year_begin = new DateTime($this->year() . '-01-01 00:00:00');
        $timestamp_year_begin = $year_begin->getTimestamp();
        $this->fraction_year = ($timestamp - $timestamp_year_begin) / self::SECONDS_PER_YEAR;
    }

    public function values(): array
    {
        return [
            'HOUR_OF_DAY' => (int)(Energy::HOURS_PER_DAY * $this->fraction_day),
            'MONTH_OF_YEAR' => (int)$this->time->format('F'),
            'DAY_OF_YEAR' => (int)$this->time->format('z'),
            'YEAR' => $this->year
        ];
    }

    public function year_end(): bool
    {
        if ($this->year_end) {
            $this->year_end = false;
            return true;
        } else {
            return false;
        }
    }
}