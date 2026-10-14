#!/bin/sh
# Container healthcheck for every role of the control-plane image (the entrypoint records the role).
set -eu
role="$(cat /tmp/falak-role 2>/dev/null || echo web)"
case "$role" in
  web | agent-api) exec curl -fsS -o /dev/null --max-time 8 http://127.0.0.1:8080/up ;;
  reverb)    exec php -r 'exit(@fsockopen("127.0.0.1", (int) (getenv("REVERB_SERVER_PORT") ?: 8080)) ? 0 : 1);' ;;
  horizon)   php /app/artisan horizon:status 2>/dev/null | grep -qi running ;;
  scheduler) kill -0 1 ;;
  relay)     exec curl -fsS -o /dev/null --max-time 4 http://127.0.0.1:8080/healthz ;;
  *)         exit 0 ;;
esac
