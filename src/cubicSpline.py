#
# Cubic Spline, see https://docs.scipy.org/doc/scipy/reference/generated/scipy.interpolate.CubicSpline.html
#
import sys
import json
from scipy.interpolate import CubicSpline
import numpy as np

# constants
index =  2
multiple = int(sys.argv[index])

# array size
index   += 2
size     = int(sys.argv[index])
i = 0
while i < number_slots:
    index += 1
    tariffImportPerKwhs.append(float(sys.argv[index]))
    i+= 1

# use bc_type = 'natural' adds the constraints as we described above
f = CubicSpline(x, y, bc_type='natural')
x_cs = np.linspace(0, 2, 10)
y_cs = f(x_cs)

# output result as json
output = {
    "x": x_cs,
    "y": y_cs
}
print(json.dumps(output))