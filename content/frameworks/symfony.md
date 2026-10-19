---
title: "Symfony"
description: "Run a Symfony application on Swerve: one kernel per in-flight request, booted once and kept warm."
status: draft
order: 2
see_also:
  - phasync\Util\Pool
  - Swerve::log
project: swerve
topic: frameworks
role: guide
---

[swerve-symfony](https://github.com/phasync/swerve-symfony) runs an unmodified Symfony
application on swerve. `public/index.php` stays Symfony Runtime's own front controller, so the
same application still runs under PHP-FPM; swerve calls it once per worker through this
package's entry point instead.

## Install and run

```bash
composer config minimum-stability dev    # phasync and swerve are on their development versions
composer config prefer-stable true
composer require phasync/swerve-symfony
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public
```

No `swerve.php`: swerve finds this package's entry point from its own `composer.json`
(`extra.swerve`). `public/index.php` is untouched.

## Kernel lifecycle

A worker boots one kernel at start, through `SymfonyRuntime` exactly as under PHP-FPM: `.env`
files, `APP_ENV`, `APP_DEBUG`, `extra.runtime` in `composer.json`. A request borrows a kernel
from a pool and calls `handle()`; when every existing kernel is busy, the pool boots another —
its own container, built from the same compiled container class, so the extra boot is cheap
(about 120 KiB in the adapter's test app). Idle kernels above the peak used in the last 30
seconds are let go.

Symfony resets the kernel's services before its next request — `services_resetter`, every
`kernel.reset` service — the same mechanism Symfony's FrankenPHP and Swoole runners rely on.
`kernel.terminate` runs once the response is sent, before the kernel goes back to the pool.
Anything already tagged `kernel.reset` by a bundle keeps working with no change:

- **Doctrine.** `doctrine-bundle`'s `Registry` service is tagged `kernel.reset`. On reset it
  calls `clear()` on an open `EntityManager` (dropping the identity map) and rebuilds one that an
  exception left closed. A closed EM from a failed transaction is never reused by the next
  request on that kernel.
- **The profiler and debug toolbar** (`profiler`, `debug.stopwatch`, `debug.log_processor`) are
  tagged `kernel.reset` too, so they work per request in `APP_ENV=dev`, same as under PHP-FPM.
- **Monolog is not.** `monolog-bundle` tags nothing `kernel.reset`. A `FingersCrossedHandler` or
  other buffering handler keeps its buffer across requests on the same kernel unless you tag it
  yourself; a plain handler (no buffering) has nothing to carry over.
- **Your own stateful services** need `kernel.reset` (a `reset()` method, tagged, or
  `ResetInterface`) exactly as under any other long-running Symfony runner.

## Concurrency inside one worker

Each kernel in the pool has its own container, so a request borrows its own `EntityManager`,
its own DBAL connection, its own `RequestStack`, security token and session — not shared with
whichever other requests are in flight on the same worker. A transaction one kernel holds is
invisible to the others; it doesn't serialize them, it just keeps that one kernel (and its
connection) busy for as long as it runs.

What a worker's kernels do share is the process, not the container:

- `\Locale::setDefault()` (which `Request::setLocale()` calls), `setlocale()`,
  `date_default_timezone_set()` — change these in a request and every other request in the
  worker sees the change. Pass the locale to formatters explicitly instead.
- Static properties and globals in your own code.
- PHP's output buffer stack (see Streaming below).

## Sessions

PHP's session module keeps one session per process, so Symfony's file-based session storage
doesn't work here. `Swerve\Symfony\SessionStorageFactory` gives each request a session loaded
and saved through a `\SessionHandlerInterface` of your choice, with no `session_start()`:

```yaml
# config/packages/framework.yaml
framework:
    session:
        storage_factory_id: Swerve\Symfony\SessionStorageFactory
        handler_id: '%env(DATABASE_URL)%'   # or redis://..., or a handler service's id
```

```yaml
# config/services.yaml
services:
    Swerve\Symfony\SessionStorageFactory: ~
```

PHP's own handlers (files, the default) are refused with a clear exception: they need the
session module. Data is `serialize()`d, not PHP's session format, so existing file sessions
aren't read after the switch. With `PdoSessionHandler` on SQLite, set `lock_mode: 0`: its
default lock holds a transaction for the whole request, and SQLite's file-level write lock then
serializes every session write across every kernel and worker, not just the two requests
sharing a session id.

## File uploads and request bodies

Form uploads are streamed straight to temporary files and handed to the controller as ordinary
`UploadedFile` instances. A raw body — JSON, a `PUT` payload, anything `Request::getContent()`
reads directly — is buffered in memory before the controller runs, capped by `--max-body`
(8 MiB by default).

## Streaming and Server-Sent Events

A `StreamedResponse` (or Symfony 7.3's `EventStreamResponse`) streams as its callback echoes;
`BinaryFileResponse` streams the file, with `Range` support. Both hold their kernel until they
end, so each one in flight costs a kernel.

```php
#[Route('/ticks')]
public function ticks(): StreamedResponse
{
    return new StreamedResponse(static function () {
        for ($n = 1; ; ++$n) {
            echo "data: tick $n\n\n";
            \flush();
            phasync::sleep(1);   // other requests on this worker run meanwhile
        }
    }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
}
```

Two things to know before relying on this:

- **Echo from inside the callback itself, not from a coroutine it starts.** The adapter routes
  output by the fiber running the callback; bytes echoed by a `phasync::go()` child fiber are
  dropped, not sent ([phasync/swerve-symfony#4](https://github.com/phasync/swerve-symfony/issues/4), open).
- **With ext-phasync's CPU preemption, two concurrent streamed responses can corrupt each
  other's output.** The callback's output buffer sits on PHP's one process-wide `ob_*` stack;
  preemption can interleave two callbacks' `ob_start()`/`ob_end_clean()` pairs out of order even
  with no explicit wait in either one ([phasync/swerve-symfony#2](https://github.com/phasync/swerve-symfony/issues/2), open). Until this is fixed, avoid
  pure-CPU loops between echoes in a route you expect to run concurrently with another
  streamed response.

A slow client doesn't slow the worker: what a `StreamedResponse` echoes faster than the client
reads is kept in memory, so send large files with `BinaryFileResponse` instead, which is read as
the client takes it.

## WebSockets

A controller returns `Swerve\Symfony\WebSocketResponse::from()`, an ordinary Symfony `Response`.
The kernel goes back to the pool before the handler runs, so read the user and session first:

```php
#[Route('/chat')]
public function chat(Request $request): Response
{
    $user = $this->getUser()?->getUserIdentifier();

    return WebSocketResponse::from($request, static function (WebSocket $ws) use ($user) {
        foreach ($ws as $message) {
            $ws->send("$user: $message");
        }
    });
}
```

A `static` closure keeps the controller (and its container) out of the handler, which is right:
by the time it runs, the kernel it came from is serving someone else.

## Cache warmup, env vars, debug and memory

`.env` files and `APP_ENV`/`APP_DEBUG` are read once per worker, at boot, through Symfony
Runtime — changing them needs a reload, not just a new request. With `APP_ENV=prod` set, run
`bin/console cache:warmup` before starting workers: the container class is compiled once and
shared by every kernel in every worker, so warming it ahead of time avoids paying for it when
the first worker starts. `APP_DEBUG=1` keeps the profiler and toolbar working per request (above); it
also keeps each kernel's debug data (stopwatch, log processor) for longer, so dev workers use
more memory per kernel than prod ones. `memory_limit` bounds the whole worker, not one request:
budget for the kernels you expect in flight at once, not for one.

## Before you deploy

`exit()` and `dd()` end the whole worker, and every request it is currently serving — not just
the one that called them. A plain controller's stray `echo` goes to swerve's log, not the
response body; only `StreamedResponse`'s callback output reaches the client.

## Your application

Requests overlap in a worker, each in a kernel of its own from the pool, so services are per request. Static properties, globals and process-wide settings are not: they are shared by every kernel in the worker. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

## Debugging

Run one worker during development (`vendor/bin/swerve -w 1 -v --public=public`): request order
and kernel identity become deterministic, which matters when a bug only shows up with requests
overlapping. `vendor/bin/swerve --version` prints the ext-phasync version when `--ext` loaded it.

`Handler::handle()` currently costs about twice what `$kernel->handle()` + `terminate()` cost on
their own — profiled to the `phasync::go()` that runs `kernel.terminate` after the response
([phasync/swerve-symfony#1](https://github.com/phasync/swerve-symfony/issues/1), open, root cause identified, fix pending). Not
something your application code can work around; it affects every request by a fixed amount.

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and [Per-request state](/learn/per-request-state/)
- [Service lifetimes](/learn/service-lifetimes/) and [Captive dependencies](/learn/captive-dependencies/)
- [Connections and pools](/learn/connections-and-pools/)
- [Frameworks](/frameworks/)
