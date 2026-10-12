---
title: "Coordination across workers"
summary: "Publish, subscribe and claim: requests that coordinate across worker processes."
order: 4
status: draft
---

A Swerve server runs several worker processes, and a request in one worker sometimes needs to reach a request in another. Three primitives cover this. [[Swerve::publish]] sends a message to a named channel; [[Swerve::subscribe]] receives the messages on that channel in every worker, in the order they were published ([[OrderedChannel]], [[Subscription]]). [[Swerve::claim]] lets exactly one request, in whichever worker, take ownership of a piece of work ([[Claim]]).
