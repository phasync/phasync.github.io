---
title: "Write sequential PHP"
description: "Your handler stays sequential: sleep(), and the other requests are served meanwhile."
status: placeholder
order: 1
---

This page will contain:

- What you may write in a handler (`sleep(10);` and then respond), what waits without blocking other requests, and what still blocks a worker. Needs phasync-ext for plain `sleep()`; without it, `phasync::sleep()`.
