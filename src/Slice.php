<?php
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class Slice extends Root
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function command(): void
    {
        $givenergy = new GivEnergy();
        $octopus = new Octopus();
        $givenergy_latest = $givenergy->latest();
        $parameters = [
            'type'                      => 'slices',
            'batteryLevelInitialKwh'    => $givenergy->batteryLevelNowKwh($givenergy_latest['battery']), // initial level at beginning of slot 0,
            'battery_charge_now_w'      => $givenergy->batteryChargeNowW($givenergy_latest['battery']),
            'load_house_kw'             => ((float)$givenergy_latest['consumption']) / 1000.0,
            'solar_gross_kw'            => ((float)$givenergy_latest['solar']['power']) / 1000.0,
            'tariff_combination'        => $octopus->tariffCombinations()[0]
        ];
        $command = (new EnergyCost($parameters))->minimise(); // minimise energy cost
        if (GIVENERGY_ENABLE) {
            $givenergy->control($command);
        }
    }
}