---
title: "Coordination and limits"
summary: "Wait for a group, take a lock, limit a rate, share an event."
order: 6
status: draft
---

[[WaitGroup]] waits for a batch of coroutines that report their own completion, instead of a list of `Fiber` handles. [[RateLimiter]] lets events through at a fixed rate, shared by every coroutine that waits on it. [[Event]] is a plain listener list for code that fires something and moves on, without a coroutine to wait. [[LockInterface]] and [[Synchronized]] serialize access to one PHP resource that only one coroutine may use at a time.
