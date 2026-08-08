#!/bin/bash
# Java Sales Hub — control script.
# Usage:  ./hub.sh [restart|start|stop|status|logs|open|ip|rebuild]
set -e
LABEL="com.javasaleshub.web"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
DIR="/Users/azizawiennetaputri/Desktop/Claude Sales"
UID_NUM="$(id -u)"

case "${1:-status}" in
  restart)
    echo "Restarting…"
    launchctl kickstart -k "gui/$UID_NUM/$LABEL"
    sleep 5
    curl -s -o /dev/null -w "server: %{http_code}\n" http://localhost:3000/login
    ;;
  start)
    launchctl load "$PLIST" 2>/dev/null || true
    echo "Started."
    ;;
  stop)
    launchctl unload "$PLIST" 2>/dev/null || true
    echo "Stopped."
    ;;
  status)
    if launchctl list | grep -q "$LABEL"; then
      echo "RUNNING (pid $(launchctl list | grep "$LABEL" | awk '{print $1}'))"
    else
      echo "NOT running.  Run: ./hub.sh start"
    fi
    curl -s -o /dev/null -w "http check: %{http_code}\n" http://localhost:3000/login 2>/dev/null || echo "not responding yet"
    ;;
  logs)
    tail -n 40 -f "$DIR/.server.log"
    ;;
  open)
    open "http://localhost:3000"
    ;;
  ip)
    IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null)"
    echo "Share this on the same WiFi:  http://$IP:3000"
    ;;
  sync)
    # Re-point login (NEXTAUTH_URL) to the Mac's current IP, then restart.
    IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null)"
    if [ -z "$IP" ]; then echo "No WiFi IP found — are you connected to WiFi?"; exit 1; fi
    /usr/bin/sed -i '' "s|^NEXTAUTH_URL=.*|NEXTAUTH_URL=\"http://$IP:3000\"|" "$DIR/.env"
    echo "Login URL set to http://$IP:3000 — restarting…"
    launchctl kickstart -k "gui/$UID_NUM/$LABEL"
    sleep 5
    curl -s -o /dev/null -w "server: %{http_code}\n" http://localhost:3000/login
    echo "✅ Share on same WiFi:  http://$IP:3000"
    ;;
  rebuild)
    echo "Rebuilding (after code changes)…"
    cd "$DIR" && npx next build && launchctl kickstart -k "gui/$UID_NUM/$LABEL"
    sleep 5
    curl -s -o /dev/null -w "server: %{http_code}\n" http://localhost:3000/login
    ;;
  *)
    echo "Usage: ./hub.sh [restart|start|stop|status|logs|open|ip|rebuild]"
    ;;
esac
