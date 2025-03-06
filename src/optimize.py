#
# Nelder-Mead cost optimiser, see https://docs.scipy.org/doc/scipy/reference/optimize.minimize-neldermead.html#optimize-minimize-neldermead
#
import math
import sys
import json
from scipy.optimize import minimize

# define energy cost
def day_cost(grid_kws):
    cost_energy_average_per_kwh_acc  = 0.0                           # accumulator for calculating average energy cost
    battery_level_kwh                = batteryEnergyInitialKwh       # initial battery level
    normalisation_energy_coefficient = 12.0 / (1.0 + (11.0 * batteryWearConstantCoefficient) + (24.0 * batteryWearOutOfSpecCoefficient * batteryWearOutOfSpecActivationEnergyKwh / batteryCapacityKwh))
    normalisation_power_coefficient  = 12.0 / (1.0 + (24.0 * batteryWearOutOfSpecActivationEnergyKwh * batteryWearOutOfSpecCoefficient / (batteryMaxDischargeRateKw + batteryMaxChargeRateKw)))
    cost_grid_import                 = 0.0
    cost_grid_export                 = 0.0
    cost_grid_out_of_spec            = 0.0
    cost_energy_wear_out_of_spec     = 0.0
    cost_power_out_of_spec           = 0.0
    import_kwh                       = 0.0
    export_kwh                       = 0.0
    slot_count                       = 0
    while slot_count < number_slots:
        grid_power_slot_kw = grid_kws[slot_count]

        total_load_kw         = total_load_kws[slot_count]
        tariff_import_per_kwh = tariffImportPerKwhs[slot_count]
        tariff_export_per_kwh = tariffExportPerKwhs[slot_count]
        energy_grid_kwh       = grid_power_slot_kw * slotDurationHour
        total_load_kwh              = total_load_kw * slotDurationHour
        #
        # grid
        if energy_grid_kwh > 0.0:
            export_kwh       += energy_grid_kwh
            cost_grid_export -= tariff_export_per_kwh * energy_grid_kwh
        else:
            import_kwh       += -energy_grid_kwh
            cost_grid_import -= tariff_import_per_kwh * energy_grid_kwh

        # grid out of spec import / export limit
        cost_grid_out_of_spec += wear_out_of_spec_cost(         grid_power_slot_kw,
                                                               -importLimitKw,
                                                                exportLimitKw,
                                                                batteryWearCostAverageGbpPerKwh/slotDurationHour,
                                                                0.0,
                                                                batteryWearOutOfSpecCoefficient,
                                                                batteryWearOutOfSpecActivationEnergyKwh,
                                                                normalisation_power_coefficient) * slotDurationHour

        # battery
        battery_charge_kwh   = -energy_grid_kwh - total_load_kwh
        battery_charge_kw    = -grid_power_slot_kw - total_load_kw
        battery_level_kwh   += battery_charge_kwh * batteryOneWayStorageEfficiency

        # operational and out of spec wear
        cost_energy_wear_out_of_spec += wear_out_of_spec_cost(  battery_level_kwh,
                                                                0.0,
                                                                batteryCapacityKwh,
                                                                batteryWearCostAverageGbpPerKwh,
                                                                batteryWearConstantCoefficient,
                                                                batteryWearOutOfSpecCoefficient,
                                                                batteryWearOutOfSpecActivationEnergyKwh,
                                                                normalisation_energy_coefficient) * slotDurationHour

        # out of current spec
        cost_power_out_of_spec += wear_out_of_spec_cost(       battery_charge_kw,
                                                              -batteryMaxDischargeRateKw,
                                                               batteryMaxChargeRateKw,
                                                               batteryWearCostAverageGbpPerKwh / slotDurationHour,
                                                                0.0,
                                                                batteryWearOutOfSpecCoefficient,
                                                                batteryWearOutOfSpecActivationEnergyKwh,
                                                                normalisation_power_coefficient) * slotDurationHour

        cost_energy_average_per_kwh_acc += 0.5 * (tariff_import_per_kwh + tariff_export_per_kwh) # accumulate average energy cost
        slot_count += 1
    cost_level_change = (batteryEnergyInitialKwh - battery_level_kwh) * cost_energy_average_per_kwh_acc / number_slots
    return cost_grid_import + cost_grid_export + cost_grid_out_of_spec + cost_energy_wear_out_of_spec + cost_power_out_of_spec + cost_level_change

# define wear function
def wear_out_of_spec_cost(x, x_min, x_max, wear_cost_average, constant_coefficient, out_of_spec_coefficient, activation, normalisation_coefficient):
    X = (((x - x_min) / (x_max - x_min)) - 0.5)
    X2 = X * X
    t1 = constant_coefficient
    t2 = (1.0 - constant_coefficient) * X2
    if X < 0.0:
        exponent = (x_min - x) / activation
    else:
        exponent = (x - x_max) / activation
    if exponent < 1E-4:
        exponential = exponent + 1.0
    else:
        exponential = math.exp(exponent)
    t3 = out_of_spec_coefficient * exponential
    wear_out_of_spec_cost = normalisation_coefficient *wear_cost_average * (t1+t2+t3)
    return wear_out_of_spec_cost

# constants
index =  2
batteryCapacityKwh                      = float(sys.argv[index])
index += 2
batteryOneWayStorageEfficiency          = float(sys.argv[index])
index += 2
batteryWearCostAverageGbpPerKwh         = float(sys.argv[index])
index += 2
batteryWearConstantCoefficient          = float(sys.argv[index])
index += 2
batteryWearOutOfSpecCoefficient         = float(sys.argv[index])
index += 2
batteryWearOutOfSpecActivationEnergyKwh = float(sys.argv[index])
index += 2
batteryMaxChargeRateKw                  = float(sys.argv[index])
index += 2
batteryMaxDischargeRateKw               = float(sys.argv[index])
index += 2
importLimitKw                           = float(sys.argv[index])
index += 2
exportLimitKw                           = float(sys.argv[index])
index += 2
batteryEnergyInitialKwh                 = float(sys.argv[index])
index += 2
slotDurationHour                        = float(sys.argv[index])
index += 2
number_slots                            = int  (sys.argv[index])

# load import_gbp_per_kwhs
index += 1
tariffImportPerKwhs = []
i = 0
while i < number_slots:
    index += 1
    tariffImportPerKwhs.append(float(sys.argv[index]))
    i+= 1

# load export_gbp_per_kwhs
index += 1
tariffExportPerKwhs = []
i = 0
while i < number_slots:
    index += 1
    tariffExportPerKwhs.append(float(sys.argv[index]))
    i+= 1

# load total_load_kws
index += 1
total_load_kws = []
i = 0
while i < number_slots:
    index += 1
    total_load_kws   .append(float(sys.argv[index]))
    i+= 1

# load initial guesses
index += 1
gridSlotKwhs = []
i = 0
while i < number_slots:
    index += 1
    gridSlotKwhs   .append(float(sys.argv[index]))
    i+= 1

# get cpu time
import time
obj = time.gmtime(0)
epoch = time.asctime(obj)
start_time = time.time()

# get cost
cost = day_cost(gridSlotKwhs)

# optimise
result    = minimize(day_cost, gridSlotKwhs, method="Nelder-Mead", options={'disp': 0, 'adaptive': 1, 'fatol': 1E-12, 'maxiter': 2000000})
elapsed_s = time.time() - start_time

# output result as json
output = {
    "success":          result.success,
    "elapsed_s":        elapsed_s,
    "evaluations":      result.nfev,
    "status":           result.status,
    "message":          result.message,
    "optimumGridKws":   result.x.tolist(),
    "energyCost":       result.fun
}
print(json.dumps(output))