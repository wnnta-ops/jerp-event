#!/bin/sh
# J-ERP PHP-FPM healthcheck.
# Tries cgi-fcgi against /ping (configured in php-fpm.conf); falls back to pgrep.
set -e

if command -v cgi-fcgi >/dev/null 2>&1; then
    SCRIPT_NAME=/ping \
    SCRIPT_FILENAME=/ping \
    REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null
    exit $?
fi

# Fallback: process check
if pgrep -f "php-fpm: master" >/dev/null 2>&1; then
    exit 0
fi

exit 1
