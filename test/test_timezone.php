<?php
  $datetime = new DateTime('2025-04-07 12:34:56');
  echo  $datetime->format('Y-m-d H:i:s') . PHP_EOL;
  $datetime->setTimezone(new DateTimeZone('Europe/London'));
  echo  $datetime->format('Y-m-d H:i:s') . PHP_EOL;

