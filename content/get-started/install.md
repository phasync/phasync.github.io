---
title: "Install"
description: "Install Swerve with Composer and check that it runs."
status: draft
order: 1
---

Swerve needs PHP 8.2 or later on Linux, with the `pcntl`, `posix` and `sockets` extensions. These
ship with most PHP distributions; `php -m | grep -E 'pcntl|posix|sockets'` lists them if you are
unsure.

Swerve and phasync, the coroutine library it runs on, are both beta, so your project has to allow
pre-release packages before Composer will install them:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve
```

<!-- TODO-verify: as of phasync 2.0.0's release (2026-10-19), the plain beta flow above installs
swerve 0.1.0-beta5, which still calls the removed phasync\ext_enabled() and fatals on --version.
swerve's dev-main already has the fix (and phasync/net only publishes an alpha, which the beta
floor can't reach), so until a swerve tag built against phasync 2.0.0 ships, use:
  composer config minimum-stability dev
  composer require phasync/net:2.0.0-alpha4 phasync/swerve:dev-main
Simplify back to the three lines above once that tag exists. -->

Check that it ran:

```
> vendor/bin/swerve --version
swerve dev-main (PHP 8.5.11, phasync dev-main, phasync-ext not loaded)
```

That is swerve's own version, the PHP it is running on, and the versions of phasync and the
phasync extension. "phasync-ext not loaded" is expected here: the extension is optional, and the
next three pages run without it. [Deploy](/get-started/deploy/) covers loading it for production.

## The phasync extension

phasync 2.0.0 ships ext-phasync, an optional PHP extension, as prebuilt binaries inside its
release packages. It is not required: everything in Get Started runs without it. Installed, it
builds the extension from source and loads it for every SAPI:

```bash
pie install phasync/ext-phasync
```

<!-- TODO-verify: pie install could not be run on this machine (no prebuilt binary for this dev
checkout's platform, and installing build tooling to compile from source was out of scope for
this check) -->

Next: [Run your application](/get-started/run-your-app/).
