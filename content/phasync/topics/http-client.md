---
title: "HTTP client"
summary: "A PSR-18 client on curl_multi, with PSR-7 requests, responses and URIs."
order: 9
status: draft
---

[[HttpClient]] is a PSR-18 client built on curl's multi interface: requests started with `phasync::go()` in different coroutines run together on one curl handle, and `sendRequest()` returns a PSR-7 [[Response]] once its own request is done. Build the request with [[Request]] and a PSR-7 stream such as [[StringStream]] for its body. See [Concurrent API calls](/guides/concurrent-api-calls/) for a worked example; the package is `2.0.0-alpha1`, with no stable release yet.
