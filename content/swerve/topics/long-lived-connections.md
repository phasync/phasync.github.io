---
title: "Long-lived connections"
summary: "Connections that stay open without holding a pooled resource."
order: 2
status: draft
---

A connection that waits a long time — an SSE stream, a WebSocket, long polling — costs a coroutine and whatever it is waiting on, not a pooled resource held the whole time: a database connection taken to read the opening state should be returned before the wait begins, not kept for as long as the browser stays connected. See [Production](/guides/production/) for how many such connections a worker can hold.
