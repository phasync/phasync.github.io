---
title: "Throughput against Node.js and Go"
description: "Measured 2026-09-28: a bare hello-world handler, Swerve against Node's http module and a Go net/http server, at rising connection counts."
status: draft
order: 3
---

Measured 2026-09-28 on a two-socket Xeon E5-2697 v3 machine (56 hardware threads), client on a second machine over a 1 GbE LAN, `wrk 4.1`, 10-second runs. PHP 8.5.11 with opcache and the tracing JIT, Node 22.19, Go 1.22.2. Swerve and Node ran 56 worker processes; Go ran one process with `GOMAXPROCS=56`. Full setup and every raw line: `benchmarks/results/2026-09-28-http.txt` in the Swerve repository.

Three bare handlers, each returning a short fixed response with no further work: Swerve's `/hello` route (`phasync 2.0.0-alpha11`, `ext-phasync 0.5.0-alpha7`), Node's built-in `http` module, and a Go `net/http` server.

| Connections | Swerve + ext | Node (http) | Go (net/http) |
|---|---:|---:|---:|
| 64 | 193,041 req/s | 132,146 req/s | 39,254 req/s |
| 1,024 | 177,443 req/s | 136,837 req/s | 135,484 req/s |
| 10,000 | 139,988 req/s | 112,588 req/s | 112,381 req/s |
| 50,000 | 131,386 req/s | 100,131 req/s | 96,065 req/s |

At 50,000 connections the client's own ephemeral port range held about 28,000 of them; all three servers show roughly 21,850 connection errors for the rest, so the 50,000 row measures the same held set for each server.

Go's `net/http` result is single-process; the same benchmark also ran Go pinned across several NUMA-aware process layouts (`numactl`, `SO_REUSEPORT`), which raised its own ceiling at 1,024 connections to 161,388 req/s with 2 processes of 28 threads each — still below Swerve and Node at that connection count. Go was not re-run at every connection count in every layout; see the raw log for the layouts that were measured.

## What this does not show

This is one fixed-response route, on one machine, over a LAN with no proxy. It says nothing about an application that does real work per request (a database query, a template), about several machines, or about memory per connection — see [Concurrent connections and memory](/performance/connections-and-memory/) for the latter. Swerve was also measured against other PHP application servers (RoadRunner, FrankenPHP, Swoole) on the same machine; that comparison is in `benchmarks/servers/SUMMARY.md` and `benchmarks/frameworks/SUMMARY.md` of the Swerve repository, not reproduced here.
