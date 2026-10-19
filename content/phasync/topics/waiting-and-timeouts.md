---
title: "Waiting and timeouts"
summary: "Sleep, wait for a stream or a flag, and what happens when the wait runs out."
order: 2
status: draft
---

[[phasync::sleep]] waits without blocking the process; [[phasync::readable]] and [[phasync::writable]] wait for a stream to be ready for the next read or write. Every wait in phasync takes an optional timeout and throws the same [[TimeoutException]] when it runs out, whether it is a sleep, a stream, a channel, or [[phasync::await]] itself.
