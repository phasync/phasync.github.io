---
title: "Frameworks"
summary: "One adapter per framework; your application runs unchanged."
order: 9
status: draft
---

An adapter turns a framework's own request handling into a `Swerve\RequestHandler`, so the application's code, routes and views run unchanged; `swerve.php` becomes the entry point instead of `public/index.php`. See the [frameworks section](/frameworks/) for which ones have an adapter and what each one covers.
