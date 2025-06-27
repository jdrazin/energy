#
# Powell cost optimiser, see https://docs.scipy.org/doc/scipy/reference/generated/scipy.optimize.fmin_powell.html#fmin-powell
#
import math
import sys
import json
import numpy as np
from scipy.optimize import minimize
from scipy.optimize import Bounds
from scipy.optimize import LinearConstraint
from scipy.optimize._numdiff import approx_derivative

SUM_CHARGE_TOLERANCE_KWH = 0.1

def value(index):
    string = sys.argv[index]
    pos    = string.find('=')+1
    return string[pos:]

# define energy cost
def dayCostGbp(X):
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
    import_gbp            = 0.0
    export_gbp            = 0.0
    wear_gbp              = 0.0
    power_out_of_spec_gbp = 0.0
    import_kwh            = 0.0
    export_kwh            = 0.0
    slot_count            = 0
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
            export_gbp -= tariff_export_per_kwh * grid_kwh
        else:
            import_kwh       += -grid_kwh
            import_gbp -= tariff_import_per_kwh * grid_kwh

        # battery
        if battery_charge_kw > 0.0:
            battery_level_kwh += battery_charge_kwh * batteryOneWayEfficiency
        else:
            battery_level_kwh += battery_charge_kwh / batteryOneWayEfficiency

        # operational and out of spec wear
        wear_gbp            += wearPerKwh        ( battery_level_kwh,
                                                           0.0,
                                                           batteryCapacityKwh,
                                                           batteryWearEnergyCostAverageGbpPerKwh,
                                                           batteryWearEnergyConstantCoefficient,
                                                           batteryWearEnergyExponentialCoefficient,
                                                           batteryWearEnergyActivationKwh,
                                                           batteryEnergyNormalisationCoefficient) * abs(battery_charge_kwh)

        # battery charge/discharge power out of spec
        power_out_of_spec_gbp      += wearPerKwh       (  battery_charge_kw,
                                                          -batteryMaxDischargeRateKw,
                                                           batteryMaxChargeRateKw,
                                                           batteryWearPowerCostAverageGbpPerKwh,
                                                           batteryWearPowerConstantCoefficient,
                                                           batteryWearPowerExponentialCoefficient,
                                                           batteryWearPowerActivationKw,
                                                           batteryPowerNormalisationCoefficient) * abs(battery_charge_kwh)

        cost_energy_average_per_kwh_acc += 0.5 * (tariff_import_per_kwh + tariff_export_per_kwh) # accumulate average energy cost
        slot_count += 1
    energy_level_change_gbp = (batteryEnergyInitialKwh - battery_level_kwh) * cost_energy_average_per_kwh_acc / number_slots
    total_gbp = import_gbp + export_gbp + wear_gbp + power_out_of_spec_gbp + energy_level_change_gbp
    return total_gbp

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


def hess_numeric(fun):
    def hess(x):
        return approx_derivative(lambda y: approx_derivative(fun, y, method='2-point'), x, method='2-point')
    return hess

# constants
index =  1
solarGenerationLimitKw                      = float(value(index))
index += 1
batteryCapacityKwh                          = float(value(index))
index += 1
batteryOneWayEfficiency                     = float(value(index))
index += 1
batteryWearEnergyCostAverageGbpPerKwh       = float(value(index))
index += 1
batteryWearEnergyConstantCoefficient        = float(value(index))
index += 1
batteryWearEnergyExponentialCoefficient     = float(value(index))
index += 1
batteryWearEnergyActivationKwh              = float(value(index))
index += 1
batteryWearPowerCostAverageGbpPerKwh        = float(value(index))
index += 1
batteryWearPowerConstantCoefficient         = float(value(index))
index += 1
batteryWearPowerExponentialCoefficient      = float(value(index))
index += 1
batteryWearPowerActivationKw                = float(value(index))
index += 1
batteryMaxChargeRateKw                      = float(value(index))
index += 1
batteryMaxDischargeRateKw                   = float(value(index))
index += 1
importLimitKw                               = float(value(index))
index += 1
exportLimitKw                               = float(value(index))
index += 1
batteryEnergyInitialKwh                     = float(value(index))
index += 1
slotSliceDurationHour                       = float(value(index))
index += 1
number_slots                                = int  (value(index))
index += 1
optimiser                                   = int  (value(index))

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

# load initial charge_kws guesses
index += 1
X = []
sumX = 0.0
i = 0
while i < number_slots:
    index += 1
    x_element = float(sys.argv[index])
    sumX += x_element
    X.append(x_element)
    i+= 1

# get cpu time
import time
obj        = time.gmtime(0)
epoch      = time.asctime(obj)
start_time = time.time()

# get cost
energyCostGuess = dayCostGbp(X)

# optimise
if optimiser == 0: # unconstrained optimisation
  # load charge min, max boundary pairs
  index += 1
  bounds = []
  i = 0
  while i < number_slots:
    charge_min_kw = -batteryMaxDischargeRateKw
    charge_max_kw = +batteryMaxChargeRateKw
    i += 1
    bound = (charge_min_kw, charge_max_kw)
    bounds.append(bound)
  result = minimize(dayCostGbp, X, method='powell', bounds=bounds, options={'disp': 0, 'ftol': 1E-14, 'maxiter': 1000000})  # Powell
  elapsed_s = time.time() - start_time
else: # constrained optimisation
  # replace first guesses with average
  X = []
  i = 0
  x = sumX / number_slots
  while i < number_slots:
    X.append(x)
    i+= 1
  # define the bounds
  index += 1
  lowerBounds = []
  upperBounds = []
  i = 0
  while i < number_slots:
      lowerBounds.append(-batteryMaxDischargeRateKw)
      upperBounds.append(+batteryMaxChargeRateKw)
      i += 1
  boundData = Bounds(lowerBounds, upperBounds)

  # define the linear constraints
  lowerBoundLinearConstraints = np.full(1, sumX - SUM_CHARGE_TOLERANCE_KWH)
  upperBoundLinearConstraints = np.full(1, sumX + SUM_CHARGE_TOLERANCE_KWH)
  matrixLinearConstraints = np.ones((1, number_slots))
  linearConstraints = LinearConstraint(matrixLinearConstraints, lowerBoundLinearConstraints, upperBoundLinearConstraints)
  # hessZero                    = lambda x: np.zeros((number_slots, number_slots))
  # optimise
  X = np.array(X, dtype=np.float64)  # force 64 bit on Raspberry Pi scipy implementation
  result = minimize(dayCostGbp, X, method='trust-constr', constraints=[linearConstraints], bounds=boundData, hess=hess_numeric(dayCostGbp), options={'verbose': 0, 'disp': 0, 'maxiter': 1000})
output = { # output result as json
    "converged": result.success,
    "elapsed_s": time.time() - start_time,
    "evaluations": result.nfev,
    "status": result.status,
    "message": result.message,
    "optimum_charge_kws": result.x.tolist(),
    "energyCostGuess": energyCostGuess,
    "energyCostSolution": result.fun
  }
print(json.dumps(output))