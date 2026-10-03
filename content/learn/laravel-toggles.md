---
title: "Laravel: toggles, macros and process settings"
description: "Gate and macro registrations, static switches like Carbon::setTestNow(), and the settings PHP keeps per process."
status: draft
order: 14
---

## Registrations in a request

`Gate::define()`, `Gate::before()`, `Auth::viaRequest()`, and macros on `Request`, `Response` and `Route` all add to an object that outlives the request. Do them at boot. `viaRequest()` hands its callback the request, so the callback does not have to be registered per request:

```php
// AppServiceProvider::boot()
Auth::viaRequest('token', fn (Request $request) => User::byToken($request->bearerToken()));
```

## Static toggles

These write a static that every request reads:

| Call | Reads it |
|---|---|
| `Carbon::setTestNow()` | every date and time created by Carbon |
| `Number::useLocale()`, `useCurrency()` | number formatting |
| `Str::createUuidsUsing()` and the other `Str` factories | generated ids |
| `Sleep::fake()` | every `Sleep::for()` |
| `Model::unguard()`, `Model::withoutEvents()` | mass assignment, model events |

Set in a request, they stay set for the requests after it, as on Octane. Design: set them at boot when they are configuration, and in a test when they are fakes. For a value that varies by request, hold it in a scoped object ([shareable services](/learn/shareable-services/)) and pass it to the call: `Number::format($n, locale: $locale)` takes the locale as an argument, and a clock can be an injected dependency.

The scoped forms switch the static for the length of a callback and switch it back: `Model::unguarded()`, `Carbon::withTestNow()`, `Number::withLocale()`. After the callback nothing is left behind. While it runs, a request that waits inside it lets another run with the switch on. Keep the callback free of waiting:

```php
$user = Model::unguarded(fn () => User::create($attributes));   // the insert waits inside the callback
```

Instead, build the model first and do the waiting after:

```php
$user = Model::unguarded(fn () => new User($attributes));
$user->save();
```

## Process-level

PHP keeps `ini_set()`, `setlocale()`, `date_default_timezone_set()` and `mt_srand()` per process, and an adapter cannot give a request its own. Set the `ini` values and the locale in `php.ini` or at boot, use `config('app.timezone')` and `App::setLocale()` in place of the PHP functions, and use an injected seed in place of `mt_srand()`. `exit()`, `die()` and `dd()` end the worker and every request in it; throw an exception or call `abort()` instead.

<!-- TODO-verify: the snippets are illustrations and were not run; the lists are from the adapter's docs/shared-state.md and the audit probes (carbon-testnow, number-currency, str-uuid, sleep-fake, gate-define, auth-viarequest, request-macro, response-macro, ini-set, setlocale, php-timezone, mt_srand), whose results move as the fixes land; the claim that Model::unguarded() holds its switch while the callback waits is from shared-state.md -->

Previous: [Laravel: views, translator, router and paginator](/learn/laravel-views/) · Next: [Laravel: static caches](/learn/laravel-caches/)
