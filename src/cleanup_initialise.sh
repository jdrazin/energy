#!/bin/bash

# set target directory
TARGET_DIR="/var/www/html/energy/pids"

# find files older than 3 hours
OLD_FILES=$(find "$TARGET_DIR" -maxdepth 1 -type f -mmin +30)

# if any such files exist then delete them and initialise
if [[ -n "$OLD_FILES" ]]; then
    php  /var/www/html/energy/src/log_db.php "Old semaphores: initialising ..." "ERROR"
    killall -KILL php-fpm
    find "$TARGET_DIR" -maxdepth 1 -type f -delete
    php  /var/www/html/energy/src/initialise.php
else
    php  /var/www/html/energy/src/log_db.php "No old semaphores" "NOTICE"
fi