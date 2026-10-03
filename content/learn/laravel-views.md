---
title: "Laravel: views, translator, router and paginator"
description: "Shared view data, translator lines and library pointers: set them at boot, pass the rest with the call."
status: draft
order: 13
---

## Shared registrations

`View::share()`, view composers, translator lines, route macros and cache or auth driver extensions are registrations on objects that every request of the application uses. A request that adds one adds it for the requests after it, on Octane too (group 1 of [the case study](/learn/laravel/)).

```php
Route::get('/dashboard', function () {
    View::share('user', auth()->user());      // every later view in this worker has it
    return view('dashboard');
});
```

Design: data for one view goes through the call, and shared data is registered in a provider's `boot()`:

```php
Route::get('/dashboard', fn () => view('dashboard', ['user' => auth()->user()]));

// AppServiceProvider::boot()
View::share('appName', config('app.name'));       // the same for every request
```

For data that depends on the request but belongs in every view, use a view composer registered at boot that reads `auth()->user()` when the view renders. The composer is shared; what it reads is the request's.

## The paginator's resolvers

Laravel finds the current page, path and view factory through static resolvers (`PaginationState::resolveUsing()`). Octane's `GiveNewRequestInstanceToPaginator` listener points them at each request's application, which is right when one request runs. The adapter points them at a proxy that resolves the application of the running request, and Octane's listener then replaced the proxy at the start of every request, so a concurrent request's `Paginator::resolveCurrentPath()` read the application of whichever request started last ([#10](https://github.com/phasync/swerve-laravel/issues/10)).

Design: a resolver that is a static hook should find the request through the context when it is called, not hold the request or application that was current when it was installed. The same goes for any library that exposes `resolveUsing()`.

## Components

`Component::$factory` is a static that caches the view factory. The view provider registers a terminating callback that sets it back to null after each request, so the next `Component::factory()` call cached the first caller's `make('view')` for the whole process, and concurrent requests rendered components through another application's factory ([#11](https://github.com/phasync/swerve-laravel/issues/11)).

Design: do not cache a resolved, request-bound service in a static. Resolve it at use ([captive dependencies](/learn/captive-dependencies/)).

<!-- TODO-verify: the three snippets are illustrations, not run; the statements on #10 and #11 come from the issues and from Laravel 13.34.0 / Octane 2.20.0 source (ViewServiceProvider's terminating callbacks); not observed at runtime here -->

Previous: [Laravel: the container and facades](/learn/laravel-container/) · Next: [Laravel: toggles, macros and process settings](/learn/laravel-toggles/)
