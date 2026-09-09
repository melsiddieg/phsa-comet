#!/bin/sh
# COMET container health check.
#
# Deliberately uses only PHP and /proc — no wget, curl, pgrep or ps. Those
# differ between Alpine and Debian bases (Debian's php image ships none of
# wget/pgrep/ps), and a health check that depends on them silently reports
# "unhealthy" on a perfectly working container.
#
#   app    → HTTP GET /up must succeed
#   queue  → PID 1 must still be the queue worker
set -e

case "${CONTAINER_ROLE:-app}" in
    queue)
        # entrypoint.sh `exec`s the worker, so it is PID 1.
        grep -qa 'queue:work' /proc/1/cmdline
        ;;
    *)
        php -r '
            $port = getenv("PORT") ?: "8080";
            $ctx  = stream_context_create(["http" => ["timeout" => 4, "ignore_errors" => true]]);
            $body = @file_get_contents("http://127.0.0.1:$port/up", false, $ctx);
            if ($body === false) { exit(1); }
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match("#^HTTP/\S+\s+(\d{3})#", $h, $m)) { exit($m[1] === "200" ? 0 : 1); }
            }
            exit(1);
        '
        ;;
esac
