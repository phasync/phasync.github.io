---
title: Learn
description: "How to write code that runs correctly when a worker serves many requests at once."
status: draft
---

A Swerve worker serves many requests at once, and keeps its memory between them. Code written for PHP-FPM, where each request has a process of its own, can share state by accident. These pages explain what to look for and what to write instead. They are meant to be read in order; each ends with a link to the next.

Start with [why code breaks when requests overlap](/learn/why-code-breaks/). The pages after the [Laravel case study](/learn/laravel/) apply the same ideas to Laravel's own state and are not needed to follow the rest.
