---
title: "The phasync extension"
description: "The optional extension: blocking I/O in existing code suspends the coroutine, not the process."
order: 2
project: phasync
topic: blocking-calls-and-the-extension
role: concept
---

The phasync extension, ext-phasync, is an optional PHP extension. Inside `phasync::run()`, blocking I/O in code that knows nothing about phasync suspends the coroutine instead of the process, and returns exactly what PHP would have returned, timeouts and warnings included. That covers sockets and TLS, MySQL through mysqli or PDO, curl and Guzzle, pipes and child processes, `sleep()`, DNS lookups, files and filesystem calls, and more. Clients with their own network code, such as PostgreSQL's libpq and phpredis, still block.

It waits with epoll instead of `stream_select()`, so a process can watch any number of sockets. Without it, PHP's `stream_select()` cannot use file descriptors numbered 1024 and up, and a Swerve worker holds at most 512 connections.

## Getting it

ext-phasync is open source, MIT, at [github.com/phasync/ext-phasync](https://github.com/phasync/ext-phasync). Release packages of `phasync/phasync` carry prebuilt binaries under `ext/`: PHP 8.3, 8.4 and 8.5 NTS, on Linux x86-64 and aarch64 (glibc or musl) and macOS arm64 and x86-64. It needs PHP 8.3 or later; phasync itself runs on PHP 8.2 without it.

## Loading it

- `vendor/bin/phasync script.php` loads the bundled binary for a CLI script.
- `phasync\ext\ensure_loaded();` as the first line of a CLI script loads it, restarting the process once.
- `pie install phasync/ext-phasync` builds it from source and enables it in `php.ini`, for every SAPI including PHP-FPM.

## Versions

phasync 2.x works with extension 2.x. Loading a mismatched pair throws a `RuntimeException` naming both versions.

It bundles [fiber-interop/ext-scope](https://github.com/fiber-interop/ext-scope) (the `Scope` class), or uses a separately installed ext-scope, or a PHP build that already has `Scope`, instead.

## One request per coroutine

`phasync\ext\sapi()` gives output buffers, headers, `php://input`, uploaded files, and the session ($_SESSION and the session functions) their own instance per coroutine, so one worker process can run concurrent requests. Session handlers may suspend; concurrent requests that use sessions need `sapi()` — Swerve does.

## Without it

phasync behaves the same with and without the extension. The extension only lets more code wait cooperatively and makes waiting cheaper. Code that must wait without it uses `phasync::sleep()`, `phasync::readable()` and `phasync::writable()`.
