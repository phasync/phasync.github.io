---
title: "Yii"
description: "Run a Yii 3 application on Swerve, one request at a time per worker."
status: draft
order: 5
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
project: swerve
topic: frameworks
role: guide
---

**Concurrency: serialized.** A worker runs one Yii request at a time ([[phasync\Util\Synchronized]]), as a PHP-FPM child does. Yii keeps a request's state in the container's own services — `CurrentRoute`, `CurrentUser`, the session — and a container per request in flight would still share PHP's native session, so the adapter takes a different route from Laravel's or Symfony's pooled applications: it locks. Yii 3 (`yiisoft/app`) only; Yii 2 is not covered.

## Install and run

```bash
composer require phasync/swerve-yii
```

```bash
vendor/bin/swerve --adapter=yii --http=0.0.0.0:8080 --public=public
```

That's the setup: the `yii` adapter runs the skeleton's `src/bootstrap.php` (the autoloader and `.env`) and serves the application, with `APP_ENV` and `APP_DEBUG` read from the environment, as the skeleton already reads them. `public/index.php` is untouched, so the same application still answers under PHP-FPM.

For a debug flag or environment that isn't `APP_ENV`/`APP_DEBUG`, write `swerve.php` instead and run `--adapter=swerve`:

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/src/bootstrap.php'; // yiisoft/app's: the autoloader and .env

return Swerve\Psr15\Bridge::wrap(new Swerve\Yii\Handler(__DIR__, debug: false, environment: 'prod'));
```

## How it runs

- **Once per worker:** `Swerve\Yii\Handler`'s constructor builds the configuration and the container with the skeleton's `bootstrap-web`, `events-web`, `di-web` and `params-web` groups — the same groups `yiisoft/yii-runner-roadrunner` uses — registers the container's `ErrorHandler`, runs the bootstrap group and starts the `Application`, dispatching `ApplicationStartup`.
- **Per request:** `Application::handle()` runs the middleware stack; an exception that escapes it goes to `ErrorCatcher`. Then `AfterEmit` is dispatched — before Swerve sends the response, not after as under PHP-FPM — `StateResetter` runs the `reset` callbacks of the container's definitions (`CurrentRoute`, `RequestProvider`, `Session`, `CurrentUser`, `WebView`, the translator's locale, the URL generator's default arguments, ...), and `session_id('')` plus `$_SESSION = []` clear PHP's native session for the next request.
- **One known gap:** `yiisoft/session`'s `Flash` remembers the last session id it saw and has no `reset`, so without a workaround the same visitor's next request would see an already-shown flash message again ([yiisoft/session#2](https://github.com/yiisoft/session/issues/2), open upstream since 2019). The adapter clears Flash's private session id itself after every request; this is a standing [tracked issue](https://github.com/phasync/swerve-yii/issues/1) until it's fixed upstream.

## What a request-scoped service of your own needs

Anything in your container that keeps a value across a request's lifetime needs a `reset` definition, the way `yiisoft/user`'s own setup does:

```php
// config/web/di/user.php
use Yiisoft\User\CurrentUser;

return [
    CurrentUser::class => [
        'withSession()' => [Reference::to(SessionInterface::class)],
        'reset'         => function () {
            $this->clear();
        },
    ],
];
```

Without one, the next request — on the same worker, which is guaranteed to be a *different* visitor's request given the lock — reads what the last request left. The adapter's test suite checks this directly: `tests/OneWorkerTest.php` fires eight requests at once at a single worker, each setting its own value in `CurrentRoute`, `RequestProvider`, the session, `Flash`, `CurrentUser`, the translator's locale, `WebView`'s title and the URL generator's default arguments, then reads them back after a wait — and repeats the round in the sessions the first round started, to catch anything the reset missed.

## Sessions

`yiisoft/session` sits on PHP's native session module: one `$_SESSION` and one `session_status()` per worker process, not per request. The lock means only one request has a session open at a time, so this is safe as written, but it is why the adapter can't simply hand out a container per overlapping request the way the Laravel and Symfony adapters do — PHP's session would still leak between them. A visitor without a cookie gets a fresh session even right after one with a cookie was served by the same worker.

## Dangerous under a worker that outlives the request

Requests don't overlap in Yii itself here, so what's dangerous is code that keeps running, or reads container state, after the lock releases:

- **A streamed body, an SSE producer or a WebSocket callback runs after the reset.** Read the user id, route arguments and session values *before* returning the response, and close over them — don't read `CurrentUser` or `CurrentRoute` from inside the callback, since by then the worker may be serving anyone:

  ```php
  use Swerve\Psr15\WebSocketResponse;
  use Swerve\WebSocket;
  use Yiisoft\User\CurrentUser;

  final readonly class NewsAction   // Route::get('/news')->action(NewsAction::class)
  {
      public function __invoke(ServerRequestInterface $request, CurrentUser $user): ResponseInterface
      {
          $userId = $user->getId();   // taken now, while this request still holds the lock

          return WebSocketResponse::from($request, static function (WebSocket $ws) use ($userId) {
              foreach (Swerve::subscribe("user:$userId") as $message) {
                  $ws->send($message);
              }
          });
      }
  }
  ```

  Server-Sent Events work the same way with `Swerve\Psr15\EventStreamResponse::stream()`: the callback also runs after the route returned and the reset ran.
- **The application's `ErrorHandler` is registered once, for the whole worker's life.** A PHP warning becomes an `ErrorException` — a 500 page in an ordinary request, as under PHP-FPM — in any coroutine that outlives its request too, such as a stream's producer.
- **`exit`, `die` and `dd()` end the worker**, and every request it was serving with it.
- **On a shutdown or reload, Swerve stops a worker at once.** A request still waiting is answered `503` by Swerve, not `ErrorCatcher`'s page — `config/events-web.php` of the adapter listens for the `WorkerStoppingException` the `ErrorCatcher` middleware caught and throws it back on so Swerve can take over. Size `--workers` the way you'd size PHP-FPM's `pm.max_children`: a request that waits on a database or an API holds its worker, the lock included, even with ext-phasync loaded.

## Config, params, events

Nothing here is Swerve-specific: `yiisoft/config`'s merge order and `params.php` run once, at container build, the same as under any other runner. An event listener registered in `events-web.php` lives for the worker, not the request — a listener that accumulates into a property of itself (counting something, say) carries that count across every request the worker serves, which is usually not what a request-scoped listener should do.

## Your application

The adapter runs one request at a time, but the worker outlives every request: a static or a service property set by one request is still there for the next, and code you run in a WebSocket or SSE callback, or in `phasync::go()`, does overlap with other requests. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

To find candidates in your own code, run [phasync/doctor](https://github.com/phasync/doctor) from the application root: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`. It reads `composer.lock`, says which adapter you run and whether requests overlap, and reports each static, global, shared connection or process setting with the sites that write and read it.

Two routes would let the adapter (or your application) drop the lock for the state above. The simple one: save and restore that state in a `phasync::onScopeChange()` hook, which runs whenever the worker switches from one request to another. It is phasync's own API (an application relying on it runs on phasync only), it runs on every switch so it should swap only a few references, and it covers only the state you swap. The proper one: key the state on the request's `\Scope` (`Scope::getCurrent()`, the [Scope RFC](https://wiki.php.net/rfc/scope)), so nothing needs swapping. `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, the default time zone and `Locale::setDefault()` already follow the `Scope`; `$_SESSION` and `setlocale()` do not.

## Debugging

`Swerve::log()` is a PSR logger; it and anything your own listeners write go to Swerve's log alongside its own request lines (method, path, status, duration). `yii-debug`'s panels aren't exercised by the adapter's test suite — treat a debug or profiler service the same as any other container service that accumulates request data: give it a `reset`, or expect it to mix requests. The isolation test in `tests/OneWorkerTest.php` is the pattern worth copying for your own services: set a distinct value per request, send several requests at once, read the value back, and run it both without and with ext-phasync.

## Compatibility

| Yii | PHP | ext-phasync |
|---|---|---|
| 3 (`yiisoft/app` 1.x) | 8.2 – 8.5 | optional; tested with and without |

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Service lifetimes](/learn/service-lifetimes/) and [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
