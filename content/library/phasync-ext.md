---
title: "The phasync extension"
description: "The optional extension: blocking I/O in existing code suspends the coroutine, not the process."
order: 2
project: phasync
topic: blocking-calls-and-the-extension
role: concept
---

The phasync extension is an optional PHP extension. Inside `phasync::run()`, blocking I/O in code that knows nothing about phasync suspends the coroutine instead of the process, and returns exactly what PHP would have returned, timeouts and warnings included. That covers sockets and TLS, MySQL through mysqli or PDO, curl and Guzzle, pipes and child processes, `sleep()`, DNS lookups, files and filesystem calls, and more. Clients with their own network code, such as PostgreSQL's libpq and phpredis, still block.

It waits with epoll instead of `stream_select()`, so a process can watch any number of sockets. Without it, PHP's `stream_select()` cannot use file descriptors numbered 1024 and up, and a Swerve worker holds at most 512 connections.

## Getting it

There is nothing to install: release packages of `phasync/phasync` carry the prebuilt binaries under `ext/` (PHP 8.2 to 8.5 on Linux, x86-64 and ARM64, glibc and musl). They are closed source, free to use and redistribute under the licence in `ext/LICENSE`, and optional. phasync and Swerve themselves are MIT licensed.

## Enabling it

- In `composer.json` of your project: `"extra": {"phasync": {"ext": true}}`. phasync does not act on it by itself; tools such as Swerve read it with `phasync\ext_enabled()` and load the extension.
- `swerve --ext` loads it even if `composer.json` does not enable it.
- In a CLI script, as the first line: `phasync\try_enable_ext();` loads the bundled binary, restarting the process once.
- Under PHP-FPM, `extension=<path to ext/phasync-<php>-nts-<arch>-<libc>.so>` in `php.ini`.

## Limits

Without a licence file, at most 4 blocking calls overlap per worker; the next one blocks as in stock PHP, and a warning is printed once. A signed licence file lifts the limit. It is read from the ini setting `phasync.license`, the environment variable `PHASYNC_LICENSE_FILE`, or `./phasync.license`, and `phasync\ext\license()` shows what is in effect.

## Without it

phasync behaves the same with and without the extension. The extension only lets more code wait cooperatively and makes waiting cheaper. Code that must wait without it uses `phasync::sleep()`, `phasync::readable()` and `phasync::writable()`.
