---
title: "CodeIgniter"
description: "Run a CodeIgniter 4 application on Swerve through its own worker mode, one request at a time per worker."
status: draft
order: 3
see_also:
  - phasync\Util\Synchronized
  - Swerve::log
project: swerve
topic: frameworks
role: guide
---

CodeIgniter 4.7 shipped its own worker mode for FrankenPHP: `Boot::bootWorker()`,
`app/Config/WorkerMode.php`, `CodeIgniter::resetForWorkerMode()`. The Swerve adapter doesn't add
a second one — it calls the same hooks. If your application already runs under worker mode,
this is the same contract with Swerve underneath it instead.

## Install and run

```bash
composer require phasync/swerve-codeigniter
```

```bash
vendor/bin/swerve --adapter=codeigniter --http=0.0.0.0:8080 --public=public
```

No `swerve.php` to write — `--adapter=codeigniter` is enough (or
`"extra": {"swerve": {"adapter": "codeigniter"}}` in `composer.json`, which is what the flag
reads). `public/index.php` is untouched, so the same application still answers through PHP-FPM.

Two things CodeIgniter's own worker-mode checklist already asks for, which Swerve enforces the
same way: `app/Config/WorkerMode.php` must exist (the 4.7 skeleton has it; an application
upgraded from 4.6 copies it over), and `configCacheEnabled` / `locatorCacheEnabled` in
`app/Config/Optimize.php` must both be `false`, or the worker refuses to start.

## One request at a time

A worker runs one CodeIgniter request at a time — the adapter wraps the whole request in
[[phasync\Util\Synchronized]]. `service()`, `config()`, `model()` through `Factories`, the
active intl locale, `$_SESSION`, and the worker's one database connection are all static state
that CodeIgniter's own request-scoped code — `session()`, the query builder, `Time` falling
back to the default locale — reaches for directly. Swerve doesn't give each request its own copy
of any of it; a request-scoped `CodeIgniter` instance and services were tried, and they clear
everything except the locale, `$_SESSION`, and the connection, which stay process-wide no matter
what owns the request. With the extension loaded, two requests sharing one connection throws
("Commands out of sync") or kills the worker; without it, a blocking query can't overlap with
anything else in the worker anyway. So the lock stays, for the whole request, extension or not.
That also means there's no need for Swerve's per-request `sapi()` isolation here — the lock
already keeps two requests from ever touching this state at the same time.

Size `--workers` the way you'd size `pm.max_children`: a request stuck on a slow query or an
outbound call holds its worker until it returns.

```php
class Dashboard extends BaseController
{
    public function index()
    {
        $user = model(UserModel::class)->find(session('user_id'));
        return view('dashboard', ['user' => $user]);
    }
}
```

Nothing here changes: `model()`, `session()`, `view()`, `config()` all resolve through the same
`Factories` and `Services` the lock protects, so ordinary controller code doesn't need to know
any of this.

## What persists, what resets

`app/Config/WorkerMode.php`'s `$persistentServices` decides what survives a request, same as
under any other worker-mode server. The 4.7 skeleton's default — `autoloader`, `locator`,
`exceptions`, `commands`, `codeigniter`, `superglobals`, `routes`, `cache` — is what keeps
booting cheap; don't add a service here that holds request data (`request`, `response`,
`session`) or the next visitor inherits it: [Service lifetimes](/learn/service-lifetimes/).

Everything else is rebuilt or rolled back after each request, in this order: open database
transactions roll back and the connection is checked, `Factories::reset()` drops `config()` and
`model()` instances, `Services::resetForWorkerMode()` drops every service not on the persistent
list, `Events::cleanupForWorkerMode()` removes listeners named in `resetEventListeners`, the
debug toolbar resets if `CI_DEBUG` is on, and `$_SESSION` is unset. The adapter also resets
PHP's session id itself, from the request's cookie, before `CodeIgniter::run()` — otherwise the
worker keeps whichever id the last request had and hands it to the next visitor, cookie or not.

`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, and the `IncomingRequest` built from them are filled
fresh every request, the way PHP-FPM fills them, but from the PSR-7 request Swerve received
rather than PHP's own request parsing, before `Services::createRequest()` runs. Helpers and
third-party code that read `$_SERVER` or `$_GET` directly need no changes.

## Sessions

CodeIgniter's session library runs unmodified — native `$_SESSION`, any driver (files,
database, Redis). Because only one request in a worker ever touches it at a time, a
file-backed session's lock never has a second coroutine queued behind it. A counter stored in
the session increments correctly whichever of a worker pool's processes answers the next
request, as long as the driver writes to storage the workers share (files or a database do;
nothing in one worker's memory does).

## The database connection

One connection per group, kept alive for the worker's life, pinged before each request and
rolled back after. This is where the lock earns its keep with the extension loaded: a shared
`mysqli` or PDO handle used by two coroutines at once is exactly what
[[phasync\Util\Synchronized]] exists to prevent, and the adapter takes the lock before
CodeIgniter ever reaches the connection — your models and query builder don't need to know.

## Uploads

`UploadedFile::isValid()` and `->move()` work on the files Swerve received, not ones PHP's own
upload handling wrote. The adapter shims `is_uploaded_file()` and `move_uploaded_file()` inside
CodeIgniter's own namespace, so `UploadedFile` doesn't need to know the difference.

## Errors, redirects, exit

CodeIgniter's exception handler renders errors as it always does — the same
`app/Views/errors/html` templates, the same logging — with its `exit()` removed. Calling
`exit()`, `die()`, or `dd()` yourself still ends the worker and every request mid-flight on it;
a custom `Config\Exceptions::handler()` that calls its own `exit()` does the same, since the
adapter only patches CodeIgniter's handler, not yours. Redirects are ordinary responses with a
`Location` header, same as under PHP-FPM. CSP headers and cookies go out the way
`Response::send()` would add them, and filters — including the debug toolbar's "after" filter —
run as configured. `is_cli()` is forced `false` in the worker, so sessions start and errors
render for a browser rather than a terminal; `spark` itself still sees it as `true`.

## WebSockets

A controller method can return a WebSocket instead of a response (`WebSocketResponse::from()`
in a `PsrResponse`), and it runs on the connection after the handshake — which means after the
request has ended, while the worker is on to the next one. `session()`, `$this->request`, and
every other request service by then belong to whoever the worker is serving now. Read what the
callback needs — a user id, a value from the session — before returning the response, and close
over it.

## Your application

The adapter runs one request at a time, but the worker outlives every request: a static or a shared Service's property set by one request is still there for the next, and streamed, SSE and WebSocket responses run after the lock, overlapping with other requests. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

To find candidates in your own code, run [phasync/doctor](https://github.com/phasync/doctor) from the application root: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`. It reads `composer.lock`, says which adapter you run and whether requests overlap, and reports each static, global, shared connection or process setting with the sites that write and read it.

Two routes would let the adapter (or your application) drop the lock for the state above. The simple one: save and restore that state in a `phasync::onScopeChange()` hook, which runs whenever the worker switches from one request to another. It is phasync's own API (an application relying on it runs on phasync only), it runs on every switch so it should swap only a few references, and it covers only the state you swap. The proper one: key the state on the request's `\Scope` (`Scope::getCurrent()`, the [Scope RFC](https://wiki.php.net/rfc/scope)), so nothing needs swapping. `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, the default time zone and `Locale::setDefault()` already follow the `Scope`; `$_SESSION` and `setlocale()` do not.

<!-- doctor-report:begin -->
### What phasync/doctor reports on a fresh CodeIgniter app

codeigniter4/appstarter v4.7.5 with phasync/swerve-codeigniter 0.1.1, scanned by phasync/doctor 0.1.1 on 2026-10-19.

swerve-codeigniter runs one request at a time per worker, behind a phasync\Util\Synchronized lock: CodeIgniter shares its services, config() and model() instances, the default locale, $_SESSION and one database connection per worker. Services in WorkerMode::$persistentServices live as long as the worker. A request that waits (a query, an API call) holds its worker, also with ext-phasync: capacity scales with --workers, sized as PHP-FPM children. Statics, settings and services still outlive the request: what one request leaves behind, the next one sees.

| Severity | Rule | Symbol | Location | Question |
| --- | --- | --- | --- | --- |
| error | exit | `public/index.php: exit` | `public/index.php:23` | Can this run while the worker serves a request? |
| warning | process-setting | `app/Config/Boot/development.php: error_reporting()` | `app/Config/Boot/development.php:13` | Is this set to the same value for every request? |
| warning | process-setting | `app/Config/Boot/development.php: ini_set()` | `app/Config/Boot/development.php:14` | Is this set to the same value for every request? |
| warning | process-setting | `app/Config/Boot/production.php: error_reporting()` | `app/Config/Boot/production.php:12` | Is this set to the same value for every request? |
| warning | process-setting | `app/Config/Boot/production.php: ini_set()` | `app/Config/Boot/production.php:15` | Is this set to the same value for every request? |
| warning | process-setting | `app/Config/Boot/testing.php: error_reporting()` | `app/Config/Boot/testing.php:17` | Is this set to the same value for every request? |
| warning | process-setting | `app/Config/Boot/testing.php: ini_set()` | `app/Config/Boot/testing.php:18` | Is this set to the same value for every request? |
| warning | process-setting | `public/index.php: chdir()` | `public/index.php:37` | Is this set to the same value for every request? |
| warning | response-output | `public/index.php: header()` | `public/index.php:20` | Is this part of the response? |
| info | output-buffering | `{closure} in app/Config/Events.php: ob_get_level()` | `app/Config/Events.php:34` | Can the request wait (a query, an HTTP call, a sleep) while this buffer is open? |
| info | output-buffering | `{closure} in app/Config/Events.php: ob_end_flush()` | `app/Config/Events.php:35` | Can the request wait (a query, an HTTP call, a sleep) while this buffer is open? |
| info | output-buffering | `{closure} in app/Config/Events.php: ob_start()` | `app/Config/Events.php:38` | Can the request wait (a query, an HTTP call, a sleep) while this buffer is open? |

See [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the fix for each finding.
<!-- doctor-report:end -->

## Debugging

The debug toolbar works, and resets per request, so it shows this request's queries and timers,
not the previous visitor's. `log_message()` goes to CodeIgniter's own log as configured, same as
under FPM. What's different from local development: there's no process restart between
requests, so a file edit doesn't reach the next request until the worker restarts.

## Before you deploy

- `exit`, `die()`, and `dd()` end the worker, and every request it's serving.
- A worker stopped by a reload or shutdown ends requests in flight at once: one still waiting
  gets a `503` from Swerve itself (no CodeIgniter error page, nothing logged); clients retry.
- The extension, when loaded, needs 0.5.0-alpha10 or later — every worker crashes on its first
  request on 0.5.0-alpha8.
- Every open WebSocket is a connection of its worker; without the extension a worker holds
  about 960.

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/) and
  [Per-request state](/learn/per-request-state/)
- [Long-lived connections](/learn/long-lived-connections/)
- [Frameworks](/frameworks/)
