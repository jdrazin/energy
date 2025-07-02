
# Licence
This project is available for copying and use according to the terms of the GNU GENERAL PUBLIC LICENSE, Version 3, 29 June 2007

# Website
See https://renewable-visions.com/

# How to use

## Requirements
# Hardware
* Raspberry Pi 5
* 8GB RAM

# System
* Ubuntu 22.04
* Apache 2
* MySQL 8
* PHP 8.3+

## Crontab entries
28,58  *   *   *   *     php  /var/www/html/energy/src/slot_solver.php   cron
*/2    *   *   *   *     php  /var/www/html/energy/src/slice_solver.php  cron
10,40  *   *   *   *          /var/www/html/energy/src/cleanup_initialise.sh



