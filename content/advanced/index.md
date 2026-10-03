---
title: Advanced
description: "The parts you do not need on day one: channels, wait groups, rate limiters, claims, the cache."
status: draft
---

Primitives and internals for when the basics are not enough. Deliberately not in the header: most applications never need these.

If you are moving an existing application to Swerve, start with [Learn](/learn/): it explains why code written for one request per process breaks when requests overlap, and what to do about it.
