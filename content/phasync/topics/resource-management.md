---
title: "Resource management"
summary: "Limited resources shared by many coroutines: pools and bounded caches."
order: 7
status: draft
---

[[Pool]] lends a limited number of objects — database connections, say — to coroutines that each borrow one, use it, and give it back. [[LruCache]] bounds an in-memory cache by size, evicting the least recently used entry first. Both exist because a coroutine that opens a new connection per unit of work defeats the point of running many of them in one process: share what is expensive to create.
