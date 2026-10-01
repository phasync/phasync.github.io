---
title: HttpClient
description: "An asynchronous PSR-18 HTTP client, in the package phasync/http-client."
symbol: phasync\HttpClient\HttpClient
status: draft
see_also:
  - phasync::go
  - /guides/concurrent-api-calls/
---

`phasync\HttpClient\HttpClient` sends requests with `curl_multi`. Requests run at the same time, and no request starts to fetch until its body is read.

```bash
composer require phasync/http-client
```

## Examples

```php
use phasync\HttpClient\HttpClient;

$client = new HttpClient();

// Neither request starts fetching until its body is read, so both run together.
$a = $client->get('https://example.com/a');
$b = $client->get('https://example.com/b');

echo $a->getBody();
echo $b->getBody();
```

```php
$response = $client->post('https://example.com/post', ['foo' => 'bar']);
echo $response->getBody();
```

A body may be a string, a PSR-7 stream, or an array or object, which is encoded as `application/x-www-form-urlencoded` or JSON depending on the `Content-Type` header. See the package README for multipart uploads and the options.

<!-- TODO-verify: the signatures of get() and post() are not reflected here (the package is not a dependency of this site); examples are from the package README of /home/frode/dev/http-client, not run -->
