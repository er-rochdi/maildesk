#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
exec ./node_modules/.bin/concurrently --kill-others --names queue,scheduler 'while true; do php artisan queue:work --tries=1 --timeout=240 || printf "Worker arrêté (code %s), redémarrage dans 1 s.\n" "$?" >&2; sleep 1; done' 'php artisan schedule:work'
