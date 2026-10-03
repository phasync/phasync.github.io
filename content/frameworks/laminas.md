---
title: "Laminas"
description: "Run a Laminas MVC or Mezzio application on Swerve. MVC serves one request at a time per worker."
status: draft
order: 6
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
---

> **Low concurrency (Laminas MVC).** A worker serves one Laminas MVC request at a time, as a PHP-FPM child does. A request holds its worker until its action returns, also with `phasync-ext`. Size `--workers` as you size `pm.max_children`. See [Frameworks](/frameworks/). For Mezzio, which is concurrent, see [Mezzio](#mezzio).

The adapter serializes requests with [[phasync\Util\Synchronized]]. Laminas reads the request from the superglobals, which the handler fills from the PSR-7 request as PHP-FPM would; `RemoteAddress`, the `ServerUrl` helper, laminas-session and `$_SESSION` are all per process. The adapter's tests show each of them leaking between overlapping requests without the lock.

## Install and run

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve-laminas
```

<!-- TODO-verify: phasync/swerve-laminas was not on Packagist on 2026-10-03; the lines are from the adapter's README -->

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Laminas\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

## What the adapter changes

- **Once per worker:** the application configuration is read as `config/container.php` reads it, and one application is booted to load classes and write the configuration cache.
- **Per request:** a new `Laminas\Mvc\Application` from `Application::init()`, with its own ServiceManager, modules, request, response and `MvcEvent`, as under PHP-FPM. Laminas has no reset for its shared services (the layout view model, the head title, the route match), so reusing an application would repeat the title once for every earlier request.
- **Sessions** use laminas-session with PHP's native sessions. After each request the handler writes and closes the session, sends the cookie and cache headers PHP-FPM sends, and makes PHP forget the session id.
- **Responses:** the Laminas response becomes a PSR-7 response. A `Laminas\Http\Response\Stream` is sent as it is read. An action may return a PSR-7 response, such as a WebSocket.

## Concurrency

One MVC request at a time per worker. Other connections (static files, keep-alive, WebSockets) go on meanwhile, and a WebSocket callback or a streamed body does not hold the worker. See the adapter's [docs/concurrency.md](https://github.com/phasync/swerve-laminas/blob/main/docs/concurrency.md).

## Mezzio

Mezzio needs no adapter: it is a PSR-15 application, and its requests run concurrently in a worker. See the [PSR-15 page](/frameworks/psr-15/). Its `swerve.php` is Mezzio's `public/index.php` returning the application:

```php
<?php // swerve.php

chdir(__DIR__);
require 'vendor/autoload.php';

$container = require 'config/container.php';
$app       = $container->get(Mezzio\Application::class);
$factory   = $container->get(Mezzio\MiddlewareFactory::class);
(require 'config/pipeline.php')($app, $factory, $container);
(require 'config/routes.php')($app, $factory, $container);

return $app;
```

<!-- TODO-verify: from the swerve-laminas README; not run here -->

Request state belongs in the request's attributes. Mezzio's `UrlHelper` is a shared service holding the last route result: called without a route name, it returns the URL of another request in flight.

## Dangerous under concurrency

- **A WebSocket callback reads no session and no identity of its own.** Take them in the action and make the callback `static`: [Long-lived connections](/learn/long-lived-connections/).
- **`SessionManager` leaks memory per request** if you get the service on every request, as laminas-session's documentation does in `onBootstrap()`: about 3 KiB a request. Set `--max-requests`, or a `memory_limit` so that `--max-memory` recycles workers.
- **`header()`, `setcookie()`, `http_response_code()` and `echo`** do not reach the client. Set headers and cookies on the Laminas response.
- **Uploads** are PSR-7 `UploadedFileInterface` objects: `is_uploaded_file()` and `move_uploaded_file()` do not know them. Call `moveTo()` on the upload.
- **`session_set_save_handler()` in `swerve.php`** is gone after the first request with a session. Set the handler through laminas-session's `SaveHandlerInterface` service.
- **`exit` and `die()`** end the worker.

Run `phasync/doctor` over your code:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor module config
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/)
- [PSR-15](/frameworks/psr-15/) for Mezzio and Slim
- [Frameworks](/frameworks/)
