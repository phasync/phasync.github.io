---
title: "PSR-15"
description: "Run Slim, Mezzio or your own PSR-15 handler on Swerve: no adapter, requests overlap in a worker."
status: draft
order: 7
see_also:
  - Swerve::log
  - phasync::getContext
---

**Concurrency: concurrent.** `swerve.php` returns the handler and Swerve calls it for every request in a coroutine, so requests that wait overlap in one worker. What the handler keeps between requests is yours, and it is the first thing to check.

## Install and run

Swerve serves any file that returns a PSR-15 `RequestHandlerInterface`. For Slim:

```bash
composer require phasync/swerve slim/slim slim/psr7
```

<!-- TODO-verify: the package name phasync/swerve and its availability on Packagist were not checked on 2026-10-03; the stability flags needed while Swerve is in beta are in the adapters' READMEs -->

```php
<?php // swerve.php

require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Swerve\Swerve;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();                            // JSON and form bodies
$app->addErrorMiddleware(true, true, false, Swerve::log());  // error pages; errors in Swerve's log
$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write('Hello, World');

    return $response;
});

return $app;
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

This was run with Slim 4: `GET /` answers 200 `Hello, World`. A plain handler needs no framework: return an object implementing `RequestHandlerInterface` that answers with `new phasync\Psr\Response(200, [], 'Hello')`. <!-- TODO-verify: the plain-handler line was not run here; the constructor is from phasync's source --> [[Swerve::log]] returns the PSR-3 logger that writes to Swerve's log.

Mezzio works the same way: its `Application` is a request handler. The Laminas page has its `swerve.php`: [Laminas](/frameworks/laminas/#mezzio).

## What the adapter changes

There is no adapter. Swerve loads `swerve.php` once per worker, so the application, the container and the routes are built once and shared by every request that worker serves.

## Concurrency

Every request runs in its own coroutine. A request waits in a coroutine (`phasync::sleep()`, phasync's HTTP client, sockets) and with `phasync-ext` `sleep()`, PDO, mysqli and curl wait that way too. Without the extension those hold the worker.

This was run: two requests to a Slim route that waits 0.2 seconds, each with its own `X-User` header, both answered in about 0.2 seconds, each with its own user.

```php
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface {
    return $next->handle($request->withAttribute('user', $request->getHeaderLine('X-User')));
});

$app->get('/me', function (ServerRequestInterface $request, ResponseInterface $response) {
    phasync::sleep(0.2);                                   // another request runs meanwhile
    $response->getBody()->write('hello ' . $request->getAttribute('user'));

    return $response;
});
```

```
GET /me  X-User: ann  ->  hello ann
GET /me  X-User: bob  ->  hello bob
```

## Dangerous under concurrency

- **Request state belongs in the request's attributes**, as above. A property on a middleware or a service object that holds the user, the tenant or the locale is shared by every request in the worker: [Per-request state](/learn/per-request-state/). When it has to live outside the request, key it by [[phasync::getContext]].
- **Shared services hold the last request.** Mezzio's `UrlHelper` holds the last route result; called without a route name it returns the URL of another request in flight. Check what any service you inject keeps between calls: [Service lifetimes](/learn/service-lifetimes/).
- **Singletons that capture scoped state** are captive: [Captive dependencies](/learn/captive-dependencies/).
- **Connections** are shared by every request in the worker: [Connections and pools](/learn/connections-and-pools/).
- **PHP's session module, `$_SESSION` and `ob_*`** belong to the process. Use a PSR-15 session middleware that keeps the session in a request attribute.
- **`exit` and `die()`** end the worker.

Run `phasync/doctor` over your code:

```bash
composer require --dev phasync/doctor
vendor/bin/phasync-doctor src
```

<!-- TODO-verify: phasync/doctor is not published yet; the commands are from its README and were not run here -->

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Services that can be shared](/learn/shareable-services/)
- [Connections and pools](/learn/connections-and-pools/)
- [Frameworks](/frameworks/)
