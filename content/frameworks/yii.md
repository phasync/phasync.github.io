---
title: "Yii"
description: "Run a Yii 3 application on Swerve, one request at a time per worker."
status: draft
order: 5
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
---

> **Low concurrency.** A worker serves one Yii request at a time, as a PHP-FPM child does. A request that waits for a database or an API holds its worker, also with `phasync-ext`. Size `--workers` as you size `pm.max_children`. See [Frameworks](/frameworks/).

The adapter serializes requests with [[phasync\Util\Synchronized]]. Yii keeps a request's state in shared services of its container, and its session is PHP's native session, one per process: a container per request in flight would still share it.

## Install and run

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve-yii
```

<!-- TODO-verify: phasync/swerve-yii was not on Packagist on 2026-10-03; the lines are from the adapter's README -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/src/bootstrap.php'; // yiisoft/app's: the autoloader and .env

return new Swerve\Yii\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

<!-- TODO-verify: the Handler line and the bootstrap require are from the adapter's README; not run here -->

## What the adapter changes

- **Once per worker:** the configuration and container are built, the `bootstrap-web` group runs and `Application::start()` dispatches `ApplicationStartup`, as in yiisoft/yii-runner-roadrunner.
- **After each request:** `AfterEmit` is dispatched, `StateResetter` runs the `reset` callbacks of the container's definitions (`CurrentRoute`, `Session`, `CurrentUser` and others), and PHP's `session_id()` and `$_SESSION` are cleared.
- **Sessions** are yiisoft/session through PHP's session module, with the save handler you configure.
- **Streams and WebSockets** go on after the request's turn and do not hold the worker.

## Concurrency

One request at a time per worker. Swerve's other work (connections, static files, WebSockets, streamed bodies) goes on meanwhile. The adapter's [docs/concurrency.md](https://github.com/phasync/swerve-yii/blob/main/docs/concurrency.md) says why and what was tried.

## Dangerous under concurrency

Requests do not overlap in Yii itself, so the danger is in what outlives the request:

- **A service of your own that keeps request state** needs a `reset` callback in its container definition, as Yii's own services have. Without one the next request sees that state: [Service lifetimes](/learn/service-lifetimes/).
- **A streamed body, an SSE producer or a WebSocket callback** runs after the reset. Take the user id, route arguments and session values before returning the response. Read inside it, those services show another request's state, another visitor's user included: [Long-lived connections](/learn/long-lived-connections/).
- **The application's `ErrorHandler`** is registered for the whole worker, so a PHP warning becomes an `ErrorException` also in a coroutine that outlives its request.
- **`AfterEmit`** is dispatched when the application returns the response, before Swerve sends it.
- **`exit`, `die` and `dd()`** end the worker.

Run `phasync/doctor` over your code:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor src
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Service lifetimes](/learn/service-lifetimes/) and [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
