---
title: "Making your application safe for concurrent requests"
description: "An adapter running your framework correctly under Swerve doesn't make your own code safe for requests that overlap. The checklist, with a fix for each item."
status: draft
order: 1
see_also:
  - phasync::onScopeChange
  - phasync\Util\Pool
  - /learn/per-request-state/
project: swerve
topic: context-switching
role: guide
---

The framework adapter pages describe what the framework itself keeps safe between overlapping requests. None of that covers code you wrote on top of it: a middleware with a property, a container definition that hands out the same instance to everyone, a cache you built as a static array. This page is the checklist for that code, with a fix for each item.

[phasync/doctor](https://github.com/phasync/doctor) finds the candidates for you. It reads your code without running it and reports each one with the sites that write and read it, the question to answer about it and ready code for each fix:

```
composer require --dev phasync/doctor
vendor/bin/phasync-doctor
```

State that is the same for every request is marked with a `@scope-safe <reason>` tag and no longer reported; findings in code you don't own go into a baseline file. `--format=json` gives the same report to a coding agent.

## State in a static, a global, or a shared service

A "current user" or "current tenant" field set on a service the container hands out once, a static property, a `global`, a value cached into a class constant's sibling array the first time it's read — all of it is one copy for the worker, not one per request, the moment two requests can overlap in it.

**The quick fix**, for code you don't want to restructure: swap the value when the worker moves from one request to another, with [[phasync::onScopeChange]]:

```php
final class CurrentTenant
{
    public static ?string $id = null;
}

$tenants = new WeakMap();   // the departing request's value, per Scope
phasync::onScopeChange(function (Scope $new, ?Scope $old) use ($tenants) {
    if (null !== $old) {
        $tenants[$old] = CurrentTenant::$id;
    }
    CurrentTenant::$id = $tenants[$new] ?? null;
});
```

Register the hook once, at boot. It runs in the event loop, before a coroutine of a different `Scope` than the last one runs, and must not wait; everything inside one request still reads and writes the plain static, unaware anything changed. Know its limits:

- it is phasync's own API, not part of fiber-interop, ext-scope or the Scope RFC: an application that relies on it runs on phasync only;
- it runs on every switch between requests, so swap a few references in it, not whole object graphs;
- it covers only the state your hook swaps: a static inside a library you haven't looked at still leaks.

**The better fix**: key the value by the request's `Scope` directly, instead of swapping a shared field for it — see [per-request state](/learn/per-request-state/):

```php
final class CurrentTenant
{
    /** @var WeakMap<Scope, string> */
    private static WeakMap $ids;

    public static function set(string $id): void
    {
        self::$ids ??= new WeakMap();
        self::$ids[Scope::getCurrent()] = $id;
    }

    public static function get(): ?string
    {
        self::$ids ??= new WeakMap();

        return self::$ids[Scope::getCurrent()] ?? null;
    }
}
```

A container definition built per request (a factory, not a shared instance — see [service lifetimes](/learn/service-lifetimes/)) reaches the same result without a `WeakMap` at all.

The hook is the simple fix for a legacy codebase, or for a known static in code you can't change. Keying on `Scope` is the proper one: nothing runs on every switch between unrelated requests, there's no hook to forget to register, and it works on any runtime that implements the Scope RFC.

## One connection, shared by every request in the worker

A `PDO`, `mysqli`, `SQLite3`, `pgsql`, or `Memcached` handle built once and reused by every request is one connection, not one per request. With ext-phasync loaded, SQLite (`SQLite3`, `pdo_sqlite`), PostgreSQL (`pgsql`, `pdo_pgsql`) and Memcached guard against this directly: a coroutine that touches a connection, statement or result while another coroutine's call on it hasn't returned gets a `LogicException`, "Concurrent operations on the same resource are not supported" — the first call finishes undisturbed. MySQL (`mysqli`, `pdo_mysql`) has no such guard: two coroutines racing one connection get the protocol's own "Commands out of sync" error, or worse.

Give each request its own connection, or borrow one from a [[phasync\Util\Pool]] for the query or the transaction and release it before the request waits on anything else that doesn't need it — not for the whole request. A transaction belongs to the request that opened it: commit or roll back before the connection goes back to the pool or back to idle, or the next request to borrow it inherits whatever the last one left open.

## `$_SESSION`, superglobals, locale and time zone

Swerve runs each request in a `Scope` of its own, and `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, the default time zone (`date_default_timezone_set()`/`get()`) and the `intl` default locale (`Locale::setDefault()`/`getDefault()`) already follow it: a new scope gets its own copies, and a write in one doesn't reach another. phasync's `fiber-interop/scope` dependency does this on its own; ext-phasync, which bundles the `Scope` extension, adds `$_REQUEST`. Nothing to do for those.

`$_SESSION` is deliberately left out of that: PHP's session module is one `$_SESSION` and one `session_id()` per process, so a request can read or write another's session data. A session storage that doesn't go through PHP's native module at all sidesteps the problem; short of that, swap the session in a `phasync::onScopeChange()` hook.

`setlocale()` is process-wide in PHP itself and isn't covered either. Refactor it to `Locale::setDefault()` and `Locale::getDefault()` (and `intl` formatters that use the default locale), which the `Scope` swaps per request. Swapping `setlocale()` in a hook is the fallback where that refactor isn't possible.

## Output, runtime settings, and a cache that grows into a static

- `echo`, `print` and `var_dump()` write to PHP's one output-buffer stack, shared by the worker; a wait inside a buffered block can let another request's output land in the same buffer. Check how the adapter you're using routes output — most buffer the response separately and send stray output to Swerve's log instead of the client.
- `ini_set()`, `putenv()`, `chdir()` and `setlocale()` called at request time change the process, not the request. `date_default_timezone_set()` is fine: the `Scope` keeps it per request.
- A cache your own code fills lazily into a static property (`self::$cache[$key] ??= ...`) is a convenient singleton until two requests need different values under it at once, or one evicts what another just set. Safe only once the values in it stop depending on which request is asking — otherwise it needs the same fixes as any other shared state above.
