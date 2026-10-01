<?php

// Local preview with GitHub Pages' behaviour for a missing path: 404 with /404.html as the body.
//     php -S localhost:8000 -t public dev-router.php
$path = \rawurldecode(\parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH));
$file = __DIR__ . '/public' . $path;
if (\is_file($file) || \is_file(\rtrim($file, '/') . '/index.html')) {
    return false;
}
\http_response_code(404);
\readfile(__DIR__ . '/public/404.html');
