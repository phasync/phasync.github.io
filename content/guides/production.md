---
title: "Production"
description: "Sizing, reloads, logging, limits and the phasync extension."
status: draft
order: 6
project: swerve
topic: production
role: guide
---

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public --ext --max-memory=512M
```

`--workers=auto` (the default) starts one worker per CPU core; each holds its own copy of the application. Fewer can serve more on machines with many cores — measure with your own application.

## Connections per worker

Without [the phasync extension](/library/phasync-ext/), a worker holds at most 512 connections at once: half of PHP's `stream_select()` limit of 1024 file descriptors, since the application opens files and connections of its own too. For many idle or long-lived connections (SSE, long polling, upgraded ones), run more workers than cores — `--workers` at four times the core count is a starting point. With the extension loaded, the limit is the open-file limit less 64 instead (`ulimit -n`; `LimitNOFILE` under systemd), to leave room for the files and connections the application opens on its own.

At the limit, a worker closes connections that are sitting idle to make room for new ones; one still being served, or an upgraded one, is never closed to make room.

## Memory

`--max-memory` (80% of `memory_limit` by default) recycles a worker whose memory grows past it: a replacement starts, the old one finishes the requests it has, and exits once its upgraded connections (WebSockets, SSE) are gone or `--linger` seconds (1800 by default) have passed. `--max-requests` recycles after about that many requests instead, or as well.

`memory_limit` with `-1` (swerve's default override, see below) removes the per-process ceiling PHP would otherwise enforce; bound memory with `--max-memory`, a container limit or a cgroup instead. Count it as the peak of one request times the concurrency you expect: every request a worker is serving at once shares the same process memory, and an out-of-memory fatal error ends every one of them, not only the request that caused it.

## The watchdog

`--watchdog=<seconds>` (30 by default) replaces a worker whose event loop has not turned in that long — CPU-bound code that never waits or yields counts as stuck. The log names the request it was stuck in. Set it to `0` to turn it off; a worker legitimately busy longer than the watchdog for its own reasons needs that, not a raised number that masks a handler that should yield.

## The access log

Swerve logs a line per request (`GET /path 200 1.2ms`) unless started with `--no-access-log`. Turn it off if you log requests yourself further down the chain (a reverse proxy, an APM) and do not need swerve's own line as well.

## php.ini

swerve overrides the settings a shared-hosting `php.ini` makes for a short-lived process — `memory_limit`, `max_execution_time`, `open_basedir`, the opcache validation and preload settings among them — with one restart at start, since it runs as a long-lived process instead. An explicit `-d` on the command line still wins; the full table, with why each setting changes, is in `docs/production.md` of the Swerve repository.

Next: load [the phasync extension](/library/phasync-ext/) before measuring anything — connection limits and the cost of waiting both change with it.
