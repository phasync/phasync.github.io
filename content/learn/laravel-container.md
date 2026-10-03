---
title: "Laravel: the container and facades"
description: "Registrations and fakes made during a request stay on shared objects. Register at boot; fake in tests."
status: draft
order: 12
---

## Runtime container registrations

```php
Route::get('/set', fn () => app()->singleton('tenant', fn () => 'A'));
Route::get('/get', fn () => app()->bound('tenant') ? app('tenant') : null);
```

On Octane the sandbox is a clone with its own copy of the container's arrays, so `/set` changes the clone and the next request does not see it. On the adapter's pooled application the container is restored after each request: `instances`, `reboundCallbacks` and `terminatingCallbacks` go back to what they were after boot. `bindings`, `aliases`, `extenders`, resolving callbacks, contextual bindings, tags and providers registered at runtime did not, so `/get` answers `A` on later requests that get the same application ([#8](https://github.com/phasync/swerve-laravel/issues/8)).

A resolved `instance()` is restored, so per-request data can use it, or `scoped()`:

```php
app()->instance('tenant', $tenant);       // this request's; dropped after it
app()->scoped(Cart::class);               // a new Cart for every request
```

Design: shared services are registered in a provider's `register()` and `boot()`, which run once per application. A request resolves what it needs and keeps its own data in an instance, a scoped service, or the request. <!-- TODO-verify: the two lines above, the repro and the restore of instances were taken from issue #8 and the adapter's Handler source; not run here -->

## Facade fakes

`Mail::fake()`, `Event::fake()` and `Queue::fake()` call `Facade::swap()`, which writes `Facade::$resolvedInstance`, a static. Octane clears it with `Facade::clearResolvedInstances()` each time it sets the current application. The adapter cleared it once at install, so a fake made in one request replaced the service for requests running meanwhile and for later ones ([#9](https://github.com/phasync/swerve-laravel/issues/9)).

Design: fakes are for tests, which run one request at a time. In application code, bind a different implementation at boot for the environment that needs it:

```php
// AppServiceProvider::register()
if ($this->app->environment('staging')) {
    $this->app->bind(Mailer::class, LogMailer::class);
}
```

## Process-wide pointers

`Container::$instance` and `Facade::$app` hold "the" application. Code that uses `app()` or a facade works because the adapter points them at proxies that forward to the running request's application. Code that saves the result for later does not:

```php
final class Reports
{
    private static $app;

    public static function boot(): void { self::$app = app(); }   // keeps the first request's application
}
```

Design: resolve `app()` where it is used, as the facades do, or pass the dependency in.

Previous: [Case study: Laravel](/learn/laravel/) · Next: [Laravel: views, translator, router and paginator](/learn/laravel-views/)
