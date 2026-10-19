---
title: "Resource management"
summary: "A few database connections, and one cache, shared among thousands of requests."
order: 5
status: draft
---

`Swerve::cache()` is one PSR-16 cache shared by every worker, read from local memory once a worker has seen a key. A database or API connection, by contrast, is each worker's own: open a few per worker and lend them out with `phasync\Util\Pool` rather than one per request. See [Cache](/guides/cache/).
