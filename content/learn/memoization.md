---
title: "Caching: memoization"
description: "A cache of a pure function can be shared by every request, if it is bounded."
status: draft
order: 6
see_also:
  - phasync\Util\LruCache
---

A pure function returns the same value for the same arguments and reads nothing else. Its results can be cached in memory and shared by all requests: whichever request computes a value, every other gets the same.

Under PHP-FPM a `static $cache = []` in such a function was emptied when the request ended. In a worker it lives as long as the worker, so it must not grow without bound. Use [[phasync\Util\LruCache]], which drops the entries used least recently:

```php
use phasync\Util\LruCache;

final class Slug
{
    private static LruCache $cache;

    public static function of(string $title): string
    {
        self::$cache ??= new LruCache(maxEntries: 1000);
        $slug = self::$cache->get($title);
        if ($slug === null) {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            self::$cache->set($title, $slug);
        }

        return $slug;
    }
}

echo Slug::of('Hello, World!');   // hello-world
```

`maxEntries` and `maxBytes` both default to no limit, so set at least one. `maxBytes` counts keys and string values. Keys are strings. `set()` takes a `$ttl` in seconds for a value that should expire.

An unbounded array does the same work and grows with every distinct title that any request ever asks for:

```php
static $cache = [];            // never emptied: one entry per distinct input, for the worker's life
$cache[$title] ??= compute($title);
```

## What a worker's cache is

Each worker is a process, and each has its own `LruCache`: a value computed in one worker is computed again in the others. For entries that every worker should see, use [Swerve::cache()](/guides/cache/).

The cache is only safe when the function is pure. If the value depends on who is asking, read [Caching: whose data is it?](/learn/cache-keys/).

Previous: [Services that can be shared](/learn/shareable-services/) · Next: [Caching: whose data is it?](/learn/cache-keys/)
