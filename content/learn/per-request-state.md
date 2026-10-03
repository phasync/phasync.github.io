---
title: "Per-request state: getContext() and WeakMap"
description: "Key state by the request's context in a WeakMap: each request sees its own value, and the entry goes when the request ends."
status: draft
order: 2
see_also:
  - phasync::getContext
  - phasync::withContext
---

[[phasync::getContext]] returns the context of the running coroutine: an object that Swerve creates for each request, and that every coroutine the request starts inherits. Use it as the key of a `WeakMap`.

```php
final class CurrentUser
{
    /** @var WeakMap<object, string> */
    private static WeakMap $names;

    public static function set(string $name): void
    {
        self::$names ??= new WeakMap();
        self::$names[phasync::getContext()] = $name;
    }

    public static function name(): ?string
    {
        self::$names ??= new WeakMap();

        return self::$names[phasync::getContext()] ?? null;
    }
}
```

With `handle()` from [the previous page](/learn/why-code-breaks/) calling `CurrentUser::set($name)` and `CurrentUser::name()`, the two requests answer as themselves:

```
hello ann
hello bob
```

## The entry goes with the request

phasync keeps no reference to a context once the request's coroutines have finished. A `WeakMap` does not keep its keys alive either, so the entry is freed with the request:

```php
$map = new WeakMap();
phasync::run(function () use ($map) {
    $map[phasync::getContext()] = 'ann';
    echo count($map), "\n";   // 1
}, [], new stdClass());
echo count($map), "\n";       // 0
```

An array keyed by `spl_object_id()` does not do this: it keeps the data after the request, and PHP may give a later object the same id.

## `phasync::$contextState`

phasync also has `phasync::$contextState`, an array that the worker swaps when it switches to a coroutine of another context, so that it reads like a static property and holds the current request's value. It is switched on with [[phasync::enableContextState]]. The Laravel adapter uses one per application; you need it only when code you cannot change reads a static property. Prefer the `WeakMap`.

Previous: [Why code breaks](/learn/why-code-breaks/) · Next: [Service lifetimes](/learn/service-lifetimes/)
