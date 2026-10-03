---
title: Frameworks
description: "One adapter per framework: your application runs unchanged. Which adapters serve requests concurrently, and which one at a time."
status: draft
---

An adapter is a one-line `swerve.php` that runs your framework on Swerve. Each page below has the same headings: how to install and start, what the adapter changes, whether requests overlap within a worker, and what breaks when they do.

<!-- TODO-verify: the adapter packages (phasync/swerve-*) were not on Packagist on 2026-10-03; the install lines on each page follow the adapters' READMEs -->

## Concurrent and serialized adapters

A Swerve worker is one PHP process. An adapter either lets several requests run in it at once, or runs one at a time.

| Adapter | Requests in one worker |
|---|---|
| [Laravel](/frameworks/laravel/) | Concurrent. Each in-flight request runs in its own pooled application. |
| [Symfony](/frameworks/symfony/) | Concurrent. Each in-flight request runs in its own pooled kernel; they overlap only while a request waits in a coroutine, which with `phasync-ext` includes database and HTTP calls. |
| [PSR-15](/frameworks/psr-15/) (Slim, Mezzio, your own) | Concurrent. The handler is yours. |
| [Yii](/frameworks/yii/) | **Low concurrency.** One request at a time per worker. |
| [CakePHP](/frameworks/cakephp/) | **Low concurrency.** One request at a time per worker. |
| [CodeIgniter](/frameworks/codeigniter/) | **Low concurrency.** One request at a time per worker. |
| [Laminas](/frameworks/laminas/) | **Low concurrency.** One request at a time per worker. |

## Low concurrency

Yii, CakePHP, CodeIgniter and Laminas keep request state in places that are shared by the whole process: static properties, shared services, `$_SESSION`, PHP's output buffers. The adapters serialize requests with [[phasync\Util\Synchronized]], so one request at a time sees that state.

- A worker serves one of these requests at a time, as a PHP-FPM child does. A request that waits for a database or an API holds its worker, also with `phasync-ext`. Size `--workers` as you size `pm.max_children`.
- What you gain is the other things Swerve does: the booted application is kept warm, static files and keep-alive connections are served meanwhile, and streamed responses, Server-Sent Events and WebSockets do not hold the worker once their request is answered.
- Code inside a WebSocket callback or a coroutine that outlives its request runs beside the next request. It needs the care described in [Why code breaks when requests overlap](/learn/why-code-breaks/).

If your application waits a lot and you need many requests in flight per worker, use a concurrent adapter.

## What every adapter shares

- `swerve.php` is loaded once per worker; the application is booted once, or pooled.
- A blocking call (PDO, curl, `sleep()`) blocks the worker unless `phasync-ext` is loaded: [how Swerve runs](https://github.com/phasync/swerve/blob/main/docs/how-it-runs.md).
- `exit`, `die()` and `dd()` end the worker and the requests it is serving.
- Code that is not safe when requests overlap is found by `phasync/doctor`: [Learn](/learn/) explains the patterns it reports.
