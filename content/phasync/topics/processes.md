---
title: "Processes"
summary: "Run child processes without blocking the other coroutines."
order: 10
status: draft
---

[[Process]]::run() (through [[ProcessRunner]], behind [[ProcessInterface]]) starts a child process and hands back its standard streams to read and write from a coroutine, without blocking the others while it runs. See [Running processes](/guides/running-processes/) for starting several at once, talking back and forth, and timing one out.
