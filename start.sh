#!/bin/bash
# CyberNet ISP - Startup Script

LOG=/home/tserver/cybernet/logs
mkdir -p $LOG

# Kill any existing instances
pkill -f "php -S 0.0.0.0:8090" 2>/dev/null
pkill -f "php -S 0.0.0.0:8091" 2>/dev/null
sleep 1

# Start main portal (port 8090)
nohup php -S 0.0.0.0:8090 -t /home/tserver/cybernet/ > $LOG/portal.log 2>&1 &
echo "Portal started on port 8090 (PID $!)"

# Start captive portal (port 8091)
nohup php -S 0.0.0.0:8091 -t /home/tserver/cybernet/hotspot/ > $LOG/hotspot.log 2>&1 &
echo "Hotspot portal started on port 8091 (PID $!)"

echo "CyberNet services started."
