<?php
//
// see https://pubs.nmsu.edu/_circulars/CR674/
//
// see tutorials https://www.youtube.com/playlist?list=PL89870B418A514D27
//
// see also https://www.pveducation.org/pvcdrom/properties-of-sunlight/calculation-of-solar-insolation
//
// see https://energy-stats.uk/dashboards/
//
// see https://agile.octopushome.net/dashboard
//
namespace Src;
use Exception;

class Solar extends Root
{
    const   float   SURFACE_REFLECTANCE = 0.2,
                    EARTH_TILT_DEGREES = 23.45,
                    SOLAR_EQUINOX_FRACTION_YEAR = 81.0 / 365.25;

    public float    $total_insolation_time_w_per_m2, $total_insolation_cloud_time_w_per_m2, $time_correction_fraction,
                    $latitude_degrees, $longitude_degrees, $azimuth_degrees, $tilt_degrees, $total_insolation_noon_w_per_m2;

    public string $orientation_type;

    public array $cloud_cover_months_year_fractions, $cloud_cover_months_factors;

    public function __construct($location, $orientation) {
        parent::__construct();
        if ($location && $orientation) {
            $this->time_correction_fraction = $location['time_correction_fraction'];

            $coordinates = $location['coordinates'];
            $this->latitude_degrees  = $coordinates['latitude_degrees'];
            $this->longitude_degrees = $coordinates['longitude_degrees'];

            $cloud_cover_months = $location['cloud_cover_months'];
            $this->cloud_cover_months_year_fractions = $cloud_cover_months['fractions'];
            $this->cloud_cover_months_factors = $cloud_cover_months['factors'];

            $this->orientation_type = $orientation['type'];
            $this->azimuth_degrees  = $orientation['azimuth_degrees'];
            $this->tilt_degrees     = $orientation['tilt_degrees'];
        }
    }

    public function time_update($time): void {
        $fraction_year = $time->fraction_year;
        $fraction_day  = $time->fraction_day;

        //
        // values at solar noon
        //
        $apparent_extraterrestrial_solar_insolation_w_per_m2 = 1160.0 + 75.0 * sin(deg2rad((360.0 * $fraction_year) - 275.0));
        $opticalDepth = 0.174 + 0.035 * sin(deg2rad((360.0 * $fraction_year) - 100.0));
        $sky_diffuse_factor = 0.095 + 0.04 * sin(deg2rad((360.0 * $fraction_year) - 100.0));

        $solar_declination_degrees = self::EARTH_TILT_DEGREES * sin(deg2rad((360 * ($fraction_year - self::SOLAR_EQUINOX_FRACTION_YEAR))));
        $solar_altitude_noon_degrees = (90.0 - $this->latitude_degrees + $solar_declination_degrees);
        $air_mass_ratio_noon = abs(1.0 / sin(deg2rad(($solar_altitude_noon_degrees))));
        $clear_sky_beam_radiation_earth_w_per_m2 = $apparent_extraterrestrial_solar_insolation_w_per_m2 * exp(-$opticalDepth * $air_mass_ratio_noon);

        $cosine_incidence_noon = cos(deg2rad($solar_altitude_noon_degrees)) * cos(deg2rad(($this->azimuth_degrees - 180.0))) * sin(deg2rad($this->tilt_degrees)) + sin(deg2rad($solar_altitude_noon_degrees)) * cos(deg2rad($this->tilt_degrees));
        $beam_insolation_face_noon_w_per_m2 = $clear_sky_beam_radiation_earth_w_per_m2 * $cosine_incidence_noon;
        $diffuse_radiation_noon_w_per_m2 = $sky_diffuse_factor * $clear_sky_beam_radiation_earth_w_per_m2 * ((1 + cos(deg2rad($this->tilt_degrees))) / 2);
        $reflected_radiation_noon_w_per_m2 = self::SURFACE_REFLECTANCE * $clear_sky_beam_radiation_earth_w_per_m2 * ((sin(deg2rad($solar_altitude_noon_degrees))) + $sky_diffuse_factor) * ((1.0 - cos(deg2rad($this->tilt_degrees))) / 2);
        $this->total_insolation_noon_w_per_m2 = $beam_insolation_face_noon_w_per_m2 + $diffuse_radiation_noon_w_per_m2 + $reflected_radiation_noon_w_per_m2;

        //
        // values at arbitrary time of year and day
        //
        $longitude_correction_degrees = 360.0 * ($fraction_year - self::SOLAR_EQUINOX_FRACTION_YEAR);
        $equation_of_time_of_day_fraction = ((9.87 * sin(deg2rad(2 * $longitude_correction_degrees))) - (7.53 * cos(deg2rad($longitude_correction_degrees))) - (1.5 * sin(deg2rad($longitude_correction_degrees)))) / (60 * 24);
        $solar_noon_local_day_fraction = 0.5 - $equation_of_time_of_day_fraction + $this->time_correction_fraction - ($this->longitude_degrees / 360);
        $hours_about_solar_noon = 24.0 * ($solar_noon_local_day_fraction - $fraction_day);
        $solar_hour_angle_time_degrees = 15.0 * $hours_about_solar_noon;
        $solar_altitude_time_degrees = rad2deg(asin(cos(deg2rad($this->latitude_degrees)) * cos(deg2rad($solar_declination_degrees)) * cos(deg2rad($solar_hour_angle_time_degrees)) + sin(deg2rad($this->latitude_degrees)) * sin(deg2rad($solar_declination_degrees))));
        $azimuth_formula = rad2deg(asin(cos(deg2rad($solar_declination_degrees)) * sin(deg2rad($solar_hour_angle_time_degrees)) / cos(deg2rad($solar_altitude_time_degrees))));
        $azimuth_before = 180.0 - $azimuth_formula;
        $azimuth_after = -$azimuth_formula - 180.0;
        $solar_azimuth_time_degrees = cos(deg2rad($solar_hour_angle_time_degrees)) >= ((tan(deg2rad($solar_declination_degrees))) / (tan(deg2rad($this->latitude_degrees)))) ? $azimuth_formula : ($fraction_day < 0.5 ? $azimuth_before : $azimuth_after);
        $air_mass_ratio_time = abs(1.0 / sin(deg2rad($solar_altitude_time_degrees)));
        $clear_sky_beam_radiation_earth_time_w_per_m2 = $solar_altitude_time_degrees > 0.0 ? $apparent_extraterrestrial_solar_insolation_w_per_m2 * exp(-$opticalDepth * $air_mass_ratio_time) : 0.0;

        $beam_insolation_face_time_w_per_m2 = $diffuse_radiation_time_w_per_m2 = $reflected_radiation_time_w_per_m2 = 0.0;
        switch ($this->orientation_type) {
            case 'flat':
            {
                $cosine_incidence_time = cos(deg2rad($solar_altitude_time_degrees)) * cos(deg2rad($solar_azimuth_time_degrees - ($this->azimuth_degrees - 180.0))) * sin(deg2rad(0)) + (sin(deg2rad($solar_altitude_time_degrees)) * cos(deg2rad(0)));
                $beam_insolation_face_time_w_per_m2 = $clear_sky_beam_radiation_earth_time_w_per_m2 * $cosine_incidence_time;
                $diffuse_radiation_time_w_per_m2 = $sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2;
                break;
            }
            case 'tilted':
            {
                $cosine_incidence_time = cos(deg2rad($solar_altitude_time_degrees)) * cos(deg2rad($solar_azimuth_time_degrees - $this->azimuth_degrees - 180.0)) * sin(deg2rad($this->tilt_degrees)) + sin(deg2rad($solar_altitude_time_degrees)) * cos(deg2rad($this->tilt_degrees));
                $beam_insolation_face_time_w_per_m2 = $cosine_incidence_time < 0.0 ? 0.0 : $clear_sky_beam_radiation_earth_time_w_per_m2 * $cosine_incidence_time;
                $diffuse_radiation_time_w_per_m2 = $sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2 * ((1.0 + cos(deg2rad($this->tilt_degrees))) / 2.0);
                $reflected_radiation_time_w_per_m2 = self::SURFACE_REFLECTANCE * $clear_sky_beam_radiation_earth_time_w_per_m2 * ((sin(deg2rad($solar_altitude_time_degrees))) + $sky_diffuse_factor) * ((1.0 - cos(deg2rad($this->tilt_degrees))) / 2.0);
                break;
            }
            case '1-axis tracker':
            {
                $beam_insolation_face_time_w_per_m2 = $clear_sky_beam_radiation_earth_time_w_per_m2 * cos(deg2rad($solar_declination_degrees));
                $diffuse_radiation_time_w_per_m2 = $sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2 * ((1.0 + cos(deg2rad(90.0 - $solar_altitude_time_degrees + $solar_declination_degrees))) / 2.0);
                $reflected_radiation_time_w_per_m2 = self::SURFACE_REFLECTANCE * (($clear_sky_beam_radiation_earth_time_w_per_m2 * sin(deg2rad($solar_altitude_time_degrees))) + ($sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2)) * ((1.0 - cos(deg2rad(90.0 - $solar_altitude_time_degrees + $solar_declination_degrees))) / 2.0);
                break;
            }
            case '2-axis tracker':
            {
                $beam_insolation_face_time_w_per_m2 = $clear_sky_beam_radiation_earth_time_w_per_m2;
                $diffuse_radiation_time_w_per_m2 = $sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2 * ((1.0 + cos(deg2rad(90.0 - $solar_altitude_time_degrees))) / 2.0);
                $reflected_radiation_time_w_per_m2 = self::SURFACE_REFLECTANCE * (($clear_sky_beam_radiation_earth_time_w_per_m2 * sin(deg2rad($solar_altitude_time_degrees))) + ($sky_diffuse_factor * $clear_sky_beam_radiation_earth_time_w_per_m2)) * ((1.0 - cos(deg2rad(90.0 - $solar_altitude_time_degrees))) / 2.0);
                break;
            }
            default:
        }
        $this->total_insolation_time_w_per_m2 = $beam_insolation_face_time_w_per_m2 + $diffuse_radiation_time_w_per_m2 + $reflected_radiation_time_w_per_m2;
        $this->total_insolation_cloud_time_w_per_m2 = $this->cloud_factor($fraction_year) * $this->total_insolation_time_w_per_m2;
    }

    public function cloud_factor($fraction_year): float|int {
        //
        // see
        //
        foreach ($this->cloud_cover_months_year_fractions as $month => $fraction) {        // find index of higher year_fraction
            if ($fraction > $fraction_year) {
                break;
            }
        }
        if ($month == 0) {                                                                 // interpolate factor
            $factor_lo = $this->cloud_cover_months_factors[11];
            $factor_hi = $this->cloud_cover_months_factors[$month];
            $fraction_lo = $this->cloud_cover_months_year_fractions[11] - 1.0;
            $fraction_hi = $this->cloud_cover_months_year_fractions[$month];
        } elseif ($month == 11) {
            $factor_lo = $this->cloud_cover_months_factors[11];
            $factor_hi = $this->cloud_cover_months_factors[0];
            $fraction_lo = $this->cloud_cover_months_year_fractions[11];
            $fraction_hi = $this->cloud_cover_months_year_fractions[0] + 1.0;
        } else {
            $factor_lo = $this->cloud_cover_months_factors[$month - 1];
            $factor_hi = $this->cloud_cover_months_factors[$month];
            $fraction_lo = $this->cloud_cover_months_year_fractions[$month - 1];
            $fraction_hi = $this->cloud_cover_months_year_fractions[$month];
        }
        return $factor_lo + ($fraction_year - $fraction_lo) * ($factor_hi - $factor_lo) / ($fraction_hi - $fraction_lo);
    }

    /**
     * @throws \Exception
     */
    public function db_historic_average_power_w($datetime_centre, $slot_width_min, $period_day, $max_ago_day): float {
        // returns average measured solar:
        // - for slot centred about $datetime_centre
        // - with $slot_width_min within a period centred about $datetime_centre
        // - width $period_day
        // - looking back to $max_ago_day
        $db_historic_average_power_w = 0.0;
        $period_half_day   =      (int) round($period_day     /2.0);
        $slot_width_half_s = 60 * (int) round($slot_width_min /2.0);
        $sql = 'SELECT  AVG(`value`)
                  FROM  `values`
                  WHERE `entity` = \'SOLAR_W\' AND
                        `type`   = \'MEASURED\' AND
                        DATE(`datetime`) BETWEEN DATE(?) - INTERVAL ? DAY    AND DATE(?) + INTERVAL ? DAY    AND
                        TIME(`datetime`) BETWEEN TIME(?) - INTERVAL ? SECOND AND TIME(?) + INTERVAL ? SECOND AND
                        `datetime` > NOW() - INTERVAL ? DAY';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sisisisii', $datetime_centre, $period_half_day, $datetime_centre, $period_half_day, $datetime_centre, $slot_width_half_s, $datetime_centre, $slot_width_half_s, $max_ago_day) ||
            !$stmt->bind_result($db_historic_average_power_w) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }

        return $db_historic_average_power_w;
    }
}