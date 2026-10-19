---
title: "Deploy"
description: "Run Swerve behind nginx or HAProxy, under systemd or in Docker."
status: draft
order: 3
---

Swerve runs in the foreground and logs to standard output, as systemd, Docker and supervisord
expect: there is no daemon mode.

## systemd

```ini
# /etc/systemd/system/myapp.service
[Unit]
Description=myapp
After=network.target

[Service]
User=myapp
WorkingDirectory=/srv/myapp/current
ExecStart=/usr/bin/php vendor/bin/swerve --http=127.0.0.1:8080 --public=public
ExecReload=/bin/kill -HUP $MAINPID
Restart=always
LimitNOFILE=1048576
# Longer than --grace (30s), so swerve's own drain finishes first
TimeoutStopSec=40

[Install]
WantedBy=multi-user.target
```

<!-- TODO-verify: not installed as a real systemd unit on this machine; the ExecReload mechanism
is the plain SIGHUP below, which was run directly against a live swerve master and confirmed. -->

`systemctl reload myapp` sends `SIGHUP`: every worker stops (a request in flight gets a `503`),
then swerve restarts in place, same PID, running the current code. I sent the same signal
directly to a running master and saw this in its log:

```
2026-10-19 15:52:11.08   Reload requested (SIGHUP): stopping 2 workers, then restarting swerve
2026-10-19 15:52:11.44   swerve 0.1.0 serving ./swerve.php on http://127.0.0.1:18742 with 2 workers
2026-10-19 15:52:11.44   Reload complete in 0.36 s
```

A request made right after the reload succeeded: deploy the new code, then reload. Connections
arriving during the brief restart are refused, and `Swerve::cache()` starts empty.
`journalctl -u myapp` has the logs.

## Docker

```dockerfile
FROM php:8.4-cli
RUN docker-php-ext-install pcntl sockets
WORKDIR /app
COPY . .
CMD ["vendor/bin/swerve", "--http=:8080", "--public=public"]
```

I built this image and ran it; `--workers=auto` (the default) saw the host's full CPU count
inside the container, and both the application route and a static file under `public/` answered:

```
> docker run -p 8080:8080 myapp
2026-10-19 15:51:22.59    swerve 0.1.0 serving ./swerve.php on http://0.0.0.0:8080 with 56 workers
2026-10-19 15:51:24.48 21 GET / 200 0.4ms
2026-10-19 15:51:24.50 38 GET /hello.txt 200 0.9ms
```

`docker stop` sends `SIGTERM` and waits 10 seconds by default, which is less than swerve's own
`--grace` (30 s): give it more time (`docker stop -t 40`, or `stop_grace_period: 40s` in Compose),
or lower `--grace`.

## A reverse proxy in front: TLS, and streaming responses

Swerve serves plain HTTP only; for HTTPS, put nginx, Caddy or HAProxy in front of it. For nginx,
terminating TLS and forwarding SSE and WebSocket upgrades through:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl;
    http2 on;
    server_name app.example.com;
    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;      # send each Server-Sent Event on at once
        proxy_read_timeout 1h;    # keep long-lived requests open
    }
}
```

<!-- TODO-verify: no nginx binary on this machine to run `nginx -t` against this config; taken
directly from docs/production.md of the swerve repository. -->

Caddy does the same with `reverse_proxy 127.0.0.1:8080`. Swerve only trusts `X-Forwarded-For`,
`-Proto` and `-Host` from a proxy you name with `--trusted-proxy=<ip|range|unix>`; from any other
peer, swerve reads them as whatever the client sent, which means nothing.

## Sizing and the extension

`--workers=auto` is one per CPU core; a worker without the phasync extension holds at most 512
connections, so for many idle connections (long polling, SSE) run more workers than cores, or
load the extension with `--ext`, or install it once for every start with
`pie install phasync/ext-phasync`. `--max-memory` (80% of `memory_limit` by default) recycles a
worker that outgrows it: the old one finishes its requests while a new one takes over, so a
recycle drops nothing.

Give the process manager more time than `--grace` (systemd's `TimeoutStopSec`, Docker's
`--time`), and set `sysctl net.ipv4.tcp_migrate_req=1` so connections still queued on a closing
listener move to the new worker instead of being reset; swerve logs a hint at startup, with `-v`,
when it is 0.
