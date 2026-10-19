---
title: "Laminas"
description: "Run a Laminas MVC or Mezzio application on Swerve. MVC rebuilds the application per request and serializes it; Mezzio runs concurrently."
status: draft
order: 6
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
  - Swerve::subscribe
project: swerve
topic: frameworks
role: guide
---

**Concurrency: serialized for MVC, concurrent for Mezzio.** The `laminas` adapter covers laminas-mvc: one request at a time per worker, with a fresh `Laminas\Mvc\Application` for each, the way a PHP-FPM child builds one from nothing. Mezzio needs no adapter — it is a PSR-15 handler, and [swerve-psr15](/frameworks/psr-15/) runs its requests concurrently, same worker, same container. See [Mezzio](#mezzio) below.

## Install and run

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.swerve-psr15 vcs https://github.com/phasync/swerve-psr15
composer require phasync/swerve-laminas
```

<!-- TODO-verify: phasync/swerve-laminas and phasync/swerve-psr15 were not on Packagist on 2026-10-19; minimum-stability dev and the vcs repository are from the adapter's README, which still pins phasync/swerve to dev-main rather than the 0.1.0 tag -->

No `swerve.php` to write: the package's `composer.json` names `laminas` as its adapter, which provides the entry point.

```bash
vendor/bin/swerve --adapter=laminas --http=0.0.0.0:8080 --public=public
```

`public/index.php` is untouched, so the same application still runs under PHP-FPM.

## Booting and the ServiceManager

A worker reads `config/application.config.php` once at start (merged with `config/development.config.php` when present), then calls `Application::init()` once to load classes and let Laminas write its module and config caches to disk. Nothing from that boot application is kept.

Every request after that gets its own `Application::init()` call, and so its own ServiceManager, plugin managers, Request, Response and `MvcEvent`, exactly as under PHP-FPM. This isn't a missed optimization — Laminas gives several of its services nowhere to reset to. The layout view model, `headTitle()` and the other placeholder view helpers, `Container::$defaultManager`, the route match: an application that served a second request without rebuilding would carry the first request's title and layout into it. A new application does pay for the module manager's config merge again on every request unless `config_cache_enabled` is on — leave it on in development too if the skeleton's `development.config.php` turned it off, or every request re-runs the full module boot the one-time constructor call was meant to skip.

Because configuration is only read from disk at worker start, editing `config/autoload/*.php` or `development.config.php` while workers are running has no effect until they restart: reload swerve (`SIGHUP`) or run with `--watch` in development.

## Concurrency

Requests take turns per worker, through [[phasync\Util\Synchronized]]. A request holds its worker until its action returns — a database query waits cooperatively with ext-phasync loaded, but still keeps the turn — so size `--workers` the way you'd size `pm.max_children`. WebSocket callbacks and streamed response bodies run after the action has returned and don't hold the worker; other connections (static files, keep-alive, open sockets) are served while a request is locked out.

Each request's `Application` is its own, but what sits below the application is the process:

- **`$_SERVER`.** Laminas reads the request from the superglobals, which the handler fills and clears around the lock. `RemoteAddress` and the `ServerUrl` view helper read `$_SERVER` directly, later than the Request object copies it, so they need the lock too.
- **PHP's session module and `$_SESSION`.** One per process, read and written by `SessionArrayStorage` on every access, lock or no lock.
- **`Container::$defaultManager`**, set by every request's `SessionManagerFactory`: a `Laminas\Session\Container` made without naming a manager uses whichever request's `SessionManager` was built last.
- **Statics an application sets per request**, such as the doctype helper and `Locale::setDefault()`. These aren't a reason for the lock by themselves — code that sets the process's locale per request is wrong under any concurrent server — but they're one more reason not to run MVC requests concurrently without rewriting that code first.

None of this touches a database connection by itself: a `Laminas\Db\Adapter\Adapter` or a Doctrine connection built by a factory lives in the request's ServiceManager, so it's as fresh, and as uninteresting across requests, as under PHP-FPM — commit or roll back before the action returns and the connection goes with the application. The risk starts only if you move a connection out of the ServiceManager to skip reconnecting (a static, or a service built once in `swerve.php`'s bootstrap and injected everywhere). That connection now outlives the request: a transaction left open at the end of one action is still open at the start of the next, and a WebSocket callback or a published subscriber running in the same worker must never touch it while a request holds it.

## Sessions

laminas-session runs on PHP's native session as configured (`session_config`, `session_manager`, save handlers). After every request the handler writes and closes the session, sends the cookie and the `cache_limiter` headers PHP-FPM would send, and makes PHP forget the session id — so the next request, with no cookie of its own, never continues someone else's session.

```php
public function loginAction()
{
    $result = $this->auth->authenticate($adapter);
    if (!$result->isValid()) {
        $this->getResponse()->setStatusCode(401);
        return new JsonModel(['user' => null]);
    }
    Container::getDefaultManager()->regenerateId(); // as always, against fixation

    return new JsonModel(['user' => $this->auth->getIdentity()]);
}
```

laminas-authentication, flash messages and CSRF forms work as written: `FlashMessenger` is session-backed and the message is gone after the next request reads it, same as under PHP-FPM.

`SessionManager` registers a shutdown function in its constructor and the worker never shuts down between requests, so that function — and the manager — stays alive until the worker recycles. An application that fetches the `SessionManager` service on every request, as laminas-session's own documentation does in `onBootstrap()`, grows by a few KiB a request ([phasync/swerve-laminas#1](https://github.com/phasync/swerve-laminas/issues/1), open upstream). Set `--max-requests`, or a `memory_limit` so `--max-memory` recycles workers — the CLI's default `memory_limit` of `-1` turns that limit off.

## Quirks worth knowing

- **Uploads are PSR-7, not `$_FILES['tmp_name']` you can hand to `move_uploaded_file()`.** The handler fills `$_FILES` from `UploadedFileInterface` objects, so Laminas' file inputs validate them, but `is_uploaded_file()` and `move_uploaded_file()` don't recognize the paths. `RenameUpload` moves them with `moveTo()`, given PSR-17 factories:

  ```php
  $file->getFilterChain()->attach(new RenameUpload([
      'target'              => $dir,
      'stream_factory'      => new Laminas\Diactoros\StreamFactory(),
      'upload_file_factory' => new Laminas\Diactoros\UploadedFileFactory(),
  ]));
  ```

- **Streamed responses and WebSockets must come from a controller action, not a middleware route.** A `laminas-mvc-middleware` route runs its PSR-7 response through laminas-psr7bridge, which buffers it before Laminas sends it. `Laminas\Http\Response\Stream` returned from an action is sent as it is read (its file deleted afterward when `setCleanup()` was called), and an action may return a PSR-7 response outright — `Swerve\Psr15\WebSocketResponse::from()`, given the PSR-7 request the handler attaches as the Laminas request's metadata:

  ```php
  public function newsAction()
  {
      $user = $this->auth->getIdentity();                 // take it before from(); none inside the callback

      return WebSocketResponse::from($this->getRequest()->getMetadata(ServerRequestInterface::class),
          static function (Swerve\WebSocket $ws) use ($user) {
              foreach (Swerve::subscribe('news') as $message) {
                  $ws->send($message);
              }
          });
  }
  ```

  Make the callback `static`. A closure written in a controller method closes over `$this`, which keeps the whole application — modules, container, everything — alive for as long as the socket is open. A `static` closure keeps only what it `use`s.

- **An unhandled exception is Laminas' own error page**, through the ordinary `MvcEvent::EVENT_DISPATCH_ERROR` / render pipeline — nothing about that changes. A worker told to stop raises a `WorkerStoppingException` instead, which the adapter catches ahead of Laminas' own error listeners: a request still in flight gets swerve's `503`, not the application's error template.
- **`echo`, `var_dump()` and anything else written outside the response go to swerve's terminal**, not the browser: the handler buffers process output to `STDOUT` so PHP's session functions don't object to output having been sent. Reach for `Swerve::log()` or the response body instead of `echo` when you want the client to see something.

## Dangerous under concurrency

- **`header()`, `setcookie()` and `http_response_code()` don't reach the client.** Set headers and cookies on the Laminas response.
- **`session_set_save_handler()` called in `swerve.php`'s bootstrap is gone after the first request that opens a session.** Register it through laminas-session's `SaveHandlerInterface` service, or in `onBootstrap()`, so it's set up again inside every application.
- **`exit` and `die()` end the worker** — and every request it was serving alongside this one, not just the current response.

## Mezzio

Mezzio is a PSR-15 handler, so it runs through [swerve-psr15](https://github.com/phasync/swerve-psr15)'s bridge directly, with no `laminas` adapter involved:

```php
<?php // swerve.psr15.php, as public/index.php

chdir(__DIR__);
require 'vendor/autoload.php';

$container = require 'config/container.php';
$app       = $container->get(Mezzio\Application::class);
$factory   = $container->get(Mezzio\MiddlewareFactory::class);
(require 'config/pipeline.php')($app, $factory, $container);
(require 'config/routes.php')($app, $factory, $container);

return $app;
```

<!-- TODO-verify: from the swerve-laminas README; not exercised by its test suite, which covers laminas-mvc and the WebSocket adapter, not Mezzio -->

Unlike the MVC case, the container above is built once per worker and every request runs through the same instance, concurrently with the others — there is no lock, and no per-request rebuild to paper over a shared service. `UrlHelper` holds the last route it matched as instance state: call it without a route name from one request while another is mid-flight, and you can get the other request's URL back. Keep request data — the matched route, the authenticated user, anything else per-request — in the PSR-7 request's attributes, which `Mezzio\Router\Middleware\RouteMiddleware` and friends already use that way, not in a service you mutate.

The same follows for a database connection registered as a shared ServiceManager service: it's now one connection used by every concurrent request in the worker, not one per request. A transaction an earlier request didn't close is still open when the next one starts on the same connection, and two requests reading or writing it at once is two coroutines sharing one handle — size connections per request (a factory service, or a small pool) rather than a shared singleton. The same applies to session persistence: `Mezzio\Session\Ext\PhpSessionPersistence` goes through PHP's native session module, which is one per process exactly as under laminas-mvc, but here with nothing serializing requests to protect it. A cache-backed persistence that never touches `$_SESSION` is the safer default for Mezzio on Swerve.

A Mezzio handler returns `WebSocketResponse::from()` the same way, with the user taken from the request's attributes before the call:

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute(UserInterface::class)?->getIdentity();

    return WebSocketResponse::from($request, static function (Swerve\WebSocket $ws) use ($user) {
        foreach (Swerve::subscribe("user:$user") as $message) {
            $ws->send($message);
        }
    });
}
```

See [PSR-15](/frameworks/psr-15/) for what else applies to any PSR-15 application running concurrently on Swerve.

## Your application

Laminas MVC runs one request at a time, but the worker outlives every request, and Mezzio runs requests concurrently: in both, a static or a shared service's property set by one request can be seen by another. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

Two routes would let the adapter (or your application) drop the lock for the state above. The simple one: save and restore that state in a `phasync::onScopeChange()` hook, which runs whenever the worker switches from one request to another. It is phasync's own API (an application relying on it runs on phasync only), it runs on every switch so it should swap only a few references, and it covers only the state you swap. The proper one: key the state on the request's `\Scope` (`Scope::getCurrent()`, the [Scope RFC](https://wiki.php.net/rfc/scope)), so nothing needs swapping. `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, the default time zone and `Locale::setDefault()` already follow the `Scope`; `$_SESSION` and `setlocale()` do not.

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/), for WebSocket callbacks and streamed responses
- [PSR-15](/frameworks/psr-15/), for Mezzio and Slim
- [Frameworks](/frameworks/)
