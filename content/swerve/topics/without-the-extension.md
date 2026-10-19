---
title: "Without the extension"
summary: "Write sequential PHP that stays concurrent."
order: 8
status: draft
---

Everything in Swerve works without the phasync extension: a handler written with `phasync::sleep()`, `phasync::readable()`/`writable()`, channels, and the primitives built on them stays concurrent either way. What changes without the extension is how much of the rest of PHP — plain `sleep()`, PDO, mysqli, curl, files — cooperates on its own, and how many connections a worker can hold. See [Write sequential PHP](/guides/sequential-php/) and [The phasync extension](/library/ext-phasync/).
