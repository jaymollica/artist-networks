#!/bin/bash
# Run all insight reports in sequence. A single report crashing must NOT abort
# the whole run — otherwise one broken script (e.g. a schema drift) silently
# starves build_digest.php and subscribers get an empty digest. So we DON'T use
# `set -e`: each report runs independently, failures are logged and counted, and
# build_digest.php always runs at the end against whatever reports succeeded.
cd /var/www/artist-networks/scripts

LOG=/var/log/artist-networks/insights-cron.log
PHP=/usr/bin/php
FAILED=()

echo "==== insights run started $(date -u +%FT%TZ) ====" >> "$LOG"

for script in \
    ingest_attendance.php \
    insight_triangulation.php \
    insight_chains.php \
    insight_hubs.php \
    insight_gaps.php \
    insight_sleeper.php \
    insight_nepo.php \
    insight_concentration.php \
    insight_donor_capture.php \
    trustees_build.php \
    insight_trustees.php \
    insight_moneyball.php
do
    echo "-- $script --" >> "$LOG"
    if ! "$PHP" "$script" >> "$LOG" 2>&1; then
        echo "!! $script FAILED, continuing" >> "$LOG"
        FAILED+=("$script")
    fi
done

# Assemble the digest from all the freshly-generated insight files. Built every
# run, but only emailed monthly (first Sunday — see the send_digest_email cron).
echo "-- build_digest.php --" >> "$LOG"
"$PHP" build_digest.php >> "$LOG" 2>&1

if [ ${#FAILED[@]} -gt 0 ]; then
    echo "!! ${#FAILED[@]} report(s) failed this run: ${FAILED[*]}" >> "$LOG"
fi

echo "==== insights run finished $(date -u +%FT%TZ) ====" >> "$LOG"
