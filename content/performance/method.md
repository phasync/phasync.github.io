---
title: "Method"
description: "The hardware, the pinning, the PHP settings and the runs behind the numbers on this site."
status: draft
order: 1
---

Every number on the [Performance](/performance/) pages links back here and to its raw log in the `benchmarks/` directory of the phasync or Swerve repository.

## Machine and pinning

The runs were made on `black`, an AMD Ryzen 9 9950X3D (16 cores / 32 threads, two chiplets), and on a two-socket Xeon E5-2697 v3 pair for the larger connection counts. The server process group is pinned to its own cores with `taskset`; the load generator (`wrk`, or a Go client for WebSockets) runs on separate cores on the same machine, so the network is loopback and the client is never the bottleneck — each result page says what CPU headroom the client had.

## PHP and versions

PHP 8.5.11 (NTS), with `opcache.enable=1`, `opcache.validate_timestamps=0` and the tracing JIT (`opcache.jit_buffer_size=128M`) on every server that runs PHP, including the workers FrankenPHP and RoadRunner spawn. Each result states the exact phasync, Swerve and ext-phasync commit or version tested; several runs predate the 2.0.0 release and are marked as such.

## Runs

A warm-up (3-10 seconds, discarded) precedes each measured window (10-30 seconds). Most comparisons run twice with the servers interleaved, and the tables report the median or the best of the rounds, with the spread stated. Connections, workers and request counts are varied one at a time; a result page states what was held fixed.

## What is logged

Every run's raw `wrk` (or client) output, the CPU and memory samples taken during it, and the command that started each server are kept in the `benchmarks/` directory of the repository the result page names. A result is not written here until its raw log exists there.
