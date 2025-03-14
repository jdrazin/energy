<?php
namespace Src;
use Exception;

class Slivers extends Root
{
    public function __construct()
    {
        parent::__construct();
        $final_slot_zero_parameters = $this->finalSlotZeroParameters();    // fetch final slot 0 parameters
    }

    public function finalSlotZeroParameters(): array {
        $sql = 'SELECT  `st`.`start`,
                        `st`.`stop`,
                        `st`.`mode`,
                        `st`.`abs_charge_power_w`,
                        `st`.`target_level_percent`,
                        `st`.`import_gbp_per_kwh`,
                        `st`.`export_gbp_per_kwh`
                   FROM `slots` `st`
                   INNER JOIN `tariff_combinations` `tc` ON `tc`.`id` = `st`.`tariff_combination`
                   WHERE `st`.`slot` = 0 AND
                         `st`.`final` AND
                         `tc`.`active` AND 
                         NOW() BETWEEN `st`.`start` AND `st`.`stop`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($start, $stop, $mode, $abs_charge_power_w, $target_level_percent, $import_gbp_per_kwh, $export_gbp_per_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if (!$stmt->fetch()) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'No slot 0 data');
            throw new Exception($message);
        }
        return ['start'                 =>  $start,
                'stop'                  =>  $stop,
                'mode'                  =>  $mode,
                'abs_charge_power_w'    =>  $abs_charge_power_w,
                'target_level_percent'  =>  $target_level_percent,
                'import_gbp_per_kwh'    =>  $import_gbp_per_kwh,
                'export_gbp_per_kwh'    =>  $export_gbp_per_kwh];
    }
}