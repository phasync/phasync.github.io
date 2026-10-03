---
title: "Laravel"
description: "Run a Laravel application on Swerve: many requests at once, each in a pooled application of its own."
status: draft
order: 1
see_also:
  - phasync\Util\Pool
  - Swerve::log
---

**Concurrency: concurrent.** A worker serves many requests at once. Each runs in an application of its own, taken from a [[phasync\Util\Pool]] of booted applications. Only Laravel's view engine needs `phasync-ext` for requests to overlap safely (see below).

## Install and run

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require phasync/swerve-laravel
```

<!-- TODO-verify: phasync/swerve-laravel was not on Packagist on 2026-10-03; minimum-stability is dev here and beta for the other adapters, per their READMEs -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laravel\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

`public/index.php` stays as it is, so the application still runs under PHP-FPM. The package installs `laravel/octane` for the listeners that reset an application between requests; Octane's own servers are not used.

## What the adapter changes

- **Applications are pooled.** One is booted on demand per overlapping request, kept as long as that many were in use during the last 60 seconds, and capped at 128. Requests above the cap wait for an application to come free.
- **Octane's listeners reset each application** between requests (the `listeners` in `config/octane.php`, so yours apply too), and the container goes back to what it held after boot.
- **The process-wide pointers are proxied.** `app()`, the facades, Eloquent's connection resolver and similar forward to the application of the request that runs.
- **WebSockets and streams.** A route returns `WebSocket::from()`; the handshake is an ordinary Laravel request and the callback holds no application. `response()->stream()` and `eventStream()` go out as the callback echoes.
- **Output outside the response.** `echo` and `dump()` in a route are not part of the response; return a response instead.

## Concurrency

Requests that wait in a coroutine overlap in one worker. With `phasync-ext`, `sleep()`, PDO and mysqli queries, curl and `flock()` wait that way too. Without it they hold the worker, as under any other server, so size `--workers` like PHP-FPM's `pm.max_children`.

One thing needs `phasync-ext`: Laravel's view engine buffers output with `ob_start()`, and PHP's output buffers belong to the process. A wait inside a view while it renders, such as a lazily loaded relation, mixes the output of requests that overlap there.

## Dangerous under concurrency

The adapter handles Laravel's own state. State your code keeps is yours:

- A static property or a singleton that holds request data is shared by every request that runs in the same application, one after the other, as under Octane, and by overlapping requests when the state is process-level.
- Static toggles such as `Model::unguard()` and `Number::useLocale()` change for every request when they are set during one.
- Registrations made while a request runs (a binding, an alias, a macro, a gate) stay on objects the next request in that application sees. Register at boot, in a provider.
- Caches keyed without the user, tenant or locale.

[Laravel on Swerve: shared state](/learn/laravel/) sorts all of it into three groups and says what to do for each. The adapter's own [shared-state.md](https://github.com/phasync/swerve-laravel/blob/main/docs/shared-state.md) is the reference. <!-- TODO-verify: docs/shared-state.md exists in the swerve-laravel working tree but was not committed or pushed on 2026-10-03; the link may 404 -->

Run `phasync/doctor` over your application to find these patterns:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor app
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/), then [Per-request state](/learn/per-request-state/)
- [Laravel on Swerve](/learn/laravel/): the container, views, toggles and caches
- [Connections and pools](/learn/connections-and-pools/), for database and Redis sizing
- [Frameworks](/frameworks/): the other adapters
