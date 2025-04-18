<?php
namespace Src;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class Slice extends Root
{
    const int SLICE_STALE_DAYS = 1;

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
        $this->trim();
    }

    /**
     * @throws Exception
     */
    private function trim(): void {
        $sql = 'DELETE FROM `slices`
                  WHERE `timestamp` < NOW() - INTERVAL ' . self::SLICE_STALE_DAYS . ' DAY';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
        }
    }
}