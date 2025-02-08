#
# Nelder-Mead cost optimiser, see https://docs.scipy.org/doc/scipy/reference/optimize.minimize-neldermead.html#optimize-minimize-neldermead
#
import sys
import json
from scipy.optimize import minimize

# define energy cost
def day_cost(grid_kws):
    cost_energy_average_per_kwh_acc = 0.0                           # accumulator for calculating average energy cost
    battery_level_kwh               = batteryEnergyInitialKwh       # initial battery level
    battery_level_mid_kwh           = batteryCapacityKwh / 2.0      # midpoint battery level
    battery_level_max_kwh           = (100.0 + batteryDepthOfDischargePercent) * batteryCapacityKwh / 200.0    # max battery operating level
    cost_min_per_kwh                = 2.0 * batteryWearCostGbpPerKwh / (1.0 + batteryWearRatio)                # minimum wear cost at midpoint level
    cost_grid_import                = 0.0
    cost_grid_export                = 0.0
    cost_wear                       = 0.0
    cost_out_of_spec                = 0.0
    import_kwh                      = 0.0
    export_kwh                      = 0.0
    slot_count                      = 0
    while slot_count < number_slots:
        grid_power_slot_kw    = grid_kws[slot_count]
        if grid_power_slot_kw  < -exportLimitKw:              # clip grid power to import/export limit
            grid_power_slot_kw = -exportLimitKw
        else:
            if grid_power_slot_kw  > importLimitKw:
                grid_power_slot_kw = importLimitKw
        load_kw               = load_kws[slot_count]
        tariff_import_per_kwh = tariffImportPerKwhs[slot_count]
        tariff_export_per_kwh = tariffExportPerKwhs[slot_count]
        energy_grid_kwh       = grid_power_slot_kw * slotDurationHour
        load_kwh              = load_kw * slotDurationHour
        #
        # grid
        if energy_grid_kwh < 0.0:
            export_kwh       += energy_grid_kwh
            cost_grid_export += tariff_export_per_kwh * energy_grid_kwh
        else:
            import_kwh       += energy_grid_kwh
            cost_grid_import += tariff_import_per_kwh * energy_grid_kwh

        # battery
        battery_charge_kwh           = energy_grid_kwh - load_kwh
        battery_charge_kw            = grid_power_slot_kw - load_kw
        battery_level_kwh           += battery_charge_kwh * batteryOneWayStorageEfficiency

        # wear
        battery_level_wear_fraction  = abs(battery_level_kwh - battery_level_mid_kwh) / (battery_level_max_kwh - battery_level_mid_kwh)
        if battery_level_wear_fraction <= 1.0:      # wear
            cost_wear        += cost_min_per_kwh * abs(battery_charge_kwh) * (1.0 + batteryWearRatio * battery_level_wear_fraction)
        else:                                       # out of spec
            cost_out_of_spec += cost_min_per_kwh * abs(battery_charge_kwh) * (batteryWearRatio + (battery_level_wear_fraction - 1.0) * batteryOutOfSpecCostMultiplier)

        # out of spec power
        out_of_spec_kwh = 0.0
        if battery_charge_kw > 0.0:      # charging
            excess_kw = battery_charge_kw - batteryMaxChargeRateKw
            if excess_kw > 0.0:
                out_of_spec_kwh += excess_kw * slotDurationHour
        else:                           # discharging
            excess_kw = -battery_charge_kw - batteryMaxDischargeRateKw
            if excess_kw > 0.0:
                out_of_spec_kwh += excess_kw * slotDurationHour
        cost_out_of_spec += out_of_spec_kwh * batteryOutOfSpecCostMultiplier
        cost_energy_average_per_kwh_acc += 0.5 * (tariff_import_per_kwh + tariff_export_per_kwh) # accumulate average energy cost
        slot_count += 1
    cost_level_change = (batteryEnergyInitialKwh - battery_level_kwh) * cost_energy_average_per_kwh_acc / number_slots
    cost = cost_grid_import + cost_grid_export + cost_wear + cost_out_of_spec + cost_level_change
    return cost

# constants
index = 1
batteryCapacityKwh              = float(sys.argv[index])
index += 1
batteryDepthOfDischargePercent  = float(sys.argv[index])
index += 1
batteryOneWayStorageEfficiency  = float(sys.argv[index])
index += 1
batteryWearCostGbpPerKwh        = float(sys.argv[index])
index += 1
batteryWearRatio                = float(sys.argv[index])
index += 1
batteryOutOfSpecCostMultiplier  = float(sys.argv[index])
index += 1
batteryMaxChargeRateKw          = float(sys.argv[index])
index += 1
batteryMaxDischargeRateKw       = float(sys.argv[index])
index += 1
importLimitKw                   = float(sys.argv[index])
index += 1
exportLimitKw                   = float(sys.argv[index])
index += 1
batteryEnergyInitialKwh         = float(sys.argv[index])
index += 1
slotDurationHour                = float(sys.argv[index])
index += 1
number_slots                    = int  (sys.argv[index])
index += 1

# load powerImportExports
load_kws    = []
tariffImportPerKwhs = []
tariffExportPerKwhs = []
i = 0
while i < number_slots:
    tariffImportPerKwhs.append(float(sys.argv[index]))
    index += 1
    tariffExportPerKwhs.append(float(sys.argv[index]))
    index += 1
    load_kws   .append(float(sys.argv[index]))
    index += 1
    i+= 1

# load initial guesses
gridSlotKwhs = []
i = 0
while i < number_slots:
    gridSlotKwhs   .append(float(sys.argv[index]))
    index += 1
    i+= 1

# get cost
cost = day_cost(gridSlotKwhs)

# optimise
result = minimize(day_cost, gridSlotKwhs, method="Nelder-Mead", options={'disp': 0, 'adaptive': 1, 'fatol': 1E-10, 'maxiter': 1000000})

# output result as json
output = {
    "success": result.success,
    "status": result.status,
    "message": result.message,
    "optimumGridKws": result.x.tolist(),
    "energyCost": result.fun
}
print(json.dumps(output))