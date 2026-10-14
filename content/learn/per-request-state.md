---
title: "Per-request state: Scope and WeakMap"
description: "Key state by the request's Scope in a WeakMap: each request sees its own value, and the entry goes when the request ends."
status: draft
order: 2
see_also:
  - phasync::onScopeChange
  - phasync::go
project: swerve
topic: context-switching
role: concept
---

Swerve runs each request in a `Scope` of its own (the class of the Composer package `fiber-interop/scope`, which phasync requires). Every coroutine the request starts runs in that scope too, also after it waits. `Scope::getCurrent()` returns it: use it as the key of a `WeakMap`.

```php
final class CurrentUser
{
    /** @var WeakMap<Scope, string> */
    private static WeakMap $names;

    public static function set(string $name): void
    {
        self::$names ??= new WeakMap();
        self::$names[Scope::getCurrent()] = $name;
    }

    public static function name(): ?string
    {
        self::$names ??= new WeakMap();

        return self::$names[Scope::getCurrent()] ?? null;
    }
}
```

With `handle()` from [the previous page](/learn/why-code-breaks/) calling `CurrentUser::set($name)` and `CurrentUser::name()`, the two requests answer as themselves:

```
hello ann
hello bob
```

Outside any request, `Scope::getCurrent()` is `Scope::getRootScope()`: the worker's own code has a key as well.

## The entry goes with the request

phasync keeps no reference to a scope once the request's coroutines have finished. A `WeakMap` does not keep its keys alive either, so the entry is freed with the request:

```php
$map = new WeakMap();
(new Scope())->run(function () use ($map) {
    $map[Scope::getCurrent()] = 'ann';
    echo count($map), "\n";   // 1
});
echo count($map), "\n";       // 0
```

An array keyed by `spl_object_id()` does not do this: it keeps the data after the request, and PHP may give a later object the same id.

## State PHP keeps for the process

Some state is not yours to key: `setlocale()`, `date_default_timezone_set()`, a static of a library you cannot change. [[phasync::onScopeChange]] swaps it. The hook runs before a coroutine of another scope than the last one runs, never between coroutines of one request, so each request keeps using the plain functions:

```php
$zones = new WeakMap();   // per Scope
phasync::onScopeChange(function (Scope $new, ?Scope $old) use ($zones) {
    if (null !== $old) {
        $zones[$old] = date_default_timezone_get();
    }
    date_default_timezone_set($zones[$new] ?? 'UTC');
});
```

Register it once, at boot. It runs in the event loop and must not wait. Prefer the `WeakMap` for state of your own: a hook runs at every switch between requests.

Previous: [Why code breaks](/learn/why-code-breaks/) · Next: [Service lifetimes](/learn/service-lifetimes/)
