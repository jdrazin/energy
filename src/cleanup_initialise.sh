#!/bin/bash

# set target directory
TARGET_DIR="/var/www/html/energy/pids"

# find files older than 3 hours
OLD_FILES=$(find "$TARGET_DIR" -maxdepth 1 -type f -mmin +180)

# if any such files exist then delete them and initialise
if [[ -n "$OLD_FILES" ]]; then
    php  /var/www/html/energy/src/log_db.php "Files older than 3 hours found. Deleting all files in $TARGET_DIR and initialising ..." "ERROR"
    find "$TARGET_DIR" -maxdepth 1 -type f -delete
    php  /var/www/html/energy/src/initialise.php
else
    php  /var/www/html/energy/src/log_db.php "No files older than 3 hours: no intervention" "NOTICE"
fi