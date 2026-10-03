---
title: "Laravel: static caches"
description: "Which of Laravel's own static caches are memoization, whether they are bounded, and what flushes them."
status: draft
order: 15
see_also:
  - phasync\Util\LruCache
---

[Memoization](/learn/memoization/) of a pure function is safe to share between requests, if it is bounded. Laravel has such caches, and its answer is to empty them rather than to bound them.

## What was checked

In Laravel 13.34.0 and Octane 2.20.0:

| Cache | Key | Bounded? | Flushed by Octane or the adapter |
|---|---|---|---|
| `Str::$snakeCache`, `$camelCache`, `$studlyCache` | the input string | No: one entry per distinct input | `Str::flushCache()`, called by Octane's `FlushStrCache` listener |
| `Component::$bladeViewCache`, `$propertyCache`, `$methodCache`, `$constructorParametersCache` | component class (and contents) | By the number of components in the application | `Component::flushCache()`, from a terminating callback in `ViewServiceProvider` |
| `Pluralizer::$inflector` | none: one object, built on first use | Yes | no |
| Eloquent's attribute, cast and mutator caches (`Model::$mutatorCache`, `$castTypeCache`, ...) | model class and attribute name | By the models and attributes of the application | no |

The adapter's `tests/statics/allowlist.php` classifies every static property of Laravel 13 and marks these as `HARMLESS-CACHE`: a memo that is a pure function of its input, shared on purpose.

Octane's default config lists `FlushStrCache` under `prepareApplicationForNextOperation()`, which runs on `RequestReceived`; the adapter dispatches `RequestReceived` for every request and applies the `listeners` of `config/octane.php`. So the casing caches are emptied at the start of each request. Emptying a pure cache while another request is using it costs that request a recomputation, not a wrong answer.

## What it means

- The casing caches are keyed by whatever string is passed in. `Str::snake($request->input('field'))` adds one entry per distinct value a client sends, until the next flush. The flush bounds it, and nothing is kept from one request to the next, so a hot key is computed again.
- The caches keyed by class names are bounded by your code and do not need flushing.
- Per-user results are a different matter ([whose data is it?](/learn/cache-keys/)). Laravel keeps the user in the guard and the session, which the adapter keeps per request. The per-user caches that matter are your own: `Cache::remember('menu', ...)` with a key that has no user in it has the bug from that page whichever store backs it.

Design: a cache of a pure function takes an `LruCache` with `maxEntries`, and is not flushed; a cache keyed by request data gets the data in its key or is scoped.

<!-- TODO-verify: not run. Verified by reading source: the Str and Component caches, FlushStrCache, ViewServiceProvider's terminating callback, Octane's default listener lists, that the adapter's Handler dispatches RequestReceived, and the classification in tests/statics/allowlist.php. Not verified at runtime: that FlushStrCache runs in the adapter (the default config merge is in OctaneServiceProvider), and the other static caches (Blade's compiled-view and regex caches, reflection caches in the container, the Doctrine inflector's own caches), which were not examined -->

Previous: [Laravel: toggles, macros and process settings](/learn/laravel-toggles/)
