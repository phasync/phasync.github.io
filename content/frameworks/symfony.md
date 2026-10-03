---
title: "Symfony"
description: "Run a Symfony application on Swerve: each in-flight request has a pooled kernel of its own."
status: draft
order: 2
see_also:
  - phasync\Util\Pool
  - Swerve::log
---

**Concurrency: concurrent, through `phasync-ext`.** Each request borrows a kernel from a [[phasync\Util\Pool]] and a kernel serves one request at a time. Requests overlap while one waits in a coroutine, which for database queries, curl and `sleep()` means with `phasync-ext` loaded. Without it requests rarely overlap and the pool stays at one kernel.

## Install and run

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve-symfony
```

<!-- TODO-verify: phasync/swerve-symfony was not on Packagist on 2026-10-03; the lines are from the adapter's README -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Symfony\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

`public/index.php` stays as it is. `Handler` takes `kernels: 16`, the most kernels per worker, and `frontController:`.

## What the adapter changes

- **One kernel per request in flight.** Symfony Runtime resolves the front controller as under PHP-FPM, and one kernel is booted. More are made when requests overlap, up to `kernels:`. They share the classes and the compiled container.
- **Services are reset between requests** by Symfony's own `services_resetter` and every `kernel.reset` service, as in Symfony's FrankenPHP and Swoole runners. `kernel.terminate` runs once Swerve has the response.
- **Sessions need the adapter's storage.** PHP's session module keeps one session per process, so you configure `Swerve\Symfony\SessionStorageFactory` with a handler of your choice (PDO, Redis, Memcached). PHP's own file handlers are refused.
- **WebSockets and streams.** A controller returns `WebSocket::from()`, a PSR-7 response. A `StreamedResponse` is sent as its callback echoes and holds its kernel until it ends.

## Concurrency

A kernel's `RequestStack`, security token, session and stateful services belong to its request alone, so Symfony's own services need no care. What the kernels of a worker share is the process:

- `\Locale::setDefault()` (which `Request::setLocale()` calls), `setlocale()`, `date_default_timezone_set()`.
- Static properties of your own code.
- PHP's output buffers.

`kernels: 1` serves one request at a time per worker. A slow request then holds up the other connections of its worker.

## Dangerous under concurrency

- **Process-wide settings.** Changing the locale or time zone in a request changes it for every request in the worker. Pass the locale to formatters explicitly.
- **Output buffers.** A callback that does `ob_start()`, waits, then `ob_get_clean()` may catch another request's output. Do not wait inside your own output buffer.
- **Open streams hold kernels.** Each open Server-Sent Events stream in a `StreamedResponse` takes one. Set `kernels:` above the streams a worker holds at once, or return a PSR-7 response with a `phasync\Psr\UnbufferedStream` body fed by a coroutine, which holds none.
- **Code that outlives the controller** (a coroutine, a WebSocket callback) must not use the kernel's services: they serve the next request by then. Take the user and session data first.
- **Services that keep request data** must implement `kernel.reset`, as they do under any long-running Symfony runtime.

See [Service lifetimes](/learn/service-lifetimes/) for how singleton, scoped and ephemeral services map onto this.

Run `phasync/doctor` over your code to find static properties, `ob_*` calls and process settings:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor src
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Service lifetimes](/learn/service-lifetimes/) and [Captive dependencies](/learn/captive-dependencies/)
- [Connections and pools](/learn/connections-and-pools/)
- [Frameworks](/frameworks/)
