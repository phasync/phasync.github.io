---
title: "Install"
description: "Install Swerve with Composer and check that it runs."
status: draft
order: 1
---

This walks through Swerve, the quickest way to see phasync run a whole application. To add
phasync to code that already runs under PHP-FPM instead, without a server to install, see
[Use phasync in a library](/library/use-phasync-in-a-library/).

Swerve needs PHP 8.2 or later on Linux, with the `pcntl`, `posix` and `sockets` extensions. These
ship with most PHP distributions; `php -m | grep -E 'pcntl|posix|sockets'` lists them if you are
unsure.

Swerve is beta, so your project has to allow pre-release packages before Composer will install
it:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve
```

Check that it ran:

```
vendor/bin/swerve --version
```

This prints swerve's own version, the PHP it is running on, and the versions of phasync and the
phasync extension, with "not loaded" for the extension when it isn't — expected here, since the
extension is optional and the next three pages run without it. [Deploy](/get-started/deploy/)
covers loading it for production.

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
