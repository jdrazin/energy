<?php

namespace Src;
use DateInterval;
use DateMalformedIntervalStringException;
use DateMalformedStringException;
use DateTime;
use Exception;

class Time {
    const string COMPONENT_NAME = 'time';
    const array CHECKS = [
                                'max_project_duration_years' => [
                                                                    'range' => [1, 25 ]
                                                                ],
                                'step_seconds'               => [
                                                                    'range' => [60, 3600 ]
                                                                ],
                                'discount_rate_pa'           => [
                                                                    'range' => [0.0, 1.0]
                                                                ]
                            ];

    const SECONDS_PER_DAY  = 60 * 60 * 24,
          SECONDS_PER_YEAR = 60 * 60 * 24 * 365.25;

    public DateTime $time, $time_start, $time_end;
    public DateInterval $timestep;

    public bool $year_end;
    public float $fraction_year, $fraction_day, $step_s, $discount_rate_pa;
    public string $timestamp;
    public int $step_count, $year;
    public array $units;

    /**
     * @throws Exception
     * @throws DateMalformedStringException
     * @throws DateMalformedIntervalStringException
     * @throws \DateMalformedStringException
     * @throws \DateMalformedIntervalStringException
     */
 // public function __construct(string $time_start, int $max_project_duration_years, int $step_s, array $time_units)
    public function __construct($check, $config, $units, $single_year) {
        $suffixes = [];
        $max_project_duration_years = $check->checkValue($config,self::COMPONENT_NAME,  $suffixes, 'max_project_duration_years', self::CHECKS);
        $step_seconds               = $check->checkValue($config, self::COMPONENT_NAME, $suffixes, 'step_seconds',              self::CHECKS);
        $this->discount_rate_pa     = $check->checkValue($config, self::COMPONENT_NAME, $suffixes, 'discount_rate_pa',           self::CHECKS);;
        $this->time_start = new DateTime('2025-01-01 00:00:00');
        $this->time_end   = clone $this->time_start;
        $this->time_end->modify('+' . $max_project_duration_years . ' year');
        $this->time       = clone $this->time_start;
        $this->timestep   = new DateInterval('PT' . $step_seconds . 'S');
        $this->step_s        = $step_seconds;
        $this->step_count    = 1;
        $this->year          = 0;
        $this->units         = $units;
        $this->units['YEAR'] = ($single_year ? 1 : $max_project_duration_years) + 1;
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
     * @throws \DateMalformedStringException
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
     * @throws \DateMalformedStringException
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
            'HOUR_OF_DAY'   => (int)(\Src\Energy::HOURS_PER_DAY * $this->fraction_day),
            'MONTH_OF_YEAR' => (int)$this->time->format('F'),
            'DAY_OF_YEAR'   => (int)$this->time->format('z'),
            'YEAR'          => $this->year
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