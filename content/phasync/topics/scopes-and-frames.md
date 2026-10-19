---
title: "Scopes and frames"
summary: "Which operation code belongs to (a Scope), and which run() owns its coroutines (a Frame)."
order: 4
status: draft
---

A frame ([[Frame]]) is the lifetime: which `run()` waits for a coroutine, and what a cancellation or a deadline reaches. A scope (`\Scope`, from `fiber-interop/scope`) is the identity: which request or job the code belongs to, independent of which frame it runs in. `go()` always joins the frame of the coroutine that calls it; only `run()` and [[phasync::service]] start one outside it. See [Advanced: Coroutines](/advanced/coroutines/) for how the two interact.
