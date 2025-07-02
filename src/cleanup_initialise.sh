#!/bin/bash

# set target directory
TARGET_DIR="/var/www/html/energy/pids"

# find files older than 3 hours
OLD_FILES=$(find "$TARGET_DIR" -maxdepth 1 -type f -mmin +180)

# if any such files exist then delete them and initialise
if [[ -n "$OLD_FILES" ]]; then
    echo "Files older than 3 hours found. Deleting all files in $TARGET_DIR and initialising ..."
    find "$TARGET_DIR" -maxdepth 1 -type f -delete
    php  /var/www/html/energy/src/initialise.php
else
    echo "No files older than 3 hours: no intervention"
fi