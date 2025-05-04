#
# trust-constr cost optimiser, see https://docs.scipy.org/doc/scipy/reference/optimize.minimize-trustconstr.html
#
import math
import sys
import json

import numpy as np
from scipy.optimize import minimize
from scipy.optimize import Bounds
from scipy.optimize import LinearConstraint

SUM_CHARGE_TOLERANCE_KWH = 0.1

# define energy cost
def costFunction(X):
    # X unknowns:
    #   grid_kw:         X[0            ... number_slots-1]
    #   solar_clip_kw:   X[number_slots ... 2*number_slots-1]
    cost_energy_average_per_kwh_acc  = 0.0                           # accumulator for calculating average energy cost
    battery_level_kwh                = batteryEnergyInitialKwh       # initial battery level
    batteryEnergyNormalisationCoefficient = normalisationCoefficient(   batteryWearEnergyConstantCoefficient,
                                                                        batteryWearEnergyExponentialCoefficient,
                                                                        batteryWearEnergyActivationKwh,
                                                                        0.0,
                                                                        batteryCapacityKwh)
    batteryPowerNormalisationCoefficient  = normalisationCoefficient(   batteryWearPowerConstantCoefficient,
                                                                        batteryWearPowerExponentialCoefficient,
                                                                        batteryWearPowerActivationKw,
                                                                        -batteryMaxDischargeRateKw,
                                                                        batteryMaxChargeRateKw)
    cost_grid_import       = 0.0
    cost_grid_export       = 0.0
    cost_energy_wear       = 0.0
    cost_power_out_of_spec = 0.0
    import_kwh             = 0.0
    export_kwh             = 0.0
    slot_count             = 0
    while slot_count < number_slots:
        tariff_import_per_kwh = tariffImportPerKwhs[slot_count]
        tariff_export_per_kwh = tariffExportPerKwhs[slot_count]
        battery_charge_kw     = X[slot_count]
        load_house_kw         = load_house_kws[slot_count]
        solar_gross_kw        = solar_gross_kws[slot_count]

        # clip solar to generation limit
        if solar_gross_kw > solarGenerationLimitKw:
            solar_clipped_kw  = solarGenerationLimitKw
        else:
            solar_clipped_kw  = solar_gross_kw

        # clip grid export to limit
        grid_unlimited_kw     = solar_clipped_kw - load_house_kw - battery_charge_kw
        if grid_unlimited_kw > exportLimitKw:
            grid_limited_kw = exportLimitKw
        else:
            grid_limited_kw = grid_unlimited_kw

        # tie export to battery discharge limit when no net solar
        if solar_clipped_kw - load_house_kw < 0.0:
            if grid_limited_kw > batteryMaxDischargeRateKw:
                grid_limited_kw = batteryMaxDischargeRateKw

        grid_kwh              = grid_limited_kw   * slotSliceDurationHour
        battery_charge_kwh    = battery_charge_kw * slotSliceDurationHour
        #
        # grid
        if grid_kwh > 0.0:
            export_kwh       += grid_kwh
            cost_grid_export -= tariff_export_per_kwh * grid_kwh
        else:
            import_kwh       += -grid_kwh
            cost_grid_import -= tariff_import_per_kwh * grid_kwh

        # battery
        if battery_charge_kw > 0.0:
            battery_level_kwh += battery_charge_kwh * batteryOneWayEfficiency
        else:
            battery_level_kwh += battery_charge_kwh / batteryOneWayEfficiency

        # operational and out of spec wear
        cost_energy_wear            += wearPerKwh        ( battery_level_kwh,
                                                           0.0,
                                                           batteryCapacityKwh,
                                                           batteryWearEnergyCostAverageGbpPerKwh,
                                                           batteryWearEnergyConstantCoefficient,
                                                           batteryWearEnergyExponentialCoefficient,
                                                           batteryWearEnergyActivationKwh,
                                                           batteryEnergyNormalisationCoefficient) * abs(battery_charge_kwh)

        # battery charge/discharge power out of spec
        cost_power_out_of_spec      += wearPerKwh       (  battery_charge_kw,
                                                           -batteryMaxDischargeRateKw,
                                                           batteryMaxChargeRateKw,
                                                           batteryWearPowerCostAverageGbpPerKwh,
                                                           batteryWearPowerConstantCoefficient,
                                                           batteryWearPowerExponentialCoefficient,
                                                           batteryWearPowerActivationKw,
                                                           batteryPowerNormalisationCoefficient) * abs(battery_charge_kwh)

        cost_energy_average_per_kwh_acc += 0.5 * (tariff_import_per_kwh + tariff_export_per_kwh) # accumulate average energy cost
        slot_count += 1
    cost_energy_level_change = (batteryEnergyInitialKwh - battery_level_kwh) * cost_energy_average_per_kwh_acc / number_slots
    day_cost_gbp = cost_grid_import + cost_grid_export + cost_energy_wear + cost_power_out_of_spec + cost_energy_level_change
    return day_cost_gbp

# define wear function
def wearPerKwh(x, x_min, x_max, wear_cost_average, constant_coefficient, exponential_coefficient, activation, normalisation_coefficient):
    X = (((x - x_min) / (x_max - x_min)) - 0.5)
    X2 = X * X
    t1 = constant_coefficient
    t2 = (1.0 - constant_coefficient) * X2
    if X < 0.0:
        exponent = (x_min - x) / activation
    else:
        exponent = (x - x_max) / activation
    t3 = exponential_coefficient * math.exp(exponent)
    wear_per_kwh = normalisation_coefficient * wear_cost_average * (t1+t2+t3)
    return wear_per_kwh

def normalisationCoefficient(constant_coefficient, exponential_coefficient, activation, x_min, x_max):
    normalisation_coefficient = 12.0/(1.0+(11.0*constant_coefficient)+(24.0*exponential_coefficient*activation/(x_max - x_min)))
    return normalisation_coefficient

# get cpu time
import time
obj        = time.gmtime(0)
epoch      = time.asctime(obj)
start_time = time.time()

# constants
index =  2
solarGenerationLimitKw                      = float(sys.argv[index])
index += 2
batteryCapacityKwh                          = float(sys.argv[index])
index += 2
batteryOneWayEfficiency                     = float(sys.argv[index])
index += 2
batteryWearEnergyCostAverageGbpPerKwh       = float(sys.argv[index])
index += 2
batteryWearEnergyConstantCoefficient        = float(sys.argv[index])
index += 2
batteryWearEnergyExponentialCoefficient     = float(sys.argv[index])
index += 2
batteryWearEnergyActivationKwh              = float(sys.argv[index])
index += 2
batteryWearPowerCostAverageGbpPerKwh        = float(sys.argv[index])
index += 2
batteryWearPowerConstantCoefficient         = float(sys.argv[index])
index += 2
batteryWearPowerExponentialCoefficient      = float(sys.argv[index])
index += 2
batteryWearPowerActivationKw                = float(sys.argv[index])
index += 2
batteryMaxChargeRateKw                      = float(sys.argv[index])
index += 2
batteryMaxDischargeRateKw                   = float(sys.argv[index])
index += 2
importLimitKw                               = float(sys.argv[index])
index += 2
exportLimitKw                               = float(sys.argv[index])
index += 2
gridWearPowerCostAverageGbpPerKwh           = float(sys.argv[index])
index += 2
gridWearPowerConstantCoefficient            = float(sys.argv[index])
index += 2
gridWearPowerExponentialCoefficient         = float(sys.argv[index])
index += 2
gridWearPowerActivationKw                   = float(sys.argv[index])
index += 2
batteryEnergyInitialKwh                     = float(sys.argv[index])
index += 2
slotSliceDurationHour                       = float(sys.argv[index])
index += 2
number_slots                                = int  (sys.argv[index])

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

# load house_load_kws
index += 1
load_house_kws = []
i = 0
while i < number_slots:
    index += 1
    load_house_kws   .append(float(sys.argv[index]))
    i+= 1

# load solar_gross_kws
index += 1
solar_gross_kws = []
i = 0
while i < number_slots:
    index += 1
    solar_gross_kws  .append(float(sys.argv[index]))
    i+= 1

# load initial charge_kws first guesses
index += 1
X0 = []
i = 0
sumX = 0.0
while i < number_slots:
    index += 1
    x_element = float(sys.argv[index])
    sumX += x_element
    X0.append(x_element)
    i+= 1

# define the bounds
index += 1
lowerBounds = []
upperBounds = []
i = 0
while i < number_slots:
    lowerBounds.append(-batteryMaxDischargeRateKw)
    upperBounds.append(+batteryMaxChargeRateKw)
    i+= 1
boundData = Bounds(lowerBounds, upperBounds)

#define the linear constraints
lowerBoundLinearConstraints = np.full(1, sumX - SUM_CHARGE_TOLERANCE_KWH)
upperBoundLinearConstraints = np.full(1, sumX + SUM_CHARGE_TOLERANCE_KWH)
matrixLinearConstraints     = np.ones((1, number_slots))
linearConstraints           = LinearConstraint(matrixLinearConstraints, lowerBoundLinearConstraints, upperBoundLinearConstraints)
hessZero                    = lambda x: np.zeros((number_slots, number_slots))

# get cost
cost = costFunction(X0)

# optimise
# result = minimize(dayCostGbp, X0, method='powell', bounds=boundData, options={'disp': 0, 'ftol': 1E-14, 'maxiter': 1000000}) # Powell
result = minimize(costFunction, X0, method='trust-constr', constraints=[linearConstraints], bounds=boundData, hess=hessZero, options={'verbose': 0, 'disp': 0, 'maxiter': 1000000})

elapsed_s = time.time() - start_time

# output result as json
output = {
    "success":              result.success,
    "elapsed_s":            elapsed_s,
    "evaluations":          result.nfev,
    "status":               result.status,
    "message":              result.message,
    "optimum_charge_kws":   result.x.tolist(),
    "energyCost":           result.fun
}
print(json.dumps(output))