---
title: "CodeIgniter"
description: "Run a CodeIgniter 4 application on Swerve through its worker mode, one request at a time per worker."
status: draft
order: 3
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
---

> **Low concurrency.** A worker serves one CodeIgniter request at a time. The others wait their turn, and a request that waits for a database or an API holds its worker, also with `phasync-ext`. Size `--workers` for the requests that wait at the same time. See [Frameworks](/frameworks/).

The adapter serializes requests with [[phasync\Util\Synchronized]]. CodeIgniter shares its services, `config()` and `model()` instances, the default locale, `$_SESSION` and the database connection among a worker's requests.

## Install and run

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve-codeigniter
```

<!-- TODO-verify: phasync/swerve-codeigniter was not on Packagist on 2026-10-03; the lines are from the adapter's README -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\CodeIgniter\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

`app/Config/WorkerMode.php` must exist (the CodeIgniter 4.7 skeleton has it; an upgraded application copies it from the skeleton), and `configCacheEnabled` and `locatorCacheEnabled` in `app/Config/Optimize.php` must be off, or the worker refuses to start.

## What the adapter changes

- **Once per worker:** what `public/index.php` does, then `Boot::bootWorker()`, CodeIgniter's own worker-mode boot.
- **Per request:** the PSR-7 request becomes the superglobals, the `Superglobals` service and an `IncomingRequest`. After the response, the resets of `app/Config/WorkerMode.php` run: `Services::resetForWorkerMode()`, `Factories::reset()`, `Events::cleanupForWorkerMode()`, open transactions rolled back, connections checked.
- **Sessions** use CodeIgniter's session library with any driver. The session is written at the end of each request and PHP's session id is set from the request's cookie.
- **Errors** are logged and rendered by CodeIgniter's handler, without the `exit()` that would end the worker.
- **`is_cli()` is `false`** in the workers, as under PHP-FPM.

## Concurrency

One request at a time per worker, with the other connections of the worker (static files, keep-alive, WebSockets, streamed responses after their request) going on meanwhile. Why, and what was tried: [docs/concurrency.md](https://github.com/phasync/swerve-codeigniter/blob/main/docs/concurrency.md).

## Dangerous under concurrency

- **`$persistentServices` in `app/Config/WorkerMode.php`** live as long as the worker. Do not add any that hold request data: [Service lifetimes](/learn/service-lifetimes/).
- **WebSocket callbacks** run after the request. Use what the controller passed in, not `session()` or `$this->request`, which show another visitor's data by then: [Long-lived connections](/learn/long-lived-connections/).
- **A custom exception handler** must not call `exit()`.
- **`exit`, `die()` and `dd()`** end the worker.
- **Every open WebSocket is a connection of one worker.** Without `phasync-ext` a worker holds about 960.

Run `phasync/doctor` over your code:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor app
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
