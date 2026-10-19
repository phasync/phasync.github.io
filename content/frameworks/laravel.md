---
title: "Laravel"
description: "Run a Laravel application on Swerve: many requests at once, each in a pooled application of its own."
status: draft
order: 1
see_also:
  - phasync\Util\Pool
  - Swerve::log
project: swerve
topic: frameworks
role: guide
---

**Concurrency: concurrent.** A worker serves many requests at once, each in a Laravel application of its own, taken from a [[phasync\Util\Pool]] of booted applications. Database and Redis connections live inside that application, so two requests in flight at once never share a connection or a transaction. The one place concurrency still bites is Laravel's view engine — see [Concurrency](#concurrency) below.

## Install and run

```bash
composer require phasync/swerve-laravel
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public
```

That starts the application with no `swerve.php` — the package's `composer.json` tells Swerve how to find it. `laravel/octane` comes along as a dependency, but only for the listeners that reset an application between requests; Octane's own servers and `octane:install` play no part. `public/index.php` is untouched, so the same application keeps running under PHP-FPM too.

Load `ext-phasync` with `--ext`: without it, `sleep()`, PDO and mysqli queries, curl and `flock()` hold the worker while they wait, the way they would under PHP-FPM; with it, they yield to other requests in the same worker.

To serve the application in a subfolder behind a proxy, or to change how many applications the pool keeps, add a `swerve.php`:

```php
<?php // swerve.php, next to composer.json

return new Swerve\RequestHandler((new Swerve\Laravel\Handler(__DIR__, basePath: '/app'))->handle(...));
```

and run it with `--adapter=swerve`.

## What persists between requests

Each request takes an idle application from the pool, or boots a fresh one. Octane's listeners reset it first (session, auth, cookies, locale — your own `config/octane.php` `listeners` run too), and once the request ends the container goes back to what it held right after boot. Concretely, none of this leaks from one request to the next:

- Container registrations made while a request runs — `singleton()`, `extend()`, a contextual binding, an alias, a tag, a provider registered at runtime.
- `Mail::fake()`, `Event::fake()`, `Queue::fake()`, and any other `Facade::swap()` — each request has its own `Facade::$resolvedInstance`.
- The paginator and Blade's component view factory, which resolve against the request's own application. (Octane's `GiveNewRequestInstanceToPaginator` listener, which would point every request's paginator at whichever application ran last, is a no-op here.)

What does persist, exactly as it would under Octane:

- **Static properties and singletons your own code, or a package, keeps data in.** They belong to the worker process, not the request: a value set during one request is still there for the next one the same pooled application serves, and, since requests overlap, for a request running concurrently in a different application too, if the state is a plain static rather than something container-bound. `View::share()` and view composers, `Gate::define()`, `Route`/`Request` macros, `Model::unguard()`, `Number::useLocale()`, global scopes, cache and auth driver extensions — register these once, in a provider's `boot()`, never inside a controller or a request.
- Route and config caching. `php artisan optimize` works exactly as it does under PHP-FPM.

## Concurrency

Each application resolves its own database and Redis connections through its own container — a query one request makes while another is mid-transaction never lands inside that transaction (the adapter's own test suite checks this with overlapping requests, one of which rolls back). Give your database enough connections for the pool, not just for `--workers`: with `ext-phasync` loaded, a worker can have as many applications open at once as it has requests waiting, up to `maxApplications` (128 by default — the fourth constructor argument of `Handler`).

Two things worth knowing before they surprise you in production:

- **A view that waits while it renders is only a problem with `ext-phasync` loaded.** Blade buffers output with `ob_start()`, and PHP's output buffers belong to the process, not the request. Without the extension, a lazily loaded relation or a query inside `@foreach` blocks the worker, so nothing else runs there meanwhile. With it, the wait lets another request run, and its output currently lands in the same buffer: [swerve-laravel#15](https://github.com/phasync/swerve-laravel/issues/15). Eager-load what a view needs instead of letting it query lazily:

  ```php
  Route::get('/posts/{post}', function (Post $post) {
      $post->loadMissing('comments.author'); // before the view can wait on it
      return view('posts.show', ['post' => $post]);
  });
  ```

- **A closure-based validation rule that waits holds the whole worker, even with the extension loaded.** Laravel calls it through `Closure::__invoke()`, which the extension's hooks don't see through. A rule class does not have this problem — use one wherever the rule queries the database or calls out.

### SQLite

Once requests overlap, Laravel's default SQLite session and cache writes start timing out under load unless you turn on WAL mode and a busy timeout:

```php
// config/database.php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => database_path('database.sqlite'),
    'journal_mode' => 'wal',
    'busy_timeout' => 5000,
],
```

## Gotchas

- **`exit`, `die()` and `dd()` end the worker**, and every request it's serving along with it. Reach for `Log::debug()` or [[Swerve::log]] instead of `dd()`-ing from a route that might run in production.
- **`echo`, `print` and a bare `dump()` in a route are not part of its response.** Return a response (`response()`, a view) instead; only a streamed response's callback writes straight to the client.
- **File uploads work normally** — `$request->file('avatar')->store(...)` and `->move(...)` — even though the underlying temporary file is Swerve's, not PHP's. It's deleted after the request unless you move it.
- **Multipart `PUT`/`PATCH` bodies aren't parsed** (url-encoded and JSON ones are); send a file upload as `POST` with `_method=PUT`.
- **Caching closure routes grows a worker's memory slowly.** `php artisan route:cache` on closure routes makes Laravel unserialize them through a stream wrapper on every request, and a long-lived worker never gets to free what that allocates. Use controller routes with `route:cache`, or run `route:clear` if you keep closures.
- **Queues and the scheduler run where they always have.** `php artisan queue:work` and `schedule:run` under cron stay separate processes; this adapter only serves HTTP and WebSocket requests, and doesn't touch either.
- **A `WebSocketResponse` callback runs with no application and no real request.** `app()`, Eloquent and the facades throw if you call them inside it. Read what you need from `$request` — the user, validated input — before calling `WebSocketResponse::from()`, and reach for `Handler::run()` to touch Laravel from inside the callback. Its application has no real request behind it (no path, no auth, an empty session that only writes to memory), so don't rely on the session there either.
- **A worker's memory creeps up slowly under sustained load**, mostly from applications booting into and dropping out of the pool. Recycle workers in production the way you would restart PHP-FPM children — `SIGHUP` triggers a rolling reload — and reload on every deploy; `--watch` does that automatically while you develop.

## Your application

Requests overlap in a worker, each in an application of its own from the pool, so container bindings are per request. What the pool does not separate is the process: static properties, globals and anything your code caches outside the container are shared by every application in the worker. The framework running correctly on Swerve does not make your own code safe: see [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the checklist and a fix for each item.

To find candidates in your own code, run [phasync/doctor](https://github.com/phasync/doctor) from the application root: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`. It reads `composer.lock`, says which adapter you run and whether requests overlap, and reports each static, global, shared connection or process setting with the sites that write and read it.

## Debugging

- `Log::debug()` and [[Swerve::log]] both land in Swerve's log next to its own request line (method, path, status, duration) — a better habit than `dd()`, which takes the worker down with it.
- To check whether a singleton or a cache of your own bleeds between requests, fire two requests at a matching pair of routes that set and read it concurrently, and compare what comes back — the pattern the adapter's own test suite uses for everything Laravel keeps.
- If a response looks like it's showing another user's data, or a route's `echo` doesn't show up, run the same request with `--ext` removed. If the symptom disappears, it's the view-rendering limitation above, not a bug in your code.

## Compatibility

| Laravel | PHP | ext-phasync |
|---|---|---|
| 12 | 8.2 – 8.5 | optional; tested with and without |
| 13 | 8.3 – 8.5 | optional; tested with and without |

## Read next

- [Why code breaks when requests overlap](/learn/why-code-breaks/), then [Per-request state](/learn/per-request-state/)
- [Laravel on Swerve](/learn/laravel/): the container, views, toggles and caches
- [Connections and pools](/learn/connections-and-pools/), for database and Redis sizing
- [Frameworks](/frameworks/): the other adapters
