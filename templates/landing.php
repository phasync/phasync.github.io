<?php
/** The front page: commands first, then short sections with real code. */
$frameworks = [
    'laravel'     => ['Laravel', 'phasync/swerve-laravel', 'return new Swerve\Laravel\Handler(__DIR__);', 'public'],
    'symfony'     => ['Symfony', 'phasync/swerve-symfony', 'return new Swerve\Symfony\Handler(__DIR__);', 'public'],
    'codeigniter' => ['CodeIgniter', 'phasync/swerve-codeigniter', 'return new Swerve\CodeIgniter\Handler(__DIR__);', 'public'],
    'cakephp'     => ['CakePHP', 'phasync/swerve-cakephp', 'return new Swerve\CakePHP\Handler(__DIR__);', 'webroot'],
    'yii'         => ['Yii 3', 'phasync/swerve-yii', "require __DIR__ . '/src/bootstrap.php'; // yiisoft/app's: the autoloader and .env\n\nreturn new Swerve\\Yii\\Handler(__DIR__);", 'public'],
    'laminas'     => ['Laminas', 'phasync/swerve-laminas', 'return new Swerve\Laminas\Handler(__DIR__);', 'public'],
];
?>
<section class="intro">
  <h1>Run your PHP application on Swerve.</h1>
  <p class="lead">A PHP application server for the code you already write: sequential, in the framework you already use. A request can wait for as long as it needs, and WebSockets and Server-Sent Events need no extra service.</p>
<?= code(<<<'T'
$ composer config minimum-stability beta    # while swerve is beta
$ composer config prefer-stable true
$ composer require phasync/swerve
$ vendor/bin/swerve --watch
2026-09-26 17:00:30.12    swerve 0.1.0-beta1 serving ./swerve.php on http://127.0.0.1:8080 with 8 workers
2026-09-26 17:00:31.25 3 GET / 200 1.2ms
T, 'terminal') ?>
  <p><code>swerve.php</code>, next to <code>composer.json</code>, returns a PSR-15 request handler. <code>--watch</code> reloads the workers when a PHP file changes; the other options are in <a href="/get-started/dev-server/">the dev server</a> page and <code>vendor/bin/swerve --help</code>.</p>
</section>

<section id="sequential">
  <h2>Write sequential PHP</h2>
  <p>To delay a response, call <code>sleep()</code> and respond afterwards. The other requests are served while this one waits.</p>
<?= code(<<<'P'
<?php // swerve.php

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        sleep(10);

        return new Response(200, ['Content-Type' => 'text/plain'], "Ten seconds later.\n");
    }
};
P, 'php') ?>
  <!-- TODO-verify: not run yet. Plain sleep() waits without blocking only with phasync-ext loaded (docs/how-it-runs.md, phasync-ext README); without it use phasync::sleep(10) -->
  <p>Plain <code>sleep()</code> waits without blocking the worker when <a href="/library/phasync-ext/">phasync-ext</a> is loaded; Swerve loads it by itself when it is installed (<code>composer require phasync/phasync-ext</code>). Without the extension, <code>sleep()</code> blocks every other request of that worker, and you call <code>phasync::sleep(10)</code> instead. Database queries, API calls and file reads wait the same way. <a href="/guides/sequential-php/">What waits and what blocks</a>.</p>
</section>

<section id="frameworks">
  <h2>Your framework</h2>
  <p>One adapter per framework. <code>public/index.php</code> stays as it is, so the same application still runs under PHP-FPM.</p>
  <!-- TODO-verify: the six swerve-* adapter packages answered 404 on repo.packagist.org on 2026-10-01; the snippets below are from their READMEs (tags 0.1.0-alpha*), not from a successful composer require -->
  <div class="tabs">
<?php $first = true; foreach ($frameworks as $id => [$name, $package, $handler, $public]): ?>
    <input type="radio" name="framework" id="fw-<?= $id ?>"<?= $first ? ' checked' : '' ?>><label for="fw-<?= $id ?>"><?= e($name) ?></label>
    <div class="tab-panel">
<?= code("\$ composer require $package\n\$ vendor/bin/swerve --http=0.0.0.0:8080 --public=$public swerve.php", 'terminal') ?>
<?= code("<?php // swerve.php, next to composer.json\n\nrequire __DIR__ . '/vendor/autoload.php';\n\n$handler", 'php') ?>
      <p><a href="/frameworks/<?= $id ?>/"><?= e($name) ?> on Swerve</a></p>
    </div>
<?php $first = false; endforeach ?>
  </div>
  <p>Anything else that returns a <a href="/frameworks/psr-15/">PSR-15 request handler</a> works too: Slim, Mezzio, your own.</p>
</section>

<section id="realtime">
  <h2>Realtime: publish, subscribe, SSE and WebSocket</h2>
  <p>A message published in any worker reaches the subscribers in every worker. With one publisher, every subscriber gets its messages in the order it published them. This is a complete chat server over Server-Sent Events:</p>
<?= code(<<<'P'
<?php // swerve.php

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' === $request->getMethod()) {
            Swerve::publish('chat', (string) $request->getBody());      // to every subscriber, in every worker

            return new Response(204);
        }

        $subscription = Swerve::subscribe('chat', heartbeat: 15);       // subscribed before we answer
        $out = new UnbufferedStream(1, 60);
        phasync::go(static function () use ($subscription, $out) {
            try {
                foreach ($subscription as $message) {
                    $out->append(null === $message ? ": keep-alive\n\n" : "data: $message\n\n");
                }
            } catch (TimeoutException) {                                // the client left
            } finally {
                $out->end();
            }
        });

        return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $out);
    }
};
P, 'php') ?>
<?= code("const events = new EventSource('/');\nevents.onmessage = (e) => console.log(e.data);\nfetch('/', {method: 'POST', body: 'hello'});", 'js') ?>
  <p>The same room over a WebSocket, with <a href="/WebSocket/"><code>WebSocket</code></a> in place of the stream. Messages from the browser arrive as events; what goes back is a plain loop:</p>
<?= code(<<<'P'
return WebSocket::from($request, static function (WebSocket $ws) {
    $ws->onMessage->listen(static function (string $data, bool $binary) {
        Swerve::publish('chat', $data);                   // to every subscriber, in every worker
    });

    foreach (Swerve::subscribe('chat') as $message) {     // ends when the browser leaves
        $ws->send($message);                              // to this browser
    }
});
P, 'php') ?>
  <!-- TODO-verify: shortened from swerve docs/realtime.md (no message validation); not run as written -->
  <p><a href="/guides/realtime/">Realtime guide</a>, <a href="/Swerve/publish/"><code>Swerve::publish</code></a>, <a href="/Swerve/subscribe/"><code>Swerve::subscribe</code></a>.</p>
</section>

<section id="cache">
  <h2>Cache</h2>
  <p><a href="/Swerve/cache/"><code>Swerve::cache()</code></a> is a PSR-16 cache that every worker shares. Each worker keeps what it has read in its own memory, and the master tells the workers to forget a key when it is written.</p>
<?= code(<<<'P'
$cache   = Swerve::cache();
$sidebar = $cache->get('home:sidebar');
if (null === $sidebar) {
    $sidebar = render_sidebar();
    $cache->set('home:sidebar', $sidebar, 60);   // seconds
}
P, 'php') ?>
  <p><a href="/guides/cache/">Cache guide</a>: how reads and writes travel, and what the cache does not do.</p>
</section>

<section id="after-the-response">
  <h2>Keep working after the response <span class="badge placeholder">Placeholder</span></h2>
  <p>Work started in a request can continue after the response is sent, with what the request had: the user, the request, the open database connection. Send 10,000 e-mails without making the client wait.</p>
  <!-- TODO-verify: sketch only. docs/how-it-runs.md says work after the response (phasync::finally(), a go()) costs the client nothing; the example below is not yet run -->
<?= code(<<<'P'
phasync::go(static function () use ($recipients) {   // runs after the handler returned
    foreach ($recipients as $recipient) {
        // send one e-mail
    }
});
P, 'php') ?>
  <p><a href="/guides/after-the-response/">After the response</a> will have the whole story.</p>
</section>

<section id="reference">
  <h2>Reference and guides</h2>
  <ul class="grid">
    <li><a href="/Swerve/"><strong>Swerve</strong><span>Publish, subscribe, log, cache and claims for the application.</span></a></li>
    <li><a href="/WebSocket/"><strong>WebSocket</strong><span>A WebSocket connection, server side.</span></a></li>
    <li><a href="/HttpClient/"><strong>HttpClient</strong><span>An async PSR-18 client, <code>phasync/http-client</code>.</span></a></li>
    <li><a href="/ProcessRunner/"><strong>ProcessRunner</strong><span>Launch and manage child processes.</span></a></li>
    <li><a href="/guides/tether/"><strong>Tether</strong><span>Interactive components over a WebSocket.</span></a></li>
    <li><a href="/phasync/"><strong>phasync</strong><span>run(), go(), sleep(), await() and the rest of the facade.</span></a></li>
    <li><a href="/performance/"><strong>Performance</strong><span>How we measure, and the results.</span></a></li>
    <li><a href="/guides/"><strong>Guides</strong><span>Realtime, concurrent API calls, running processes, production.</span></a></li>
  </ul>
</section>

<p class="more">Writing a library? <a href="/library/use-phasync-in-a-library/">Use phasync in a library</a>, so that it runs under Swerve, PHP-FPM and the CLI alike.</p>

