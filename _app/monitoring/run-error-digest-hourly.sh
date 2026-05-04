#!/bin/bash
# Wrapper for cron-error-digest.php --mode=hourly
# Called by crontab to avoid argument parsing issues with long cron lines.
php /home4/cogsaust/public_html/_app/api/cron-error-digest.php --mode=hourly
