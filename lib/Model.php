<?php

/**
 * The site model (see MODEL.md): what the pages and the reference are, before anything is rendered.
 * Topics, the Manual, the Index, breadcrumbs, previous/next, See also, examples with their output.
 * `$problems` is the lint, in the shape of Symbols::$problems: package, kind, symbol, message.
 */
final class Model
{
    /** The names of the facade classes: their methods take their own @topic */
    public const FACADES = ['phasync', 'Swerve\Swerve'];
    public const PROJECTS = ['phasync' => 'phasync', 'swerve' => 'Swerve'];
    public const EXAMPLE_TIMEOUT = 20;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    public array $problems = [];
    /** @var list<string> advisory lint lines (one-way @see pairs); never a failure */
    public array $info = [];
    public array $data = [];

    /** The project a package documents: http-client is part of phasync. */
    public static function project(?string $package): string
    {
        return 'phasync/swerve' === $package ? 'swerve' : 'phasync';
    }

    /**
     * @param array<string, array> $pages url => page (build.php), with front, raw
     */
    public function __construct(private Symbols $symbols, private array $pages, bool $runExamples)
    {
        $topics = $this->loadTopics();
        $this->assignTopics($topics);
        foreach ($this->pages as $p) {
            $f = $p['front'] ?? [];
            if (isset($f['topic']) && !isset($topics[$f['project'] ?? ''][$f['topic']])) {
                $this->problem('site', 'page-topic', $p['url'], "{$p['source']}: topic '{$f['topic']}' is not a topic of project '" . ($f['project'] ?? '') . "' (front matter needs project: and topic:)");
            } elseif (!isset($f['topic']) && 'page' === $p['kind'] && $p['source'] && \preg_match('~^/(?:guides|learn|advanced|frameworks|library)/~', $p['url'])) {
                $this->problem('site', 'page-topic', $p['url'], "{$p['source']}: no topic in the front matter");
            }
        }
        $examples = $this->examples($runExamples);
        [$see, $used] = $this->seeAlso();
        $this->data = $this->assemble($topics, $examples, $see, $used);
    }

    private function problem(string $package, string $kind, string $symbol, string $message): void
    {
        $this->problems[] = [$package, $kind, $symbol, $message];
    }

    /** @return array<string, array<string, array>> project => slug => topic */
    private function loadTopics(): array
    {
        $topics = [];
        foreach (\array_keys(self::PROJECTS) as $project) {
            foreach (\glob(__DIR__ . "/../content/$project/topics/*.md") ?: [] as $file) {
                [$front, $html] = Markdown::file(\file_get_contents($file));
                $slug = \basename($file, '.md');
                $html = $this->symbols->linkify($html, "content/$project/topics/$slug.md");
                foreach (['title', 'summary', 'order'] as $key) {
                    if (!isset($front[$key])) {
                        $this->problem('site', 'topic-file', "$project/$slug", "content/$project/topics/$slug.md: front matter needs $key");
                    }
                }
                $topics[$project][$slug] = [
                    'slug' => $slug, 'project' => $project, 'title' => (string) ($front['title'] ?? $slug), 'summary' => (string) ($front['summary'] ?? ''),
                    'order' => (int) ($front['order'] ?? 50), 'status' => $front['status'] ?? 'draft', 'introduction' => $html,
                    'url' => "/topics/$project/$slug/",
                ];
            }
            \uasort($topics[$project], static fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
        }

        return $topics;
    }

    /** A symbol's topic: @topic in the source, else topics-map.php, else none. Methods of non-facade classes take the class's. */
    private function assignTopics(array $topics): void
    {
        $map = [];
        foreach (require __DIR__ . '/../topics-map.php' as $name => $slug) {
            try {
                $s = $this->symbols->find($name);
            } catch (BuildFailure $e) {
                $s = null;
                $this->problem('site', 'map', $name, "topics-map.php: $name is ambiguous; use the full name");
                continue;
            }
            $s ? $map[$s['full']] = $slug : $this->problem('site', 'map', $name, "topics-map.php: $name names no symbol");
        }
        $all = &$this->symbols->all;
        foreach ($all as $full => &$s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            $s['project'] = self::project($s['package'] ?? null);
            $own = $s['doc']['topic'] ?? null;
            $src = null;
            $slug = null;
            if ('method' === $s['kind'] && !\in_array($s['class'], self::FACADES, true)) {
                $slug = $all[$s['class']]['topic'] ?? null; // classes come first: names sort before their methods
                $src  = $all[$s['class']]['topicSource'] ?? null;
            } elseif (null !== $own) {
                [$slug, $src] = [$own, 'source'];
                if (isset($map[$full]) && $map[$full] !== $own) {
                    // The map is the decided table: it wins until the source is fixed
                    $this->problem($s['package'], 'map', $full, "$full: @topic $own in the source, topics-map.php says {$map[$full]}; change the source to @topic {$map[$full]} ({$s['where']})");
                    [$slug, $src] = [$map[$full], 'override'];
                }
            } elseif (isset($map[$full])) {
                [$slug, $src] = [$map[$full], 'map'];
            }
            $s['topic']       = $slug;
            $s['topicSource'] = $src;
            if (null !== $slug && !isset($topics[$s['project']][$slug])) {
                $this->problem($s['package'], 'topic-unknown', $full, "$full: topic '$slug' is not a topic of {$s['project']} ({$s['where']})");
                $s['topic'] = $s['topicSource'] = null;
            } elseif (null === $slug && ('method' !== $s['kind'] || \in_array($s['class'], self::FACADES, true))) {
                $this->problem($s['package'], 'unmapped', $full, "$full has no topic ({$s['where']})");
            } elseif ('map' === $src && ('method' !== $s['kind'] || \in_array($s['class'], self::FACADES, true))) {
                $this->problem($s['package'], 'untagged', $full, "$full: topic '$slug' comes from topics-map.php; add @topic $slug ({$s['where']})");
            }
        }
    }

    /** Run every @example file once, concurrently; returns symbol => numbered examples. */
    private function examples(bool $run): array
    {
        $jobs = [];
        foreach ($this->symbols->all as $s) {
            foreach ($s['examples'] ?? [] as $e) {
                $jobs[$e['file']] ??= ['root' => $s['root'], 'path' => $e['path']];
            }
        }
        $results = $run ? $this->run($jobs) : [];
        $out     = [];
        foreach ($this->symbols->all as $full => $s) {
            $n = 0;
            foreach ($s['examples'] ?? [] as $e) {
                $r = $results[$e['file']] ?? ['output' => null, 'exit' => null, 'ok' => null, 'timeout' => false, 'seconds' => null];
                $out[$full][] = ['n' => ++$n, 'kind' => 'file', 'path' => $e['path'], 'caption' => $e['caption'], 'code' => $e['code']] + $r;
                if (false === $r['ok']) {
                    $head = \implode(' | ', \array_slice(\preg_split('/\R/', \trim((string) $r['output'])), 0, 3));
                    $this->problem($s['package'], 'example-run', $full, "@example {$e['path']} of $full " . ($r['timeout'] ? 'timed out' : "exited {$r['exit']}") . ": $head");
                }
            }
            foreach ($s['doc']['fences'] ?? [] as $code) {
                $out[$full][] = ['n' => ++$n, 'kind' => 'inline', 'path' => null, 'caption' => '', 'code' => \trim($code, "\n"), 'output' => null, 'exit' => null, 'ok' => null, 'timeout' => false, 'seconds' => null];
            }
        }

        return $out;
    }

    /** `php -d display_errors=1 path` from the package root, the docs repo's vendor/autoload.php prepended, at most 8 at once, 20 s each. */
    private function run(array $jobs): array
    {
        $results = $running = [];
        $queue   = $jobs;
        while ($queue || $running) {
            while ($queue && \count($running) < 8) {
                $file = \array_key_first($queue);
                $job  = \array_shift($queue);
                $p    = \proc_open([\PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'auto_prepend_file=' . \dirname(__DIR__) . '/vendor/autoload.php', $job['path']], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $job['root']);
                \stream_set_blocking($pipes[1], false);
                \stream_set_blocking($pipes[2], false);
                $running[$file] = ['p' => $p, 'pipes' => $pipes, 'out' => '', 'start' => \microtime(true)];
            }
            foreach ($running as $file => &$r) {
                foreach ([1, 2] as $i) {
                    $r['out'] .= (string) \stream_get_contents($r['pipes'][$i]);
                }
                $status  = \proc_get_status($r['p']);
                $elapsed = \microtime(true) - $r['start'];
                $timeout = $status['running'] && $elapsed > self::EXAMPLE_TIMEOUT;
                if ($timeout) {
                    \proc_terminate($r['p'], 9);
                }
                if (!$status['running'] || $timeout) {
                    foreach ([1, 2] as $i) {
                        $r['out'] .= (string) \stream_get_contents($r['pipes'][$i]);
                        \fclose($r['pipes'][$i]);
                    }
                    \proc_close($r['p']);
                    $exit = $timeout ? null : $status['exitcode'];
                    $results[$file] = ['output' => $r['out'], 'exit' => $exit, 'ok' => !$timeout && 0 === $exit, 'timeout' => $timeout, 'seconds' => \round($elapsed, 2)];
                    unset($running[$file]);
                }
            }
            unset($r);
            $running && \usleep(20000);
        }

        return $results;
    }

    /**
     * See also is the symbol's own @see lines, in the author's order, and nothing else. "Used in" is the guide and concept
     * pages that link to the symbol with [[Symbol]] or {@see}. One-way @see pairs (A names B, B does not name A) go to
     * $info, unless the symbol named has `@see none`.
     *
     * @return array{0: array<string, list<array>>, 1: array<string, list<array>>} see also, used in: symbol full => entries
     */
    private function seeAlso(): array
    {
        $used = [];
        foreach ($this->pages as $page) {
            if (!empty($page['raw']) && null === $page['symbol']) {
                \preg_match_all('~\[\[([\w\\\\]+(?:::\w+)?)\]\]|\{@see\s+([\w\\\\]+(?:::\w+)?)(?:\(\))?[^}]*\}~', $page['raw'], $m, \PREG_SET_ORDER);
                foreach ($m as $hit) {
                    try {
                        $t = $this->symbols->find($hit[1] ?: $hit[2]);
                    } catch (BuildFailure) {
                        continue;
                    }
                    $t && ($used[$t['full']][$page['url']] = ['name' => $page['title'], 'url' => $page['url'], 'desc' => $page['description'], 'source' => '', 'code' => false]);
                }
            }
        }
        $names = []; // full => [target full => true]
        foreach ($this->symbols->all as $full => $s) {
            foreach ($s['doc']['see'] ?? [] as [$ref, , $type]) {
                if ('symbol' === $type && ($t = $this->symbols->find($ref)) && $t['full'] !== $full) {
                    $names[$full][$t['full']] = true;
                }
            }
        }
        foreach ($names as $from => $targets) {
            foreach ($targets as $to => $_) {
                if (!isset($names[$to][$from]) && !($this->symbols->all[$to]['doc']['seeNone'] ?? false)) {
                    $this->info[] = "$from names $to with @see; $to does not name $from (add it, or @see none on $to)";
                }
            }
        }
        $see = [];
        foreach ($this->symbols->all as $full => $s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            $list = [];
            foreach ($s['see'] as $e) {
                $list[$e['url'] ?? $e['name']] ??= ['name' => $e['name'], 'url' => $e['url'], 'desc' => $e['desc'], 'source' => '', 'code' => $e['code']];
            }
            unset($list[$s['url']]);
            $see[$full] = \array_values($list);
            $used[$full] = \array_values($used[$full] ?? []);
        }

        return [$see, $used];
    }

    private function item(array $s): array
    {
        return ['name' => $s['name'], 'full' => $s['full'], 'kind' => $s['kind'], 'url' => $s['url'], 'summary' => $s['plain'], 'topic' => $s['topic'] ?? null];
    }

    /** The id Render gives a heading (and so the anchor the sidebars link to). */
    public static function headingId(string $text): string
    {
        return \preg_replace('~^(?=\d)~', 'v', \trim(\preg_replace('~[^a-z0-9]+~', '-', \strtolower(\strip_tags($text))), '-'));
    }

    /**
     * The Changelog sub-section of a project, from the package repos: the changelog itself, one release per `## ` heading,
     * and the pages Upgrading (the breaking entries), Planned changes (content/<project>/changelog/planned.md, curated; never a package roadmap note) and Development process
     * (CONTRIBUTING.md, SECURITY.md). A page with no source is left out.
     *
     * @return array{html: ?string, releases: list<array{title: string, id: string}>, pages: list<array{slug: string, title: string, html: string}>}
     */
    private function changelog(string $project): array
    {
        $root  = ['phasync' => '/home/frode/dev/phasync', 'swerve' => '/home/frode/dev/swerve'][$project];
        $read  = static fn (string $f): ?string => \is_file("$root/$f") ? (string) \file_get_contents("$root/$f") : null;
        $md    = $read('CHANGELOG.md');
        $out   = ['html' => null, 'releases' => [], 'pages' => []];
        $demote = static fn (string $html) => \preg_replace_callback('~<(/?)h([1-5])>~', static fn ($m) => "<{$m[1]}h" . ($m[2] + 1) . '>', $html);
        if (null !== $md) {
            $out['html'] = \preg_replace('~^<h1[^>]*>.*?</h1>~s', '', Markdown::html($md));
            // A relative link to another root doc of the package repo (UPGRADING.md, say) means this site's own page for it.
            $slugs = ['UPGRADING' => 'upgrading', 'CHANGELOG' => '', 'CONTRIBUTING' => 'process', 'SECURITY' => 'process'];
            $out['html'] = \preg_replace_callback('~href="(UPGRADING|CHANGELOG|CONTRIBUTING|SECURITY)\.md"~', static fn ($m) => 'href="/' . $project . '/changelog/' . $slugs[$m[1]] . '/"', $out['html']);
            $version = null;
            $section = '';
            $breaking = []; // version => [markdown bullet...]
            $lines = \preg_split('/\R/', $md);
            foreach ($lines as $i => $line) {
                if (\preg_match('~^## (.+)$~', $line, $m)) {
                    $version = \trim($m[1]);
                    $section = '';
                    $out['releases'][] = ['title' => $version, 'id' => self::headingId($version)];
                } elseif (\preg_match('~^### (.+)$~', $line, $m)) {
                    $section = $m[1];
                } elseif (null !== $version && \str_starts_with($line, '- ')) {
                    $bullet = $line;
                    for ($j = $i + 1; isset($lines[$j]) && \preg_match('~^\s+\S~', $lines[$j]); $j++) {
                        $bullet .= "\n" . $lines[$j];
                    }
                    if (\stripos($section, 'breaking') !== false || \stripos($bullet, 'breaking') !== false) {
                        $breaking[$version][] = $bullet;
                    }
                }
            }
            if ($breaking) {
                $b = "What changed incompatibly, release by release, collected from the [changelog](/$project/changelog/): entries marked breaking or listed under a Breaking heading. Read this before upgrading across the releases listed.\n\n";
                foreach ($breaking as $v => $list) {
                    $b .= "## $v\n\n" . \implode("\n", $list) . "\n\n";
                }
                $out['pages'][] = ['slug' => 'upgrading', 'title' => 'Upgrading and breaking changes', 'html' => Markdown::html($b)];
            }
        }
        // Planned changes come from a curated page in this repo, never from a package's working notes
        // (docs/roadmap-*.md are internal notes and must not ship: see CONTENT-GUIDE.md).
        $planned = __DIR__ . "/../content/$project/changelog/planned.md";
        if (\is_file($planned)) {
            $out['pages'][] = ['slug' => 'planned', 'title' => 'Planned changes', 'html' => \preg_replace('~^<h1[^>]*>.*?</h1>~s', '', Markdown::html(\file_get_contents($planned)))];
        }
        $proc = '';
        foreach (['CONTRIBUTING.md', 'SECURITY.md'] as $f) {
            null !== ($c = $read($f)) && $proc .= $demote(Markdown::html($c));
        }
        '' !== $proc && $out['pages'][] = ['slug' => 'process', 'title' => 'Development process', 'html' => $proc];

        return $out;
    }

    /**
     * The sidebar of each sub-section other than the Manual (whose tree depends on the page): {title, ordered, items[]}.
     * An item is {title, url, code?}, {label} (a heading), or {jump: [{title, url}]} (a row of links). The urls are final.
     */
    private function sidebars(string $p, array $getStarted, array $index, array $changelog): array
    {
        $path = ['title' => 'Get started', 'ordered' => true, 'items' => \array_map(static fn ($g) => ['title' => $g['title'], 'url' => "/$p" . $g['url']], $getStarted)];
        $views = [['All symbols', "/$p/symbols/", fn () => true], ['Classes', "/$p/symbols/classes/", fn ($s) => 'function' !== $s['kind']], ['Functions', "/$p/symbols/functions/", fn ($s) => 'function' === $s['kind']]];
        $views = \array_values(\array_filter($views, static fn ($v) => \array_filter($index['symbols'], $v[2]))); // a view with no symbols (Swerve has no functions) is not offered
        $sidebars = ['get-started' => $path];
        foreach ($views as [, $viewUrl, $keep]) {
            $letters = [];
            foreach (\array_filter($index['symbols'], $keep) as $s) {
                if (!\ctype_alpha($s['name'][0])) {
                    continue; // no anchor for a section headed `_`
                }
                $letters[\strtoupper($s['name'][0])] = ['title' => \strtoupper($s['name'][0]), 'url' => $viewUrl . '#' . \strtolower($s['name'][0])];
            }
            \ksort($letters);
            $items = [];
            foreach ($views as [$t, $u]) {
                $items[] = ['title' => $t, 'url' => $u];
            }
            $items[] = ['title' => 'Examples', 'url' => "/$p/examples/"];
            $items[] = ['label' => 'A to Z'];
            $items[] = ['jump' => \array_values($letters)];
            $sidebars[\preg_replace('~^/\w+/~', '', \rtrim($viewUrl, '/'))] = ['title' => 'Index', 'ordered' => false, 'items' => $items];
        }
        $items = [];
        if ($changelog['releases']) {
            $items[] = ['label' => 'Releases'];
            foreach ($changelog['releases'] as $r) {
                $items[] = ['title' => $r['title'], 'url' => "/$p/changelog/#{$r['id']}"];
            }
            $items[] = ['label' => 'More'];
        }
        foreach ($changelog['pages'] as $pg) {
            $items[] = ['title' => $pg['title'], 'url' => "/$p/changelog/{$pg['slug']}/"];
        }
        $sidebars['changelog'] = ['title' => 'Changelog', 'ordered' => false, 'items' => $items];

        return $sidebars;
    }

    private function assemble(array $topics, array $examples, array $see, array $used): array
    {
        $all   = $this->symbols->all;
        $model = ['projects' => [], 'pages' => [], 'symbols' => []];
        $getStarted = [];
        foreach ($this->pages as $p) {
            if (\str_starts_with($p['url'], '/get-started/') && '/get-started/' !== $p['url']) {
                $getStarted[] = ['title' => $p['title'], 'url' => $p['url'], 'summary' => $p['description'], 'order' => (int) ($p['front']['order'] ?? 50)];
            }
        }
        \usort($getStarted, static fn ($a, $b) => $a['order'] <=> $b['order']); // stable: ties keep page order
        $getStarted = \array_map(static fn ($g) => \array_diff_key($g, ['order' => 1]), $getStarted);
        foreach (self::PROJECTS as $project => $title) {
            $manual = [];
            foreach ($topics[$project] ?? [] as $slug => $t) {
                $t += ['concepts' => [], 'guides' => [], 'symbols' => [], 'examples' => []];
                foreach ($this->pages as $p) {
                    if (($p['front']['project'] ?? null) === $project && ($p['front']['topic'] ?? null) === $slug) {
                        $e = ['title' => $p['title'], 'url' => $p['url'], 'summary' => $p['description']];
                        'concept' === ($p['front']['role'] ?? 'guide') ? $t['concepts'][] = $e : $t['guides'][] = $e;
                    }
                }
                foreach ($all as $full => $s) {
                    if ('topic' === $s['kind'] || ($s['project'] ?? null) !== $project || $s['topic'] !== $slug) {
                        continue;
                    }
                    $isMember = 'method' === $s['kind'] && !\in_array($s['class'], self::FACADES, true);
                    if ($isMember) {
                        continue; // listed under its class
                    }
                    $entry = $this->item($s);
                    if ('method' !== $s['kind'] && isset($s['methods'])) {
                        $facade = \in_array($full, self::FACADES, true);
                        $entry['members'] = [];
                        foreach ($s['methods'] as $m) {
                            if (!$facade || $all[$m]['topic'] === $slug) {
                                $entry['members'][] = $this->item($all[$m]);
                            }
                        }
                    }
                    $t['symbols'][] = $entry;
                    foreach ($examples[$full] ?? [] as $ex) {
                        $t['examples'][] = ['symbol' => $s['name'], 'symbolUrl' => $s['url']] + $ex;
                    }
                    if (isset($entry['members'])) {
                        foreach ($entry['members'] as $m) {
                            foreach ($examples[$m['full']] ?? [] as $ex) {
                                $t['examples'][] = ['symbol' => $m['name'], 'symbolUrl' => $m['url']] + $ex;
                            }
                        }
                    }
                }
                $manual[] = $t;
            }
            // The index: every symbol A to Z, members separately
            $index = ['symbols' => [], 'members' => []];
            foreach ($all as $s) {
                if (($s['project'] ?? null) === $project && 'topic' !== $s['kind']) {
                    $index['method' === $s['kind'] ? 'members' : 'symbols'][] = $this->item($s);
                }
            }
            foreach ($index as &$list) {
                \usort($list, static fn ($a, $b) => \strcasecmp($a['name'], $b['name']));
            }
            unset($list);
            $model['projects'][$project] = ['title' => $title, 'url' => "/topics/$project/", 'getStarted' => $getStarted, 'manual' => $manual, 'index' => $index,
                'changelog' => $this->changelog($project), 'sidebars' => []];
            $model['projects'][$project]['sidebars'] = $this->sidebars($project, $getStarted, $index, $model['projects'][$project]['changelog']);
        }

        // Pages: breadcrumbs, previous/next within a topic, See also
        $home = ['title' => 'Home', 'url' => '/'];
        foreach ($model['projects'] as $project => $pr) {
            $base = [$home, ['title' => $pr['title'], 'url' => $pr['url']]];
            foreach ($pr['manual'] as $t) {
                $crumbs = [...$base, ['title' => $t['title'], 'url' => $t['url']]];
                $seq    = [['title' => $t['title'], 'url' => $t['url']], ...\array_map(static fn ($e) => ['title' => $e['title'], 'url' => $e['url']], [...$t['concepts'], ...$t['guides']])];
                foreach ($seq as $i => $step) {
                    $model['pages'][$step['url']] = ['title' => $step['title'], 'breadcrumbs' => 0 === $i ? $base : $crumbs, 'prev' => $seq[$i - 1] ?? null, 'next' => $seq[$i + 1] ?? null, 'project' => $project, 'topic' => $t['slug']];
                }
                $model['pages'][$t['url']]['breadcrumbs'] = $base;
                foreach ($t['symbols'] as $e) {
                    $model['symbols'][$e['full']]['breadcrumbs'] = [...$crumbs];
                    foreach ($e['members'] ?? [] as $m) {
                        $model['symbols'][$m['full']]['breadcrumbs'] = [...$crumbs, ['title' => $e['name'], 'url' => $e['url']]];
                    }
                }
            }
        }
        foreach ($this->pages as $url => $p) {
            if (!isset($model['pages'][$url]) && null === $p['symbol']) {
                $dir = '/' . (\explode('/', \trim($url, '/'))[0] ?? '') . '/';
                $model['pages'][$url] = ['title' => $p['title'], 'breadcrumbs' => '/' === $url ? [] : [$home, ...(isset($this->pages[$dir]) && $dir !== $url ? [['title' => $this->pages[$dir]['title'], 'url' => $dir]] : [])], 'prev' => null, 'next' => null, 'project' => null, 'topic' => null];
            }
            if (null === $p['symbol']) {
                $model['pages'][$url]['see_also'] = \array_map(static fn ($e) => ['name' => $e['name'], 'url' => $e['url'], 'desc' => $e['desc'], 'source' => '', 'code' => $e['code']], $p['see'] ?? []);
            }
        }
        foreach ($all as $full => $s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            $d = $s['doc'];
            $model['symbols'][$full] ??= ['breadcrumbs' => [$home]];
            $model['symbols'][$full] += [
                'name' => $s['name'], 'kind' => $s['kind'], 'url' => $s['url'], 'package' => $s['package'], 'project' => $s['project'],
                'summary' => $s['plain'], 'topic' => $s['topic'], 'topicSource' => $s['topicSource'],
                'since' => $d['since'], 'deprecated' => $d['deprecated'], 'changed' => $d['changed'], 'notes' => $d['notes'], 'warnings' => $d['warnings'],
                'examples' => $examples[$full] ?? [], 'see_also' => $see[$full] ?? [], 'used_in' => $used[$full] ?? [],
            ];
        }

        return $model;
    }

    /** The number of See also entries, for the lint summary. */
    public function seeAlsoCount(): int
    {
        return \array_sum(\array_map(static fn ($s) => \count($s['see_also']), $this->data['symbols'])) + \array_sum(\array_map(static fn ($p) => \count($p['see_also'] ?? []), $this->data['pages']));
    }

    /** Numbers for the lint summary. */
    public function stats(): array
    {
        $st = ['responsible' => 0, 'source' => 0, 'map' => 0, 'none' => 0, 'examples' => 0, 'passed' => 0, 'failed' => 0, 'seeAlso' => $this->seeAlsoCount()];
        foreach ($this->symbols->all as $s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            if ('method' !== $s['kind'] || \in_array($s['class'], self::FACADES, true)) {
                $st['responsible']++;
                $st[match ($s['topicSource']) { null => 'none', 'override' => 'map', default => $s['topicSource'] }]++;
            }
        }
        foreach ($this->data['symbols'] as $s) {
            foreach ($s['examples'] as $e) {
                if ('file' === $e['kind']) {
                    $st['examples']++;
                    $st[$e['ok'] ? 'passed' : 'failed'] += null === $e['ok'] ? 0 : 1;
                }
            }
        }

        return $st;
    }
}
