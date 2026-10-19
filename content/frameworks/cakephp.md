---
title: "CakePHP"
description: "Run a CakePHP 5 application on Swerve: booted once per worker, one request at a time, like PHP-FPM."
status: draft
order: 4
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
project: swerve
topic: frameworks
role: guide
---

**Concurrency: serialized, one request at a time per worker.** `Swerve\CakePHP\Handler` takes [[phasync\Util\Synchronized]] around every request: a worker runs one Cake request to completion before starting the next, as a PHP-FPM child does. Size `--workers` as you size `pm.max_children`. Swerve's own work — accepting connections, serving static files, and anything already answered that's now a WebSocket or an SSE stream — goes on meanwhile. See [Frameworks](/frameworks/).

## Install and run

```bash
composer require phasync/swerve-cakephp
```

```bash
vendor/bin/swerve --adapter=cakephp --http=0.0.0.0:8080 --public=webroot
```

`--adapter=cakephp` serves `App\Application` with no `swerve.php`: `webroot/index.php` keeps running the same application under PHP-FPM. For another application class, or other settings, write `swerve.php` and run with `--adapter=swerve` instead:

```php
<?php // swerve.php, next to composer.json

return Swerve\Psr15\Bridge::wrap(new Swerve\CakePHP\Handler(__DIR__, application: My\Application::class));
```

Set `APP_FULL_BASE_URL`, `SECURITY_SALT` and `DEBUG=false`, as for PHP-FPM: with debug off, the skeleton's `HostHeaderMiddleware` refuses every request while `App.fullBaseUrl` is empty.

## What boots once, and what runs per request

`Handler`'s constructor does what `Cake\Http\Server` does, once: it calls `$app->bootstrap()` and `pluginBootstrap()`, connects the routes with a route builder, and configures the session. It then snapshots `Configure::read()`, the event manager's listeners, the locale, the time zone and `Router`'s request context, and keeps a closure that restores them.

Each request, under the lock:

- The snapshot is restored first — `Configure`, `Router::$_request`/`$_requestContext`/`$_fullBaseUrl`, the global `EventManager::instance()`'s listeners, `FactoryLocator::get('Table')->clear()` (so `fetchTable()` returns fresh `Table` instances, not another request's with its entity state), DebugKit's panel registry, the locale, the time zone, and the application's container and controller factory — as a fresh PHP-FPM process would have them.
- A `RoutingMiddleware` that skips `loadRoutes()` stands in for Cake's, in the middleware queue `Handler` builds the way `Cake\Http\Server::run()` does (so `Server.buildMiddleware`, DebugKit's middleware and your own still run). Routes connected or depended on during a request don't apply until a restart or `--watch` picks up a reload.
- `RequestFactory::fromPsr()` builds a `Cake\Http\ServerRequest` from Swerve's PSR-7 request — headers, cookies, query, body and uploaded files — the way `ServerRequestFactory::fromGlobals()` would from superglobals. There are no superglobals to read: `$_GET`, `$_POST`, `$_FILES` play no part.
- `ConnectionManager`'s connections, and your `Cache` and `Log` engines, are not part of the reset. They are built once at boot and reused by every request, like a persistent connection under PHP-FPM's `pdo_pconnect`, except they're genuinely shared with the next request on the same worker. An uncommitted transaction left open by an exception stays open into the next request that uses that connection: wrap writes in `$connection->transactional()` rather than manual `begin()`/`commit()`, so an exception rolls back before the lock is released.

## The session

`Swerve\CakePHP\Session` extends Cake's own, using PHP's session module and your configured engine, not the fake `'cli'` session Cake's `Session` falls back to when `PHP_SAPI` isn't `'cgi'`/`'fpm-fcgi'`. The session id comes from the request's cookie (PHP's own cookie lookup would read the worker's `$_COOKIE`, which belongs to no particular request); `begin()` takes it at the start of a request, `finish()` writes and closes it, clears `$_SESSION`, and returns the `Set-Cookie` and no-cache headers `header()` would otherwise have sent. `renew()` (Cake's `getSession()->renew()`, used at login) gives a new id without a `header()` call.

Every read reaches `started()` first, which checks the session against the `Scope` of the request that opened it:

```php
public function started(): bool
{
    if (null === $this->scope || \Scope::getCurrent() !== $this->scope) {
        throw new \LogicException('The session belongs to its request, which is over: ...');
    }
    return parent::started();
}
```

A coroutine that outlives the request — a WebSocket callback, a `phasync::go()` closure started from the action — throws `LogicException` if it reads `$this->request->getSession()`. Take what you need before starting it:

```php
$user = $this->Authentication->getIdentity();
$room = $this->request->getSession()->read('room');

return CakeResponse::from(WebSocketResponse::from($this->request, function (WebSocket $ws) use ($user, $room) {
    // $user and $room are this connection's, fixed at the time the request answered
}));
```

Since only one request runs at a time per worker, `$_SESSION` being process-wide isn't a race here the way it would be in a concurrent adapter — it's `begin()`/`finish()` forgetting the session between requests that keeps one visitor's session from leaking into the next.

## Uploads, streamed responses and WebSockets

Uploaded files arrive as Cake's own `UploadedFile` objects (`$this->request->getData('file')`, with `getClientFilename()`, `getSize()`, `getStream()`), built by `RequestFactory` from Swerve's request the same way `ServerRequestFactory::fromGlobals()` builds them from `$_FILES`.

Cake's streamed responses (`CallbackStream`, `JsonStreamResponse`) still hold the worker: PHP's output buffers belong to the process, so `Handler` runs the callback under an `ob_start()` handler and the same `Synchronized` lock, feeding what it echoes to the client as it comes — no other Cake request runs until the stream ends.

```php
public function stream(): Response
{
    return $this->response->withType('text/plain')->withBody(new CallbackStream(function () {
        echo "first\n";
        $this->wait(0.5);   // the worker's other Cake requests wait too
        echo "last\n";
    }));
}
```

For Server-Sent Events and other long-lived streams, fill a `phasync\Psr\UnbufferedStream` from a coroutine instead. It holds no lock, so the worker serves other requests while it's open:

```php
public function events(): Response
{
    $stream = new UnbufferedStream(1, 10);
    phasync::go(function () use ($stream) {
        for ($i = 1; $i <= 3; $i++) {
            $stream->append("data: event $i\n\n");
            phasync::sleep(0.2);
        }
        $stream->end();
    });

    return $this->response->withType('text/event-stream')->withBody($stream);
}
```

Don't use the router, the session or the ORM's connection inside that coroutine: by the time it runs, the worker may already be serving another request. WebSockets work the same way: a controller action returns `CakeResponse::from(WebSocketResponse::from($this->request, function (WebSocket $ws) { ... }))`, and the callback runs in its own coroutine after the action returns, beside the worker's later requests. `Swerve::publish()`/`Swerve::subscribe()` give it server push from any route in any worker. Inside the callback, `Router::getRequest()` and `Router::url()` describe whichever request the worker serves then — maybe someone else's — and the session throws, as above.

## Dangerous under concurrency

- **Routes connect once per worker.** Connect them in `routes.php`, not conditionally from a request; a reload (`--watch` or `SIGHUP`) picks up changes.
- **Static state of your own** — static properties, singletons — lives as long as the worker. `Configure::write()` during a request is undone by the next request's reset, same as everything Cake itself keeps static.
- **`Server.terminate` listeners run after the response is handed to Swerve**, through the same lock, in turn with the worker's requests, session closed.
- **`exit` and `dd()` end the worker**, and every request it's holding.
- **A stop ends requests in flight.** On shutdown or reload, Swerve stops a worker at once: a request still waiting is answered `503` (Cake neither logs it nor renders an error page); clients retry.
- **Cake sees the CLI.** `PHP_SAPI` is `'cli'`, so the skeleton's `config/bootstrap.php` logs to `logs/cli-debug.log`/`logs/cli-error.log` instead of the usual files, plugins marked `onlyCli` (Bake, Migrations) load, and with debug on, PHP warnings and notices go to the worker's standard error, not the page.

## DebugKit

DebugKit's plugin builds one panel registry at boot; `Handler`'s reset calls `registry()->reset()` on it before each request, so the toolbar reflects that request alone, and `/debug-kit/toolbar/<id>` keeps working. It costs nothing extra to leave DebugKit loaded between requests — it isn't rebuilt — but it still does its usual per-request work (profiling the request, the SQL log), so treat `DEBUG=true` workers the way you'd treat a debug PHP-FPM pool: fine for staging, not for a worker also carrying production traffic.

## Your application

The adapter runs one request at a time, but the worker outlives every request: a static or a table's state set by one request is still there for the next, and WebSocket callbacks and streams fed by `phasync::go()` run outside the lock, overlapping with other requests. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

To find candidates in your own code, run [phasync/doctor](https://github.com/phasync/doctor) from the application root: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`. It reads `composer.lock`, says which adapter you run and whether requests overlap, and reports each static, global, shared connection or process setting with the sites that write and read it.

Two routes would let the adapter (or your application) drop the lock for the state above. The simple one: save and restore that state in a `phasync::onScopeChange()` hook, which runs whenever the worker switches from one request to another. It is phasync's own API (an application relying on it runs on phasync only), it runs on every switch so it should swap only a few references, and it covers only the state you swap. The proper one: key the state on the request's `\Scope` (`Scope::getCurrent()`, the [Scope RFC](https://wiki.php.net/rfc/scope)), so nothing needs swapping. `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, the default time zone and `Locale::setDefault()` already follow the `Scope`; `$_SESSION` and `setlocale()` do not.

<!-- doctor-report:begin -->
### What phasync/doctor reports on a fresh CakePHP app

cakephp/app 5.4.0 with phasync/swerve-cakephp 0.1.0, scanned by phasync/doctor 0.1.1 on 2026-10-19.

swerve-cakephp runs one request at a time per worker, behind a phasync\Util\Synchronized lock: Router's current request, Configure, the global event manager, the table registry, the locale, the time zone, the session and the output buffers are CakePHP's per-process state; the adapter restores them after boot for each request. A request that waits (a query, an API call) holds its worker, also with ext-phasync: capacity scales with --workers, sized as PHP-FPM children. Statics, settings and services still outlive the request: what one request leaves behind, the next one sees.

| Severity | Rule | Symbol | Location | Question |
| --- | --- | --- | --- | --- |
| error | exit | `bin/cake.php: exit` | `bin/cake.php:10` | Can this run while the worker serves a request? |
| error | exit | `config/bootstrap.php: exit` | `config/bootstrap.php:88` | Can this run while the worker serves a request? |
| warning | process-setting | `config/bootstrap.php: mb_internal_encoding()` | `config/bootstrap.php:116` | Is this set to the same value for every request? |
| warning | process-setting | `config/bootstrap.php: ini_set()` | `config/bootstrap.php:122` | Is this set to the same value for every request? |
| warning | process-setting | `webroot/index.php: $_SERVER` | `webroot/index.php:20` | Is this set to the same value for every request? |
| info | scope-managed | `config/bootstrap.php: date_default_timezone_set()` | `config/bootstrap.php:111` |  |

See [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the fix for each finding.
<!-- doctor-report:end -->

## Debugging

- A worker's standard error is where uncaught warnings and the output of a wrongly-placed `echo` land; `headers_sent()` turns true for the rest of that worker's life after one (sessions still work after it — `Session` doesn't check it).
- A request stuck in a blocking call holds up every other request of that worker; `--watchdog` kills and replaces a worker whose loop doesn't turn for too long, and the log says where it was stuck.
- `fetchTable()` returning stale entity state across requests almost always means code kept a `Table` or entity in a static property or a long-lived service instead of fetching it again — the per-request `FactoryLocator` clear only reaches Cake's own registry.

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
