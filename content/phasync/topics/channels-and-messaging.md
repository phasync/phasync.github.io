---
title: "Channels and messaging"
summary: "Pass values between coroutines: channels, queues and publishers."
order: 5
status: draft
---

[[phasync::channel]] returns two objects, not one: a read end ([[ReadChannelInterface]]) and a write end ([[WriteChannelInterface]]). There is no Channel class. Give the write end to the coroutines that produce values and the read end to those that consume them; a read waits until a value is written, and when every write end is gone the reader sees the channel close. [[phasync::publisher]] is the one-to-many form: every subscriber gets every value. [[Queue]] is a plain FIFO buffer for when no coroutine needs to wait.
