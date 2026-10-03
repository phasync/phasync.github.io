---
title: "CakePHP"
description: "Run a CakePHP 5 application on Swerve, one request at a time per worker."
status: draft
order: 4
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
---

> **Low concurrency.** A worker serves one CakePHP request at a time, as a PHP-FPM child does. A request that waits for a database or an API holds its worker, also with `phasync-ext`. Size `--workers` as you size `pm.max_children`. See [Frameworks](/frameworks/).

The adapter serializes requests with [[phasync\Util\Synchronized]]. Cake keeps the current request, `Configure`, the locale, the global event manager and the table registry in static properties, and `$_SESSION` and the output buffers belong to the process: overlapping requests would see each other's.

## Install and run

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve-cakephp
```

<!-- TODO-verify: phasync/swerve-cakephp was not on Packagist on 2026-10-03; the lines are from the adapter's README -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\CakePHP\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=webroot swerve.php
```

The public directory is `webroot`. Set `APP_FULL_BASE_URL` and `SECURITY_SALT`, and `DEBUG=false`, as for PHP-FPM.

## What the adapter changes

- **Once per worker:** the application is bootstrapped with its plugins, its routes are connected and the session is configured, as `Cake\Http\Server` does.
- **After each request:** `Configure`, the router's current request, the global event manager, the application's container, the table registry, DebugKit's panels, the locale and the time zone go back to what they were after boot.
- **Sessions** use PHP's session module and Cake's configured engine. The session id comes from the request's cookie and is forgotten after the request.
- **Cake sees the CLI.** `PHP_SAPI` is `cli`, so the skeleton's `config/bootstrap.php` logs to `logs/cli-*.log`, and plugins marked `onlyCli` are loaded.

## Concurrency

One Cake request at a time per worker. Swerve's other work (connections, static files, WebSockets and streams after their response) goes on meanwhile. See the adapter's [docs/concurrency.md](https://github.com/phasync/swerve-cakephp/blob/main/docs/concurrency.md).

## Dangerous under concurrency

- **Routes are connected once per worker.** Routes connected during a request do not apply. `--watch` and a reload pick up changed routes.
- **Static state of your own** (static properties, singletons) lives as long as the worker. `Configure::write()` during a request is undone after it.
- **Streams that echo hold the worker.** A `CallbackStream` whose callback echoes (Cake's streamed responses) runs while no other Cake request does, since PHP's output buffers belong to the process. For long streams fill a `phasync\Psr\UnbufferedStream` from a coroutine instead, and do not use Cake's router, session or ORM connection in that coroutine.
- **The session belongs to the request.** A coroutine that outlives it, such as a WebSocket callback, gets a `LogicException` if it reads the session. Take the identity in the action: [Long-lived connections](/learn/long-lived-connections/).
- **`exit` and `dd()`** end the worker.

Run `phasync/doctor` over your code:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor src plugins
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
