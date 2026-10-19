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
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.swerve-psr15 vcs https://github.com/phasync/swerve-psr15
composer require 'phasync/swerve:dev-main as 0.1.0-beta7' phasync/swerve-codeigniter
```

<!-- TODO-verify: neither phasync/swerve-codeigniter nor phasync/swerve-psr15 was on Packagist on 2026-10-19; commands are the adapter's README, unchanged -->

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
