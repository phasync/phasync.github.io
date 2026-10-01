---
title: Cache
description: "Swerve::cache(): one PSR-16 cache for every worker, read from memory in the worker."
status: draft
order: 3
see_also:
  - Swerve::cache
  - Swerve::publish
---

`Swerve::cache()` returns a `Psr\SimpleCache\CacheInterface`. Every worker sees the same entries, so a value cached by one request is a hit for the next, whichever worker serves it.

```php
use Swerve\Swerve;

$cache   = Swerve::cache();
$sidebar = $cache->get('home:sidebar');
if (null === $sidebar) {
    $sidebar = render_sidebar();                  // your own code
    $cache->set('home:sidebar', $sidebar, 60);    // 60 seconds
}
```

`set()` takes a TTL in seconds or a `DateInterval`, as PSR-16 says. Entries are stored serialized, so anything `serialize()` takes can be cached.

## How it works

- The master process holds the store. It evicts the least recently used entries past `--cache-size` (64 MiB by default).
- Each worker keeps a local cache of 8 MiB with what it has read, and what it found missing. A read of something the worker already holds is answered from its own memory.
- A write goes to the master. The master then tells every worker, the writer included, to forget those keys. It sends no values: a worker fetches the value from the master the next time it reads the key.
- An entry with a TTL expires at the same moment in every worker.
- Values are serialized in the worker. The master keeps the strings and never unserializes them, so your classes are never loaded there.

A worker never keeps a value older than the last write it has been told of.

## What it does not do

- The contents last as long as the master: a rolling reload keeps them, a restart empties them. Keep what must survive in a database.
- It is a cache for one machine. Swerve on several machines has one cache per machine.
- There is no increment. A counter read and written by concurrent requests loses updates; use a database or Redis for counters.

<p class="callout placeholder"><strong>TODO-measure.</strong> How long a read takes, from the worker's own memory and from the master, is not measured yet. It will be stated on <a href="/performance/">Performance</a>, and not here.</p>
