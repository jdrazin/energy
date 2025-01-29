<?php

class Climate
{
    const   TEMPERATURE_AVERAGE_DAILY_MINIMUM_CELSIUS = -3,
        TEMPERATURE_AVERAGE_DAILY_MAXIMUM_CELSIUS = 22,
        TEMPERATURE_AVERAGE_DAILY_SWING = 10,
        TEMPERATURE_LAG_YEAR_FRACTION = 9.0 / 365.0,
        TEMPERATURE_LAG_DAY_FRACTION = 0.1;

    public function temperature_time($time): float
    {
        return $this->temperature_fraction($time->fraction_year, $time->fraction_day);
    }

    public function temperature_fraction($fraction_year, $fraction_day): float
    {
        $fraction_year = $time->fraction_year ?? $fraction_year;
        $fraction_day = $time->fraction_day ?? $fraction_day;
        $angle_year_degrees = 360.0 * ($fraction_year - self::TEMPERATURE_LAG_YEAR_FRACTION);
        $temperature_average_daily = self::TEMPERATURE_AVERAGE_DAILY_MINIMUM_CELSIUS +
            (self::TEMPERATURE_AVERAGE_DAILY_MAXIMUM_CELSIUS - self::TEMPERATURE_AVERAGE_DAILY_MINIMUM_CELSIUS) * 0.5 * $this->one_minus_cos($angle_year_degrees);

        $angle_day_degrees = 360.0 * ($fraction_day - self::TEMPERATURE_LAG_DAY_FRACTION);
        $temperature_swing_daily = self::TEMPERATURE_AVERAGE_DAILY_SWING * 0.5 * $this->one_minus_cos($angle_day_degrees);

        return $temperature_average_daily + $temperature_swing_daily;
    }

    public function one_minus_cos($degrees): float
    {
        return 1.0 - cos(deg2rad($degrees));
    }
}