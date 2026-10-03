---
title: "Caching: whose data is it?"
description: "A shared cache is safe when its key holds every input the value depends on."
status: draft
order: 7
see_also:
  - phasync\Util\LruCache
---

A cache that several requests share is safe only if its key contains everything the value depends on. In-memory caches in application code often assume one user, tenant or locale: the key is `'menu'` or `'settings'`, and the value was computed for whoever was logged in. Under PHP-FPM that was request-scoped by accident.

> What inputs does this value depend on, and are they all in the key?

## The bug

```php
function menu(): array
{
    static $menu;
    if ($menu === null) {
        $user = Current::user();
        $menu = loadMenu($user);       // a query
    }

    return $menu;
}
```

Ann's request fills `$menu`; Bob's, later, reads it:

```
menu: Account of ann | Account of ann
```

## Two fixes

Put the user in the key. The cache is shared again, and bounded, since the number of keys grows with the number of users:

```php
function menu(): array
{
    static $cache;
    $cache ??= new LruCache(maxEntries: 1000);
    $user = Current::user();
    $menu = $cache->get("menu:$user");
    if ($menu === null) {
        $cache->set("menu:$user", $menu = loadMenu($user), ttl: 60);
    }

    return $menu;
}
```

Or make it scoped, so that it belongs to one request and goes with it:

```php
function menu(): array
{
    static $menus;
    $menus ??= new WeakMap();

    return $menus[phasync::getContext()] ??= loadMenu(Current::user());
}
```

```
menuKeyed: Account of ann | Account of bob
menuScoped: Account of ann | Account of bob
```

## Which cache is which

1. **A pure function of its arguments.** Shared, bounded by an LRU: [memoization](/learn/memoization/).
2. **Depends on the user, tenant, locale, roles or feature flags, and the key lacks it.** Add every such input to the key (then it is shared, and the LRU matters because the keys multiply with users), or scope it to the request.
3. **Depends on state that changes** (a setting in the database, the time). An LRU does not make a stale entry correct: give it a TTL, or delete it when the state changes.
4. **Large against cheap to recompute.** A scoped cache dies with the request. A shared one costs memory for the worker's life, per worker. Share what is expensive to compute and small; scope the rest.
5. **The value is an object bound to a request**: a Request, a user model, a database connection. Never share it. Cache the data it was made from.

Previous: [Caching: memoization](/learn/memoization/) · Next: [Connections, pools and limits](/learn/connections-and-pools/)
