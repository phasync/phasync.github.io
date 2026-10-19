<?php

/** Stage 2: the site from the model, in the markup of the design kit. URLs: /<project>/{get-started,manual,symbols,examples,changelog}/ and /<project>/<Symbol>/ (never the topic). */
final class Render
{
    public const KIT = '/home/frode/dev/phasync.dev/public/assets';
    public const PROJECTS = ['phasync' => 'phasync', 'swerve' => 'Swerve'];
    /** Topic slugs that changed after publishing: project => old => new; the old URL gets a redirect stub. */
    public const RENAMED_TOPICS = [
        'phasync' => ['extension-and-event-loop' => 'blocking-calls-and-the-extension', 'contexts' => 'scopes-and-frames'],
        'swerve'  => ['publish-and-subscribe' => 'coordination-across-workers', 'code-that-needs-no-extension' => 'without-the-extension'],
    ];
    public const LABEL = ['class' => 'Class', 'interface' => 'Interface', 'exception' => 'Exception', 'enum' => 'Enum', 'trait' => 'Trait', 'method' => 'Method', 'function' => 'Function'];

    public array $map = [];
    public array $written = [];
    public array $search = [];
    public array $counts = [];

    public function __construct(private Symbols $sy, private array $pages, private array $m, private string $out, private string $site)
    {
    }

    private function count(string $k): void
    {
        $this->counts[$k] = ($this->counts[$k] ?? 0) + 1;
    }

    public function symUrl(string $full): string
    {
        return "/{$this->m['symbols'][$full]['project']}/{$this->m['symbols'][$full]['name']}/";
    }

    private function plan(): void
    {
        foreach ($this->m['symbols'] as $full => $s) {
            $a = $this->sy->all[$full] ?? [];
            foreach ([$s['url'], $a['alias'] ?? null, $a['fqnUrl'] ?? null, '/' . \str_replace('\\', '/', $s['name']) . '/'] as $old) {
                $old && $old !== $this->symUrl($full) && $this->map[$old] ??= $this->symUrl($full);
            }
        }
        foreach ($this->m['projects'] as $p => $proj) {
            $this->map["/topics/$p/"] = "/$p/manual/";
            foreach (self::RENAMED_TOPICS[$p] ?? [] as $old => $new) {
                $this->map["/$p/manual/$old/"] = "/$p/manual/$new/";
            }
            foreach ($proj['manual'] as $t) {
                $this->map[$t['url']] = "/$p/manual/{$t['slug']}/";
                $taken = ['examples' => 1];
                foreach ([...$t['concepts'], ...$t['guides']] as $pg) {
                    $seg = \basename(\rtrim($pg['url'], '/'));
                    isset($taken[$seg]) && $seg = \trim(\dirname($pg['url']), '/') . "-$seg";
                    $taken[$seg] = 1;
                    $this->map[$pg['url']] = "/$p/manual/{$t['slug']}/$seg/";
                }
            }
        }
        foreach ($this->pages as $url => $pg) {
            if (isset($this->map[$url]) || '/' === $url || $pg['symbol']) {
                continue;
            }
            $this->map[$url] = 'section' === $pg['kind'] ? ('/get-started/' === $url ? '/swerve/get-started/' : '/swerve/manual/') : '/swerve' . $url;
        }
    }

    public function fix(string $html): string
    {
        return \preg_replace_callback('~href="(/[^"#]*)(#[^"]*)?"~', fn ($m) => 'href="' . ($this->map[\htmlspecialchars_decode($m[1])] ?? $m[1]) . ($m[2] ?? '') . '"', $html);
    }

    /** The package README's first paragraph (as HTML) and first php code block: the landing page's only content. */
    private function readme(string $p): array
    {
        $md = (string) @\file_get_contents(['phasync' => '/home/frode/dev/phasync/README.md', 'swerve' => '/home/frode/dev/swerve/README.md'][$p]);
        $md = \preg_replace('~^(?:!\[|#|\[!\[|<).*$~m', '', $md);
        \preg_match('~^(\S[^\n]*(?:\n(?!```|\n)[^\n]+)*)~m', $md, $para);
        \preg_match('~```php\n(.*?)```~s', $md, $code);

        return [Markdown::html(\trim($para[1] ?? '')), \trim($code[1] ?? '')];
    }

    /** The installed version of the project's package, from composer's installed.json. */
    private function version(string $p): string
    {
        static $pk = null;
        $pk ??= \array_column(\json_decode((string) @\file_get_contents(__DIR__ . '/../vendor/composer/installed.json'), true)['packages'] ?? [], 'version', 'name');

        return \preg_replace('~^v~', '', $pk["phasync/$p"] ?? '');
    }

    /** The three cards (phasync, the extension, Swerve) and the fiber-interop paragraph, phasync's landing page only. */
    private function phasyncHome(): string
    {
        $v = fn (string $p) => ($x = $this->version($p)) ? ' <span class="badge">v' . e($x) . '</span>' : '';

        return '<h2 id="three-ways">phasync, the extension, and Swerve</h2><div class="pricing">'
            . '<div class="plan"><h3>phasync' . $v('phasync') . '</h3>'
            . '<p class="for">The coroutine library. <code>go()</code> and <code>await()</code> on PHP\'s own Fibers, with timeouts and cancellation. No promises.</p>'
            . '<ul><li>One <code>phasync::run()</code> call is a complete program</li><li>Runs under PHP-FPM, the CLI, or Swerve</li></ul>'
            . '<p><code>composer require phasync/phasync</code></p></div>'
            . '<div class="plan featured"><h3>ext-phasync <span class="badge">v2.0.0</span></h3>'
            . '<p class="for">The optional extension. PDO, mysqli, curl, file I/O and <code>sleep()</code> become cooperative inside a coroutine instead of blocking the worker &mdash; no rewrite.</p>'
            . '<ul><li>Epoll instead of <code>stream_select()</code></li><li>Bundles the <code>Scope</code> class, the per-request identity that request-scoped state is keyed on</li></ul>'
            . '<p><a href="/library/ext-phasync/">The phasync extension &rarr;</a></p></div>'
            . '<div class="plan"><h3>Swerve' . $v('swerve') . '</h3>'
            . '<p class="for">The application server. Long requests, Server-Sent Events and WebSocket, in the framework you already use.</p>'
            . '<ul><li>Boots your app once, serves many requests per worker</li><li>Adapters for Laravel, Symfony, Yii and more</li></ul>'
            . '<p><a href="/swerve/">Swerve &rarr;</a></p></div>'
            . '</div>'
            . '<p>Start with phasync alone inside an application you already run under PHP-FPM, to overlap one function\'s own API calls or queries. Reach for Swerve when you want every request handled concurrently, not only that one function.</p>'
            . '<h2 id="fiber-interop">phasync and fiber-interop</h2>'
            . '<p><a href="https://github.com/fiber-interop">fiber-interop</a> is a shared concurrency API (<code>fiber\\go()</code>, <code>fiber\\await()</code> and the rest) that runs unchanged on phasync, Revolt, AMPHP, ReactPHP, Swoole or TrueAsync, picked by installing one adapter package. The <a href="https://github.com/fiber-interop/phasync">fiber-interop/phasync</a> adapter runs it on phasync\'s event loop. fiber-interop\'s own <a href="https://fiber-interop.github.io/#adapter">adapter guide</a> names phasync as the one to start with if you have no event loop of your own yet, calling it &quot;a complete runtime with nothing else to configure&quot;. A library written against <code>fiber-interop/api</code> runs on phasync without depending on it directly.</p>';
    }

    /** Why Swerve exists, for its own landing page: the problem it solves, not just the one-line example. */
    private function swerveHome(): string
    {
        return '<h2 id="why-swerve">Why Swerve</h2><ul>'
            . '<li><b>Connections that stay open.</b> Server-Sent Events, WebSocket and long polling from an ordinary request handler, and publish/subscribe between workers &mdash; no separate Node or Go service.</li>'
            . '<li><b>Boot once, serve forever.</b> The framework, routes and connection pools build once per worker, which then serves thousands of requests and connections.</li>'
            . '<li><b>Isolated requests.</b> Each request runs in a <code>Scope</code> of its own, so request-scoped state stays separate even with thousands in flight.</li>'
            . '<li><b>Frameworks, not a rewrite.</b> One adapter per framework runs your existing application; some serve requests concurrently within a worker, some one at a time. <a href="/swerve/manual/frameworks/">Which adapters overlap requests</a>.</li>'
            . '</ul>';
    }

    /** The first page of the Get started path: what the sub-navigation links to. */
    public function startUrl(string $p): string
    {
        $g = $this->m['projects'][$p]['getStarted'][0] ?? null;

        return $g ? "/$p" . $g['url'] : "/$p/get-started/";
    }

    /** A sub-section's own tree from the model (`sidebars`): see MODEL.md. */
    private function tree(array $tree, string $cur): string
    {
        $h = '<' . ($tree['ordered'] ? 'ol class="steps"' : 'ul') . '>';
        foreach ($tree['items'] as $i => $it) {
            if (isset($it['label'])) {
                $h .= '<li class="lbl">' . e($it['label']) . '</li>';
            } elseif (isset($it['jump'])) {
                $h .= '<li class="jump">' . \implode('', \array_map(fn ($j) => '<a href="' . $j['url'] . '">' . e($j['title']) . '</a>', $it['jump'])) . '</li>';
            } else {
                $h .= '<li><a href="' . $it['url'] . '"' . ($cur === $it['url'] ? ' aria-current="page"' : '') . '>' . ($tree['ordered'] ? '<span class="n">' . ($i + 1) . '</span>' : '') . '<span>' . e($it['title']) . '</span></a></li>';
            }
        }

        return $h . '</' . ($tree['ordered'] ? 'ol' : 'ul') . '>';
    }

    public function sidebar(string $p, ?string $topic, ?string $cur, ?string $class, ?array $tree = null): string
    {
        $a = static fn (string $u, string $t, string $x = '') => '<a href="' . $u . '"' . $x . '>' . $t . '</a>';
        $h = '<aside class="sidebar" id="sidebar" aria-label="' . ($tree ? e($tree['title']) : 'Manual') . '"><div class="side-head"><b>' . $a("/$p/manual/", e(self::PROJECTS[$p])) . '</b>' . (($v = $this->version($p)) ? '<span class="badge' . (\str_contains($v, '-') ? ' beta' : '') . '">' . e($v) . '</span>' : '') . '</div><nav class="side-body" aria-label="' . ($tree ? e($tree['title']) : 'Topics') . '">';
        if ($tree) {
            return $h . $this->tree($tree, (string) $cur) . '</nav></aside>';
        }
        $h .= '<ul>';
        foreach ($this->m['projects'][$p]['manual'] as $t) {
            $u = "/$p/manual/{$t['slug']}/";
            if ($t['slug'] !== $topic) {
                $h .= '<li>' . $a($u, e($t['title'])) . '</li>';
                continue;
            }
            $h .= '<li>' . $a($u, e($t['title']), $cur === $u ? ' aria-current="page"' : ' class="anc"') . '<ul class="parts"><li>' . $a($u . '#introduction', 'Introduction') . '</li>';
            foreach ([...$t['concepts'], ...$t['guides']] as $pg) {
                $h .= '<li>' . $a($this->map[$pg['url']], e($pg['title']), $cur === $this->map[$pg['url']] ? ' aria-current="page"' : '') . '</li>';
            }
            $t['examples'] && $h .= '<li>' . $a($u . 'examples/', 'Examples', $cur === $u . 'examples/' ? ' aria-current="page"' : '') . '</li>';
            if ($t['symbols']) {
                $h .= '<li class="lbl">Reference</li><li><ul class="syms">';
                foreach ($t['symbols'] as $s) {
                    $su = $this->symUrl($s['full']);
                    $open = $class === $s['full'];
                    $h .= '<li>' . $a($su, '<code>' . e($s['name']) . '</code>', $cur === $su ? ' aria-current="page"' : ($open ? ' class="anc"' : ''));
                    if ($open) {
                        $h .= '<ul>';
                        foreach ($s['members'] ?? \array_map(fn ($f) => ['full' => $f], $this->sy->all[$s['full']]['methods'] ?? []) as $mm) {
                            $mu = $this->symUrl($mm['full']);
                            $h .= '<li>' . $a($mu, '<code>' . e(\preg_replace('~^.*::~', '', $this->m['symbols'][$mm['full']]['name'])) . '</code>', $cur === $mu ? ' aria-current="page"' : '') . '</li>';
                        }
                        $h .= '</ul>';
                    }
                    $h .= '</li>';
                }
                $h .= '</ul></li>';
            }
            $h .= '</ul></li>';
        }

        return $h . '</ul></nav></aside>';
    }

    private function synopsis(string $sig, ?string $name = null, ?string $url = null, bool $wrap = true, int $min = 90): string
    {
        $h = e($sig);
        $h = \preg_replace('~^((?:(?:public|protected|static|abstract|final|readonly|function|class|interface|trait|enum) )*)~', '<span class="hl-k">$1</span>', $h);
        $h = \preg_replace('~(?<=[\s(?|])(int|float|string|bool|array|object|mixed|void|callable|iterable|null|self|never)\b(?![\w(])~', '<span class="hl-k">$1</span>', $h);
        $name && $url && $h = \preg_replace('~' . \preg_quote(e($name), '~') . '(?=\()~', '<a href="' . $url . '">' . e($name) . '</a>', $h, 1);
        if ($wrap && \strlen($sig) > $min && \preg_match('~^(.*?\()(.+)(\)(?:: .*)?)$~s', $h, $m)) {
            $parts = []; $d = 0; $c0 = '';
            foreach (\str_split($m[2]) as $c) {
                $d += \in_array($c, ['(', '['], true) ? 1 : (\in_array($c, [')', ']'], true) ? -1 : 0);
                ',' === $c && 0 === $d ? ([$parts[], $c0] = [$c0, '']) : $c0 .= $c;
            }
            $parts[] = $c0;
            $h = $m[1] . "\n    " . \implode(",\n    ", \array_map('trim', $parts)) . "\n" . $m[3];
        }

        return $h;
    }

    private function examples(array $list): string
    {
        $h = '';
        foreach ($list as $ex) {
            $h .= '<figure class="example" id="ex-' . $ex['n'] . '"><figcaption><b>Example ' . $ex['n'] . '</b>' . ($ex['caption'] ? inline($ex['caption']) : '') . '</figcaption>' . "\n"
                . \trim(Markdown::code(\trim($ex['code'], "\n"), 'php')) . "\n"
                . (null !== ($ex['output'] ?? null) && '' !== \trim($ex['output']) ? '<div class="output"><span class="lbl">Output</span><pre>' . e(\rtrim($ex['output'])) . '</pre></div>' : '') . '</figure>';
        }

        return $h;
    }

    private function seeAlso(array $list, string $title = 'See also', string $id = 'see-also'): string
    {
        if (!$list) {
            return '';
        }
        $h = '<h2 id="' . $id . '">' . e($title) . '</h2><div class="seealso"><ul>';
        foreach ($list as $x) {
            $label = ($x['code'] ?? false) ? '<code>' . e($x['name']) . '</code>' : e($x['name']);
            $h .= '<li>' . (($x['url'] ?? null) && (isset($this->map[$x['url']]) || \preg_match('~^(?:https?:|/(?:phasync|swerve)/)~', $x['url'])) ? '<a href="' . ($this->map[$x['url']] ?? $x['url']) . '">' . $label . '</a>' : $label) . '<span>' . inline(\ucfirst($x['desc'] ?? '')) . '</span></li>';
        }

        return $h . '</ul></div>';
    }

    /** The guide and concept pages that link to the symbol: a fact, so its own section after See also. */
    private function usedIn(array $list): string
    {
        return $this->seeAlso($list, 'Used in', 'used-in');
    }

    private function listing(array $rows): string
    {
        $h = '<ul class="listing">';
        foreach ($rows as [$u, $t, $d, $code]) {
            $h .= '<li><a href="' . $u . '">' . ($code ? '<code>' . e($t) . '</code>' : e($t)) . '</a>' . ($d ? ' – ' . inline($d) : '') . '</li>';
        }

        return $h . '</ul>';
    }

    private function callouts(array $list, string $kind): string
    {
        $h = '';
        foreach ($list as $t) {
            $h .= '<div class="callout' . ('Warning' === $kind ? ' warning' : '') . '"><span class="t">' . $kind . '</span>' . Markdown::html($t) . '</div>';
        }

        return $h;
    }

    public function page(string $url, array $o): void
    {
        $o += ['topic' => null, 'cur' => $url, 'class' => null, 'sub' => 'manual', 'prev' => null, 'next' => null, 'section' => null, 'sidebar' => true, 'rail' => true, 'tree' => null];
        $o['tree'] ??= $this->m['projects'][$o['project']]['sidebars'][$o['sub'] ?? ''] ?? null;
        $h2 = [[], [], []];
        $body = \preg_replace_callback('~<h([234])(?: id="([^"]*)")?>(.*?)</h\1>~s', function ($m) use (&$h2) {
            $text = \preg_replace('~<a [^>]*class="anchor"[^>]*>.*?</a>~s', '', $m[3]); // drop the Markdown extension's permalink
            $id = $m[2] ?: Model::headingId($text);
            if ('2' === $m[1]) {
                $h2[1][] = $id;
                $h2[2][] = $text;
            }

            return "<h{$m[1]} id=\"$id\">$text<a class=\"anchor\" href=\"#$id\" aria-label=\"Link to this section\">#</a></h{$m[1]}>";
        }, $this->fix($o['body']));
        $this->write($url, render('layout', $o + ['url' => $url, 'bodyHtml' => $body, 'h2' => $h2, 'site' => $this->site, 'r' => $this]));
        $this->search[] = ['n' => $o['title'], 'd' => $o['desc'], 'u' => $url, 's' => self::PROJECTS[$o['project']]];
    }

    public function write(string $url, string $html): void
    {
        $this->written[$url] = true;
        @\mkdir($this->out . $url, 0777, true);
        \file_put_contents($this->out . $url . 'index.html', $html);
    }

    public function crumbs(array $c): string
    {
        if (\count($c) < 2) {
            return ''; // a one-item trail is only a stray word
        }
        $h = '<nav class="crumbs" aria-label="Breadcrumb"><ol>';
        foreach ($c as $i => [$t, $u]) {
            $h .= $i === \count($c) - 1 || !$u ? '<li aria-current="page">' . e($t) . '</li>' : '<li><a href="' . $u . '">' . e($t) . '</a></li>';
        }

        return $h . '</ol></nav>';
    }

    public function build(): void
    {
        $this->plan();
        foreach ($this->m['projects'] as $p => $proj) {
            $N = self::PROJECTS[$p];
            $base = [[$N, "/$p/"]];
            $manual = $proj['manual'];
            [$lead, $code] = $this->readme($p);
            // phasync's README opens with a teaser that calls an undefined fetch($id): not runnable, so the
            // landing page uses the README's second, self-contained example instead (verified to run as shown).
            'phasync' === $p && $code = <<<'PHP'
                use phasync\Services\CurlAwaiter;

                function fetch(string $url): string
                {
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    // Inside a coroutine this waits without blocking the others; outside, it just runs
                    return CurlAwaiter::await($ch);
                }

                $pages = phasync::run(function () {
                    $a = phasync::go(fn () => fetch('https://www.php.net/'));
                    $b = phasync::go(fn () => fetch('https://getcomposer.org/'));

                    return [phasync::await($a), phasync::await($b)];
                });
                PHP;
            $this->page("/$p/", ['project' => $p, 'title' => $N, 'desc' => \trim(\strip_tags($lead)), 'crumbs' => [], 'sub' => null, 'sidebar' => false,
                'body' => "<h1>$N</h1>" . \preg_replace('~^<p>~', '<p class="lead">', $lead) . ('' !== $code ? \trim(Markdown::code($code, 'php')) : '')
                    . "<p><a class=\"btn btn-primary\" href=\"/$p/get-started/\">Get started</a> <a class=\"btn btn-secondary\" href=\"/$p/manual/\">Read the manual</a></p>"
                    . ('phasync' === $p ? $this->phasyncHome() : $this->swerveHome())]);
            $this->count('landing');
            $gs = $proj['getStarted'];
            $this->page("/$p/get-started/", ['project' => $p, 'title' => 'Get started', 'desc' => 'From install to a running application.', 'crumbs' => [...$base, ['Get started', null]], 'sub' => 'get-started',
                'body' => "<p class=\"eyebrow\">$N</p><h1>Get started</h1><p class=\"lead\">From install to a running application.</p>" . $this->listing(\array_map(fn ($g) => ["/$p" . $g['url'], $g['title'], $g['summary'], false], $gs))]);
            foreach ($gs as $i => $g) {
                $this->page("/$p" . $g['url'], ['project' => $p, 'title' => $g['title'], 'desc' => $g['summary'], 'sub' => 'get-started',
                    'body' => '<p class="eyebrow">Get started</p><h1>' . e($g['title']) . '</h1><p class="lead">' . inline($g['summary']) . '</p>' . $this->pages[$g['url']]['html'],
                    'crumbs' => [...$base, ['Get started', "/$p/get-started/"], [$g['title'], null]],
                    'prev' => $i ? ['url' => "/$p" . $gs[$i - 1]['url'], 'title' => $gs[$i - 1]['title']] : null, 'next' => isset($gs[$i + 1]) ? ['url' => "/$p" . $gs[$i + 1]['url'], 'title' => $gs[$i + 1]['title']] : null]);
                $this->count('get-started');
            }
            $b = "<p class=\"eyebrow\">$N</p><h1>Manual</h1><p class=\"lead\">The manual is a set of topic books. Each has an introduction, concepts, guides, examples and the reference for the classes and functions that belong to it.</p>";
            foreach ($manual as $t) {
                $rows = [["/$p/manual/{$t['slug']}/#introduction", 'Introduction', '', false]];
                foreach ([...$t['concepts'], ...$t['guides']] as $pg) { $rows[] = [$this->map[$pg['url']], $pg['title'], $pg['summary'], false]; }
                $t['examples'] && $rows[] = ["/$p/manual/{$t['slug']}/examples/", 'Examples', 'Every example in this topic, with its output.', false];
                foreach ($t['symbols'] as $s) { $rows[] = [$this->symUrl($s['full']), $s['name'], $s['summary'], true]; }
                $b .= "<section class=\"topic-entry\"><h2><a href=\"/$p/manual/{$t['slug']}/\">" . e($t['title']) . '</a></h2><p class="desc">' . inline($t['summary']) . '</p>' . $this->listing($rows) . '</section>';
            }
            $this->page("/$p/manual/", ['project' => $p, 'title' => 'Manual', 'desc' => "The $N manual, by topic.", 'body' => $b, 'crumbs' => [...$base, ['Manual', null]], 'cur' => null, 'rail' => false]);
            $this->count('manual index');
            foreach ($manual as $ti => $t) {
                $tu = "/$p/manual/{$t['slug']}/";
                $tc = [...$base, ['Manual', "/$p/manual/"]];
                $pgs = [...$t['concepts'], ...$t['guides']];
                $list = fn (array $l) => $this->listing(\array_map(fn ($x) => [$this->map[$x['url']], $x['title'], $x['summary'], false], $l));
                $b = '<p class="eyebrow">Manual</p><h1>' . e($t['title']) . '</h1><p class="lead">' . inline($t['summary']) . '</p><h2 id="introduction">Introduction</h2>' . $t['introduction']
                    . ($t['concepts'] ? '<h2 id="concepts">Concepts</h2>' . $list($t['concepts']) : '') . ($t['guides'] ? '<h2 id="guides">Guides</h2>' . $list($t['guides']) : '')
                    . ($t['examples'] ? '<h2 id="examples">Examples</h2>' . $this->listing([["{$tu}examples/", 'All examples of this topic', \count($t['examples']) . ' with their output.', false]]) : '')
                    . ($t['symbols'] ? '<h2 id="reference">Reference</h2>' . $this->listing(\array_map(fn ($s) => [$this->symUrl($s['full']), $s['name'], $s['summary'], true], $t['symbols'])) : '');
                $this->page($tu, ['project' => $p, 'title' => $t['title'], 'desc' => $t['summary'], 'body' => $b, 'crumbs' => [...$tc, [$t['title'], null]], 'topic' => $t['slug'],
                    'prev' => $ti ? ['url' => "/$p/manual/{$manual[$ti - 1]['slug']}/", 'title' => $manual[$ti - 1]['title']] : null,
                    'next' => $pgs ? ['url' => $this->map[$pgs[0]['url']], 'title' => $pgs[0]['title']] : null]);
                $this->count('topic');
                if ($t['examples']) {
                    $b = '<p class="eyebrow">' . e($t['title']) . '</p><h1>Examples</h1><p class="lead">Every example of this topic, with its output.</p>';
                    foreach ($t['examples'] as $i => $ex) {
                        $b .= '<h2 id="e' . ($i + 1) . '"><a href="' . $this->map[$ex['symbolUrl']] . '"><code>' . e($ex['symbol']) . '</code></a></h2>' . $this->examples([['n' => 1] + $ex]);
                    }
                    $b = \preg_replace('~ id="ex-1"~', '', $b);
                    $this->page("{$tu}examples/", ['project' => $p, 'title' => $t['title'] . ': examples', 'desc' => 'Examples of ' . $t['title'], 'body' => $b, 'crumbs' => [...$tc, [$t['title'], $tu], ['Examples', null]], 'topic' => $t['slug']]);
                    $this->count('topic examples');
                }
                foreach ($pgs as $i => $pg) {
                    $prev = $i ? $pgs[$i - 1] : ['url' => $t['url'], 'title' => $t['title']];
                    $next = $pgs[$i + 1] ?? null;
                    $this->page($this->map[$pg['url']], ['project' => $p, 'title' => $pg['title'], 'desc' => $pg['summary'], 'topic' => $t['slug'], 'crumbs' => [...$tc, [$t['title'], $tu], [$pg['title'], null]],
                        'body' => '<p class="eyebrow">' . e($t['title']) . '</p><h1>' . e($pg['title']) . '</h1><p class="lead">' . inline($pg['summary']) . '</p>' . $this->pages[$pg['url']]['html'] . $this->seeAlso($this->m['pages'][$pg['url']]['see_also'] ?? []),
                        'prev' => ['url' => $this->map[$prev['url']], 'title' => $prev['title']], 'next' => $next ? ['url' => $this->map[$next['url']], 'title' => $next['title']] : null]);
                    $this->count('guide/concept');
                }
            }
        }
        foreach ($this->pages as $url => $pg) {
            $new = $this->map[$url] ?? null;
            if (!$new || $pg['symbol'] || isset($this->written[$new]) || \str_starts_with($new, '/swerve/manual') || \str_starts_with($new, '/swerve/get-started') || '/' === $url) {
                continue;
            }
            $this->page($new, ['project' => 'swerve', 'title' => $pg['title'], 'desc' => $pg['description'], 'sub' => null, 'crumbs' => [['Swerve', '/swerve/'], [$pg['title'], null]],
                'body' => '<h1>' . e($pg['title']) . '</h1><p class="lead">' . inline($pg['description']) . '</p>' . $pg['html']]);
            $this->count('other pages');
        }
        foreach ($this->m['symbols'] as $full => $ms) {
            $this->symbolPage($full, $ms);
        }
        $this->indexes();
        $this->finish();
    }

    private function symbolPage(string $full, array $ms): void
    {
        $s = $this->sy->all[$full];
        $p = $ms['project'];
        $kind = $s['kind'];
        $method = 'method' === $kind;
        $callable = $method || 'function' === $kind;
        $hand = ($this->pages[$s['url']] ?? null)['html'] ?? '';
        $rest = \preg_replace('~```php.*?```\n?~s', '', $s['rest'] ?? '');
        $desc = '' !== \trim($rest) ? Markdown::html($rest) : '';
        $cls = $method ? \preg_replace('~^.*\\\\~', '', $s['class']) : null;
        $cu = $method && isset($this->m['symbols'][$s['class']]) ? $this->symUrl($s['class']) : null;
        $b = '<p class="eyebrow">' . self::LABEL[$kind] . '</p><div class="nameline"><h1>' . e($s['name']) . '</h1>' . ($ms['since'] ? '<span class="badge">since ' . e($ms['since']) . '</span>' : '') . (!empty($ms['deprecated']) ? '<span class="badge">deprecated</span>' : '') . '</div>'
            . ('' !== $s['desc'] ? '<p class="lead">' . inline($s['desc']) . '</p>' : '');
        if ($callable) {
            $sig = $this->synopsis($method ? \preg_replace('~^(.*?)(\w+)::~', '$1$2::', $s['sig']) : $s['sig'], null, null, true, 0);
            $cu && $sig = \preg_replace('~' . \preg_quote(e($cls), '~') . '(?=::)~', '<a href="' . $cu . '">' . e($cls) . '</a>', $sig, 1);
            $b .= '<h2 id="description">Description</h2><div class="code synopsis"><pre><code>' . $sig . '</code></pre></div>' . $desc . $hand;
            if ($s['params']) {
                $b .= '<h2 id="parameters">Parameters</h2><dl class="params">';
                foreach ($s['params'] as $a) {
                    $b .= '<div><dt><span class="name">' . ($a['variadic'] ? '...' : '') . '$' . e($a['name']) . '</span>' . ($a['type'] ? ' <span class="type">' . e($a['type']) . '</span>' : '') . (null !== $a['default'] ? ' <span class="def">= ' . e($a['default']) . '</span>' : '') . '</dt><dd>' . ($a['desc'] ? inline(\ucfirst($a['desc'])) : '') . '</dd></div>';
                }
                $b .= '</dl>';
            }
            ($s['returnDesc'] || ($s['return'] && 'void' !== $s['return'])) && $b .= '<h2 id="return-values">Return values</h2><p>' . ($s['returnDesc'] ? inline(\ucfirst($s['returnDesc'])) . ' ' : '') . '<code>' . e($s['return']) . '</code></p>';
            if ($s['throws']) {
                $b .= '<h2 id="errors">Errors / Exceptions</h2><div class="table-wrap"><table><thead><tr><th>Exception</th><th>When</th></tr></thead><tbody>';
                foreach ($s['throws'] as [$c, $d]) {
                    $t = $this->sy->find($c);
                    $b .= '<tr><td>' . ($t && isset($this->m['symbols'][$t['full']]) ? '<a href="' . $this->symUrl($t['full']) . '"><code>' . e($c) . '</code></a>' : '<code>' . e($c) . '</code>') . '</td><td>' . ($d ? inline(\ucfirst($d)) : '') . '</td></tr>';
                }
                $b .= '</tbody></table></div>';
            }
            $rows = \array_map(fn ($c) => [$c['version'], $c['text']], $ms['changed'] ?? []);
            $ms['since'] && $rows[] = [$ms['since'], 'Added.'];
            if ($rows) {
                $b .= '<h2 id="changelog">Changelog</h2><div class="table-wrap"><table><thead><tr><th>Version</th><th>Change</th></tr></thead><tbody>';
                foreach ($rows as [$v, $t]) { $b .= '<tr><td class="nw"><code>' . e($v) . '</code></td><td>' . inline($t) . '</td></tr>'; }
                $b .= '</tbody></table></div>';
            }
        } else {
            ('' !== $desc || $hand) && $b .= '<h2 id="introduction">Introduction</h2>' . $desc . $hand;
            $b .= '<h2 id="synopsis">Class synopsis</h2><div class="code synopsis"><pre><code>' . $this->synopsis($s['sig'], null, null, false) . " {\n";
            $s['methods'] && $b .= '    <span class="hl-c">/* Methods */</span>';
            foreach ($s['methods'] ?? [] as $mf) {
                $mm = $this->sy->all[$mf];
                $b .= "\n    " . \str_replace("\n", "\n    ", $this->synopsis($mm['sig'], $mm['method'], $this->symUrl($mf)));
            }
            $b .= "\n}</code></pre></div>";
            foreach ([['consts', 'Constants', '<th>Name</th><th>Value</th><th>Description</th>', fn ($c) => '<td><code>' . e($c['name']) . '</code></td><td><code>' . e($c['value']) . '</code></td><td>' . inline($c['desc']) . '</td>'],
                ['props', 'Properties', '<th>Name</th><th>Type</th><th>Description</th>', fn ($c) => '<td><code>$' . e($c['name']) . '</code></td><td><code>' . e(\trim($c['mods'] . ' ' . $c['type'])) . '</code></td><td>' . inline($c['desc']) . '</td>']] as [$k, $title, $th, $row]) {
                $s[$k] && $b .= '<h2 id="' . \strtolower($title) . "\">$title</h2><div class=\"table-wrap\"><table><thead><tr>$th</tr></thead><tbody><tr>" . \implode('</tr><tr>', \array_map($row, $s[$k])) . '</tr></tbody></table></div>';
            }
            if ($s['methods']) {
                $b .= '<h2 id="methods">Methods</h2><div class="table-wrap"><table><thead><tr><th>Method</th><th>Description</th></tr></thead><tbody>';
                foreach ($s['methods'] as $mf) { $b .= '<tr><td><a href="' . $this->symUrl($mf) . '"><code>' . e($this->sy->all[$mf]['method']) . '</code></a></td><td>' . inline($this->sy->all[$mf]['desc']) . '</td></tr>'; }
                $b .= '</tbody></table></div>';
            }
        }
        ($ex = $this->examples($ms['examples'] ?? [])) && $b .= '<h2 id="examples">Examples</h2>' . $ex;
        ($n = $this->callouts($ms['notes'] ?? [], 'Note') . $this->callouts($ms['warnings'] ?? [], 'Warning')) && $b .= '<h2 id="notes">Notes</h2>' . $n;
        $b .= $this->seeAlso($ms['see_also'] ?? []);
        $b .= $this->usedIn($ms['used_in'] ?? []);
        $t = null;
        foreach ($this->m['projects'][$p]['manual'] as $mt) { $mt['slug'] === $ms['topic'] && $t = $mt; }
        $c = [[self::PROJECTS[$p], "/$p/"], ['Manual', "/$p/manual/"]];
        $t ? $c[] = [$t['title'], "/$p/manual/{$t['slug']}/"] : $c[] = ['Index', "/$p/symbols/"];
        $method && $c[] = [$cls, $cu];
        $c[] = [$method ? $s['method'] : $s['name'], null];
        $this->page($this->symUrl($full), ['project' => $p, 'title' => $s['name'], 'desc' => $s['plain'] ?: self::LABEL[$kind] . ' ' . $s['name'] . '.', 'body' => $b, 'crumbs' => $c, 'topic' => $t['slug'] ?? null, 'class' => $method ? $s['class'] : $full]);
        $this->count($method ? 'method' : ($callable ? 'function' : 'class'));
    }

    private function indexes(): void
    {
        foreach ($this->m['projects'] as $p => $proj) {
            $N = self::PROJECTS[$p];
            $topics = \array_column($proj['manual'], null, 'slug');
            $row = fn ($s, $m = false) => '<li' . ($m ? ' class="m" hidden' : '') . '><a href="' . $this->symUrl($s['full']) . '"><code class="t">' . e($s['name']) . '</code></a> – <span class="s">' . inline($s['summary']) . '</span>'
                . (isset($topics[$s['topic'] ?? '']) ? ' <a class="topic" href="/' . $p . '/manual/' . $s['topic'] . '/">' . e($topics[$s['topic']]['title']) . '</a>' : '<span class="topic"></span>') . '</li>';
            foreach ([['', 'Symbol index', 'Every class, interface, exception and function', fn () => true], ['classes/', 'Classes', 'Every class, interface, exception, trait and enum', fn ($s) => 'function' !== $s['kind']], ['functions/', 'Functions', 'Every function', fn ($s) => 'function' === $s['kind']]] as [$seg, $title, $what, $keep]) {
                $by = $mem = [];
                $syms = \array_filter($proj['index']['symbols'], $keep);
                if (!$syms) {
                    continue;
                }
                foreach ($syms as $s) { $by[\strtoupper($s['name'][0])][] = $s; }
                foreach ($proj['index']['members'] as $s) { $mem[\strtoupper($s['name'][0])][] = $s; }
                \ksort($by);
                $b = "<p class=\"eyebrow\">Index</p><h1>$title</h1><p class=\"lead\">$what, A to Z, with the topic it belongs to. Type to filter; methods appear when you filter.</p>"
                    . '<div class="listing-filter" data-filter><input type="search" placeholder="Filter symbols" aria-label="Filter symbols" autocomplete="off" spellcheck="false"><span class="count" aria-live="polite">' . \count($syms) . ' symbols</span></div>';
                foreach ($by as $l => $list) {
                    $b .= '<section data-letter><h2>' . $l . '</h2><ul class="listing">' . \implode('', \array_map($row, $list)) . \implode('', \array_map(fn ($x) => $row($x, true), $mem[$l] ?? [])) . '</ul></section>';
                }
                $this->page("/$p/symbols/$seg", ['project' => $p, 'title' => $title, 'desc' => "$what, A to Z.", 'body' => $b, 'sub' => 'symbols', 'rail' => false,
                    'tree' => $proj['sidebars']['' === $seg ? 'symbols' : 'symbols/' . \rtrim($seg, '/')],
                    'crumbs' => '' === $seg ? [[$N, "/$p/"], ['Index', null]] : [[$N, "/$p/"], ['Index', "/$p/symbols/"], [$title, null]]]);
            }
            $b = "<p class=\"eyebrow\">$N</p><h1>Examples</h1><p class=\"lead\">Every example by topic, with the page it belongs to.</p>";
            foreach ($proj['manual'] as $t) {
                $t['examples'] && $b .= "<h2 id=\"{$t['slug']}\"><a href=\"/$p/manual/{$t['slug']}/examples/\">" . e($t['title']) . '</a></h2>' . $this->listing(\array_map(fn ($e) => [$this->map[$e['symbolUrl']] . '#ex-' . $e['n'], $e['symbol'], $e['caption'], true], $t['examples']));
            }
            $this->page("/$p/examples/", ['project' => $p, 'title' => 'Examples', 'desc' => 'Every example by topic.', 'body' => $b, 'crumbs' => [[$N, "/$p/"], ['Examples', null]], 'sub' => 'symbols']);
            $cl = $proj['changelog'];
            $b = "<p class=\"eyebrow\">$N</p><h1>Changelog</h1>" . ($cl['html'] ?? '<p class="lead">The changelog is not published yet.</p><span class="badge">draft</span>');
            $this->page("/$p/changelog/", ['project' => $p, 'title' => 'Changelog', 'desc' => 'What changed in each release.', 'body' => $b, 'crumbs' => [[$N, "/$p/"], ['Changelog', null]], 'sub' => 'changelog', 'rail' => false]); // the sidebar lists the releases
            foreach ($cl['pages'] as $pg) {
                $this->page("/$p/changelog/{$pg['slug']}/", ['project' => $p, 'title' => $pg['title'], 'desc' => $pg['title'] . " of $N.", 'sub' => 'changelog', 'crumbs' => [[$N, "/$p/"], ['Changelog', "/$p/changelog/"], [$pg['title'], null]],
                    'body' => "<p class=\"eyebrow\">Changelog</p><h1>" . e($pg['title']) . '</h1>' . $pg['html']]);
            }
            $this->count('symbol index'); $this->count('examples index'); $this->count('changelog');
        }
    }

    private function finish(): void
    {
        \exec('cp -r ' . \escapeshellarg(self::KIT) . ' ' . \escapeshellarg($this->out . '/assets'));
        \file_put_contents($this->out . '/404.html', render('layout', ['project' => 'phasync', 'title' => 'Not found', 'desc' => 'No such page.', 'bodyHtml' => '<h1>Not found</h1><p class="lead">There is no such page. Try the <a href="/phasync/symbols/">symbol index</a> or the <a href="/phasync/manual/">manual</a>.</p>',
            'crumbs' => [], 'sub' => null, 'topic' => null, 'cur' => null, 'class' => null, 'prev' => null, 'next' => null, 'sidebar' => false, 'rail' => true, 'url' => '/404.html', 'h2' => [[], [], []], 'site' => $this->site, 'r' => $this]));
        \file_put_contents($this->out . '/search.json', \json_encode($this->search, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        // The bare domain root has no page of its own (only /phasync/ and /swerve/ do): send it to phasync's.
        isset($this->written['/']) || $this->write('/', stub($this->pages['/']['title'] ?? 'phasync', '/', '/phasync/', $this->site));
        $txt = '';
        \ksort($this->map);
        foreach ($this->map as $old => $new) {
            if (!isset($this->written[$old])) {
                $this->write($old, stub('Moved', $old, $new, $this->site));
                $txt .= "$old $new\n";
                $this->count('redirect stubs');
            }
        }
        \file_put_contents($this->out . '/redirects.txt', $txt);
        \file_put_contents($this->out . '/.nojekyll', '');
    }
}
