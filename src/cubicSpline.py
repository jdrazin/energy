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

# load x, y array element pairs
x = []
y = []

index += 2
i = 0
while i < size:
    x.append(float(sys.argv[index]))
    index += 1
    y.append(float(sys.argv[index]))
    index += 1
    i+= 1

# use bc_type = 'natural' adds the constraints as we described above
f = CubicSpline(x, y, bc_type='natural')
x_cs = np.linspace(0, size-1, multiple)
y_cs = f(x_cs)

# output result as json
output = {
    "x": x_cs.tolist(),
    "y": y_cs.tolist()
}
print(json.dumps(output))