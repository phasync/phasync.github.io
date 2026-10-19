---
title: "PSR-15"
description: "Run Slim, Mezzio or your own PSR-15 handler on Swerve with the swerve-psr15 adapter: one boot per worker, requests overlap."
status: draft
order: 7
see_also:
  - Swerve::log
  - phasync::onScopeChange
project: swerve
topic: frameworks
role: guide
---

**Concurrency: concurrent.** `swerve.psr15.php` returns your `Psr\Http\Server\RequestHandlerInterface`,
and the adapter runs it once per request in a coroutine of its own, so requests that wait overlap in
one worker. What your handler, your middleware and your container keep between requests is yours to
watch — the first thing to check when two requests answer each other's data.

## Install and run

```bash
composer require phasync/swerve-psr15 slim/slim slim/psr7
```

```php
<?php // swerve.psr15.php

use Slim\Factory\AppFactory;
use Swerve\Swerve;

$app = AppFactory::create();                                  // slim/psr7 installed: its factories build the responses
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, false, Swerve::log());   // error pages; errors in Swerve's log
$app->get('/hello/{name}', function ($request, $response, array $args) {
    $response->getBody()->write("Hello, {$args['name']}");

    return $response;
});

return $app;
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public
```

Installing `phasync/swerve-psr15` adds an adapter: with it the only one installed, `vendor/bin/swerve`
finds it from its own `composer.json` and calls its entry point instead of loading `swerve.php`. The
entry requires the application's own Composer autoloader, then `swerve.psr15.php`, once per worker,
and keeps whatever it returns for every request that worker serves. A file that is missing, or returns
anything but a `RequestHandlerInterface`, stops the worker with exit code 2 and says so in the log —
`swerve.psr15.php not found`, or `returned stdClass; it must return a Psr\Http\Server\RequestHandlerInterface`.
A Mezzio `Application` is a request handler too — see the [Laminas page](/frameworks/laminas/#mezzio).

## What the adapter builds, and what it leaves to you

- **The request is always `phasync\Psr\ServerRequest`**, not an object your framework's own
  `ServerRequestFactoryInterface` constructed. Its body is a lazy stream on the connection — nothing
  is read before your code reads it, and `Expect: 100-continue` is answered by that first read.
  `getParsedBody()` and `getUploadedFiles()` parse a form body
  (`application/x-www-form-urlencoded` or `multipart/form-data`) on first call, under PHP's own
  `post_max_size`, `upload_max_filesize`, `max_file_uploads` and `max_input_vars`, exactly as `$_POST`
  and `$_FILES` would be filled; anything else stays an unparsed stream. `getServerParams()` has
  `REQUEST_METHOD`, `REQUEST_URI`, `SERVER_PROTOCOL`, `REMOTE_ADDR`/`REMOTE_PORT`,
  `SERVER_ADDR`/`SERVER_PORT` and, behind a trusted proxy, `HTTPS`.
- **The response can be anything implementing `ResponseInterface`.** The bridge sends it by calling
  `getStatusCode()`, `getHeaders()` and `getBody()` — it never checks which PSR-17 package built it.
  Composer accepts `psr/http-message` 1 or 2 either way (`^1.0 || ^2.0`); whatever your framework's
  factory package needs is what gets installed. `slim/psr7`, Nyholm or laminas-diactoros all work the
  same way here.
- **The body streams both ways.** A response body is read in chunks and written as they come out, so
  a stream larger than memory is fine as long as it produces its bytes piecemeal; it gets a
  `Content-Length` when you set one or `getSize()` knows it, and is chunked otherwise. A `withBody()`
  in middleware is honoured for the same reason — the bridge reads whatever stream is on the response
  it was handed, not the one the route originally wrote to.

## Concurrency

Every request runs in its own coroutine. A route that waits (`phasync::sleep()`, phasync's own HTTP
client, a socket) lets the worker answer other requests meanwhile; with ext-phasync loaded, `sleep()`,
PDO, mysqli and curl wait that way too, without the application's code changing at all.

```php
$app->add(function ($request, $next) {
    return $next->handle($request->withAttribute('user', $request->getHeaderLine('X-User')));
});

$app->get('/me', function ($request, $response) {
    phasync::sleep(0.2);                                        // another request runs meanwhile
    $response->getBody()->write('hello ' . $request->getAttribute('user'));

    return $response;
});
```

Two requests to `/me`, one with `X-User: ann` and one with `X-User: bob`, both answer in about 0.2 s,
each with its own user — because `user` travels on the request's attributes, not on a property.

## Server-Sent Events

A route returns `Swerve\Psr15\EventStreamResponse::stream()` instead of writing a body. The callback
runs after the route returns, writes with swerve's `ServerSentEvents`, and ends the stream by
returning; a client that left makes `send()` throw `phasync\IOException` — let it leave the loop.

```php
use Swerve\Psr15\EventStreamResponse;
use Swerve\ServerSentEvents;
use Swerve\Swerve;

$app->get('/news', function ($request, $response) {
    return EventStreamResponse::stream(function (ServerSentEvents $sse) {
        foreach (Swerve::subscribe('news', heartbeat: 15) as $message) {
            null === $message ? $sse->comment('keep-alive') : $sse->send($message, event: 'news');
        }
    }, headers: ['access-control-allow-origin' => '*']);
});
```

It is an ordinary 200 response underneath: a CORS or logging middleware's `with*()` clones it and
keeps the callback, so headers added on the way out (and the ones set in `headers:` above) reach the
client with the head. `content-type`, `cache-control` and `x-accel-buffering` are swerve's own, and
any value your response sets for those three is ignored. `Last-Event-ID` is on the request as a normal
header, and also on `ServerSentEvents::lastEventId()`. A `HEAD` request gets the head and no body, and
the callback never runs — there is nothing to stream to.

A PSR-15 route can upgrade to a WebSocket the same way, with `Swerve\Psr15\WebSocketResponse::from()`:
the handler it runs is swerve's own `WebSocket`, so swerve's realtime docs apply unchanged. Take
anything you need from the request (the user, a session) before returning — the handler runs after
the route, over the request's body, and does not see the request object itself.

## What stays per request, what doesn't

Each request runs in a `Scope` of its own (`Scope::getCurrent()`), shared by the coroutines it starts
and freed when the request ends. Request attributes live there; so does anything you key by
`Scope::getCurrent()` yourself when attributes don't fit. Everything else your `swerve.psr15.php`
builds — the Slim app, a container you attach with `AppFactory::setContainer()`, compiled routes,
static properties, globals — belongs to the worker, not the request, and is shared by every request
running in it at once, including concurrently. A container entry that is safe to share (a template
engine, a route cache) is fine to build once there; one that isn't (anything holding the current user,
a query builder with state pending between calls) needs to be built per request or kept off the
container entirely, in favor of a request attribute.

`date_default_timezone_set()` and `Locale::setDefault()` follow the request's `Scope`, so a request
that changes them affects only itself. `setlocale()` does not: it is process state in PHP itself.
Use `Locale::setDefault()` and `intl` formatters instead; neither Slim nor this adapter calls
`setlocale()`.

## Dangerous under concurrency

- **Request state belongs in the request's attributes**, as above. A property on a middleware object
  that holds the user, the tenant or the locale is shared by every request in the worker:
  [Per-request state](/learn/per-request-state/).
- **Shared services hold the last request.** Mezzio's `UrlHelper` holds the last route result; called
  without a route name it can answer with another request's URL. Check what any service you inject
  keeps between calls: [Service lifetimes](/learn/service-lifetimes/).
- **Singletons that capture scoped state** are captive: [Captive dependencies](/learn/captive-dependencies/).
- **A PDO or mysqli handle built once in `swerve.psr15.php` is one connection, shared by every request
  the worker serves.** Without ext-phasync a query on it blocks the whole worker for its duration —
  short queries cost little, long ones cost every request in flight. With ext-phasync, MySQL through
  PDO or mysqli waits as a coroutine instead (PostgreSQL's libpq — `pdo_pgsql`, `pgsql` — still
  blocks). Either way, don't hand the same connection to two requests that might both be mid-query at
  once: [Connections and pools](/learn/connections-and-pools/).
- **PHP's session module, `$_SESSION` and `ob_*`** belong to the process. Use a PSR-15 session
  middleware that keeps the session in a request attribute, not these.
- **`exit` and `die()`** end the worker, and every request it was serving.

## Your application

Slim, Mezzio and any other PSR-15 stack run requests concurrently in one worker, so your own code decides whether that is safe: middleware and handlers that keep request state in properties, and container definitions that hand every request the same instance (a `PDO`, a "current user" service), are what breaks. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

To find candidates in your own code, run [phasync/doctor](https://github.com/phasync/doctor) from the application root: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`. It reads `composer.lock`, says which adapter you run and whether requests overlap, and reports each static, global, shared connection or process setting with the sites that write and read it.

<!-- doctor-report:begin -->
### What phasync/doctor reports on a fresh PSR-15 app

slim/slim-skeleton 4.5.0 with phasync/swerve-psr15 0.1.1, scanned by phasync/doctor 0.1.1 on 2026-10-19.

swerve-psr15 runs PSR-15 requests concurrently in one worker: a middleware or handler keeping request state in a property, or a container definition handing out one shared instance (a PDO connection), is shared by overlapping requests.

| Severity | Rule | Symbol | Location | Question |
| --- | --- | --- | --- | --- |
| warning | session | `App\Application\Middleware\SessionMiddleware::process(): session` | `src/Application/Middleware/SessionMiddleware.php:20` | Whose session does this read or write when requests share the worker? |
| warning | output-buffering | `App\Application\ResponseEmitter\ResponseEmitter::emit(): ob_get_contents()` | `src/Application/ResponseEmitter/ResponseEmitter.php:32` | Can the request wait (a query, an HTTP call, a sleep) while this buffer is open? |
| warning | output-buffering | `App\Application\ResponseEmitter\ResponseEmitter::emit(): ob_clean()` | `src/Application/ResponseEmitter/ResponseEmitter.php:33` | Can the request wait (a query, an HTTP call, a sleep) while this buffer is open? |
| info | global-handler | `public/index.php: register_shutdown_function()` | `public/index.php:67` | Does this run for every request? |

See [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the fix for each finding.
<!-- doctor-report:end -->

## Debugging

- **Changes don't show**: workers load the application once and don't watch files; run with
  `--watch` while developing, or reload with `kill -HUP <master pid>`.
- **`swerve.psr15.php not found`, or exit code 2 with "returned X; it must return a ..."**: the file
  is missing, or didn't return a `RequestHandlerInterface` — the log line says what it returned.
- **`Can't create a coroutine outside of a coroutine`**: `phasync::go()` ran outside swerve's event
  loop — a script invoked on its own, not a request or `swerve.psr15.php` itself while it builds the
  application.
- **`-vv`** logs a debug line per request; **`kill -QUIT <worker pid>`** makes a stuck worker log what
  it's serving and where.

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Services that can be shared](/learn/shareable-services/)
- [Connections and pools](/learn/connections-and-pools/)
- [Frameworks](/frameworks/)
