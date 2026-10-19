---
title: "Concurrent connections and memory per socket"
description: "Measured 2026-09-28/29: how many open connections Swerve holds, and how much memory each one costs, against RoadRunner, FrankenPHP, Swoole, OpenSwoole and ReactPHP."
status: draft
order: 2
see_also:
  - Swerve::subscribe
---

Measured 2026-09-28/29 on `black` (see [Method](/performance/method/)): swerve 0.1.0-alpha19+ with phasync 2.0.0-alpha14, ext-phasync 0.5.0-alpha13, against RoadRunner 2025.1.15, FrankenPHP 1.12.7, Swoole and OpenSwoole 6.2.3/26.2.0 and ReactPHP. Full setup, every raw run and the scripts are in [`benchmarks/servers/SUMMARY.md`](https://github.com/phasync/swerve/blob/main/benchmarks/servers/SUMMARY.md) of the Swerve repository.

## A plain 10 ms wait, pushed to each server's limit

Connections were raised until throughput stopped growing. RoadRunner and FrankenPHP serve one request per worker, so they queue once connections exceed the worker count; the cooperative servers (Swerve, Swoole, OpenSwoole, ReactPHP) keep going because a wait does not hold a worker.

| Server | 8 workers, 10,000 connections | p99 |
|---|---:|---:|
| Swerve + ext-phasync | 500,900 req/s | 22 ms |
| Swoole | 621,000 req/s | 20 ms |
| OpenSwoole | 557,400 req/s | 21 ms |
| ReactPHP | 222,900 req/s (at 1,000 conn) | 0.33-1.33 s |
| RoadRunner (1,000 conn) | 701 req/s | 2.3 s |
| FrankenPHP (1,000 conn) | 706 req/s | 2.2 s |

Without ext-phasync, Swerve falls back to PHP's `stream_select()`, which is limited to about 1,024 descriptors per process: past roughly 1,000 connections per worker it stops accepting new ones and the rest queue, unseen by `wrk`'s own latency numbers. This is why Swerve's own documentation points to the extension for many connections.

## Memory per open WebSocket

10,000 and 50,000 sockets connected and held open, a message published to all of them, memory measured as the server's total RSS with the sockets open minus before, divided by sockets held:

| Server | KiB per socket (10k) | KiB per socket (50k) |
|---|---:|---:|
| Swoole | 5 | 5 |
| OpenSwoole | 6 | 5 |
| ReactPHP | 37 | 36 |
| Swerve + ext-phasync | 52-53 | 49-59 |

At 50,000 sockets, that is about 2.6 GB total for Swerve against 250-290 MB for Swoole and OpenSwoole and 1.8 GB for ReactPHP: a real gap, not a rounding difference. The held state is a parked PHP coroutine plus phasync's own subscription bookkeeping, in PHP; Swoole's and OpenSwoole's is a C struct, which costs less per connection by design. All three delivered the message to every socket at both sizes. Without ext-phasync, Swerve's workers stopped accepting connections at about 1,014 sockets each (the same `stream_select()` limit) and the fan-out was not delivered.

## What this does not show

These are idle or near-idle connections (a wait, or a WebSocket between messages), on one machine, over loopback. It says nothing about CPU-bound throughput — see [Throughput against Node.js and Go](/performance/throughput-node-go/) for that — or about several machines behind one Swerve deployment.
