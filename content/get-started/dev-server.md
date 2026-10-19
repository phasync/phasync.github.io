---
title: "The dev server"
description: "Development with --watch: reload on every change."
status: draft
order: 4
---

```
> vendor/bin/swerve --watch
2026-10-19 15:49:17.85   swerve dev-main serving ./swerve.php on http://127.0.0.1:8080 with 1 worker, reloading when PHP files change
2026-10-19 15:49:18.69 0 GET / 200 0.2ms
2026-10-19 15:49:19.86   Change detected in /srv/app/swerve.php; reloading
2026-10-19 15:49:19.86   Reload requested (changed: /srv/app/swerve.php): stopping 1 workers, then restarting swerve
2026-10-19 15:49:20.19   swerve dev-main serving ./swerve.php on http://127.0.0.1:8080 with 1 worker, reloading when PHP files change
2026-10-19 15:49:20.19   Reload complete in 0.33 s
2026-10-19 15:49:21.02 0 GET / 200 0.2ms
```

Saving a file stops every worker (a request already in flight gets a `503`), then starts new
ones running the changed code: old and new code never run side by side. swerve waits 1 to 2
seconds after the last change before reloading, so saving several files at once costs one reload,
not several.

## What reloads, and what does not

`--watch` scans the `*.php` files next to and below the swerve file, skipping `vendor/` and names
starting with a dot (an editor's lock file). `vendor/composer/installed.php` is the exception: it
changes on `composer install`, so a dependency update reloads too.

A reload re-reads the swerve file and every class your application autoloads in the worker,
Composer's class map and PSR-4 prefixes included. It does not pick up swerve itself, phasync,
anything loaded through Composer's `files` autoloading, `php.ini`, or the command line options
`--watch` was started with: restart `vendor/bin/swerve` for those.

A syntax error in a changed file is logged, and the workers keep failing to start — the old code
is already gone — until you fix it and save again.
