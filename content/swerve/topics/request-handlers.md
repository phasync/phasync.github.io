---
title: "Request handlers"
summary: "What the application returns, and how a request meets it."
order: 1
status: draft
---

The application returns a [[RequestHandler]] wrapping a closure; swerve calls it once per HTTP exchange with a [[ClientRequest]] to read the request and write the response on. The closure may keep the request open for as long as it likes — an event stream, a slow download — simply by not returning. See [Run your application](/swerve/get-started/run-your-app/).
