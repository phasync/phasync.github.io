<?php

/**
 * The site generator: `php build.php` writes the site to public/.
 *
 * content/**.md (front matter + markdown)   the pages
 * symbols.php + the installed packages       the reference: source docblocks and reflection (lib/Symbols.php)
 * templates/*.php                            plain PHP templates
 * theme/                                     copied as it is
 *
 * Anything inconsistent fails the build: a broken internal link, a see-also that does not
 * exist, two pages with one URL, two classes with one short name, a page about a symbol that
 * is not there.
 */

declare(strict_types=1);

chdir(__DIR__);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/Symbols.php';
require __DIR__ . '/lib/Markdown.php';

final class BuildFailure extends RuntimeException
{
}

const STATUSES = ['placeholder', 'draft', 'stable'];
/** The header: what a newcomer needs. Advanced is linked from the footer and the reference index. */
const NAV = ['/get-started/' => 'Get started', '/frameworks/' => 'Frameworks', '/guides/' => 'Guides', '/learn/' => 'Learn', '/library/' => 'Library', '/performance/' => 'Performance', '/reference/' => 'Reference'];

$siteUrl = \rtrim(\getenv('SITE_URL') ?: 'https://phasync.github.io', '/');

function e(?string $s): string
{
    return \htmlspecialchars((string) $s, \ENT_QUOTES);
}

function render(string $template, array $vars): string
{
    \extract($vars);
    \ob_start();
    require __DIR__ . "/templates/$template.php";

    return \ob_get_clean();
}

/** A code block for templates: the same markup as a fenced block in markdown. */
function code(string $code, string $lang = 'php', bool $run = false): string
{
    return Markdown::code(\trim($code, "\n"), $lang, $run);
}

/** Inline markdown, for a docblock's sentence: no wrapping paragraph. */
function inline(string $text): string
{
    $html = Markdown::html($text);

    return 1 === \substr_count($html, '<p>') ? \preg_replace('~^<p>(.*)</p>\s*$~s', '$1', $html) : $html;
}

/** A redirect page, for the aliases: GitHub Pages cannot redirect, so a directory with a meta refresh. */
function stub(string $title, string $from, string $to, string $siteUrl): string
{
    return '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . e($title) . '</title>'
        . '<link rel="canonical" href="' . e($siteUrl . $to) . '"><meta http-equiv="refresh" content="0; url=' . e($to) . '">'
        . '<script>location.replace(' . \json_encode($to) . ')</script><a href="' . e($to) . '">' . e($title) . '</a>';
}

function main(string $siteUrl, array $argv): void
{
    $start   = \microtime(true);
    $lint    = \in_array('--lint', $argv, true);
    $strict  = \in_array('--strict', $argv, true);
    $symbols = new Symbols(require __DIR__ . '/symbols.php');
    $notices = []; // things the build did on its own, printed at the end

    // An unresolved @see is an error; with --lint it is listed with the other gaps instead
    $unresolved = \array_filter($symbols->problems, static fn ($p) => 'see' === $p[1]);
    if ($unresolved && !$lint) {
        throw new BuildFailure(\count($unresolved) . " @see tag(s) that name no symbol:\n  " . \implode("\n  ", \array_map(static fn ($p) => $p[3], $unresolved)) . "\n(php build.php --lint lists every docblock gap)");
    }

    // 1. The markdown files
    $pages = [];   // url => page
    $files = [];   // url => the file that made it, to name both when two want one URL
    $claim = static function (string $url, string $by) use (&$files): void {
        if (isset($files[$url])) {
            throw new BuildFailure("Duplicate URL $url: $by and {$files[$url]}");
        }
        $files[$url] = $by;
    };
    $parsed = [];
    $it     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/content', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ('md' !== $file->getExtension()) {
            continue;
        }
        $rel = \substr($file->getPathname(), \strlen(__DIR__ . '/content/'), -3);
        [$front, $html] = Markdown::file(\file_get_contents($file->getPathname()));
        $parsed[$rel]   = [$front, $html];
        if (!empty($front['symbol'])) { // a page about something that is not in the source of the packages
            $symbols->addTopic($front['symbol'], (string) ($front['description'] ?? throw new BuildFailure("content/$rel.md: a topic needs a description")));
        }
    }

    foreach ($parsed as $rel => [$front, $html]) {
        $src      = "content/$rel.md";
        $segments = \explode('/', $rel);
        $leaf     = \array_pop($segments);
        $dir      = $segments;                // the directories above the file
        $url      = '/' . \implode('/', 'index' === $leaf ? $dir : [...$dir, $leaf]);
        $url      = '/' === $url ? '/' : $url . '/';
        $symbol   = null;
        $kind     = 'page';

        // content/reference/ is the legacy overlay: hand-written parts for a symbol that the docblock does not cover
        if ('reference' === ($dir[0] ?? null)) {
            if (1 === \count($dir) && 'index' === $leaf) {
                $kind = 'section';
            } elseif (2 === \count($dir) && 'index' === $leaf) {
                $symbol = $symbols->find($front['symbol'] ?? $dir[1]);
                $symbol ?? throw new BuildFailure("$src is about $dir[1], which is not a documented class");
            } elseif (2 === \count($dir)) {
                $symbol = $symbols->find("$dir[1]::$leaf");
                $symbol ?? throw new BuildFailure("$src is about $dir[1]::$leaf, which is not a public method of $dir[1]");
            } else {
                throw new BuildFailure("$src: the reference has only Class/ and Class/method pages");
            }
            if ($symbol) {
                if ('' === $symbol['desc'] && isset($front['description'])) {
                    $symbols->all[$symbol['full']]['desc']  = $front['description'];
                    $symbols->all[$symbol['full']]['plain'] = Symbols::plain($front['description']);
                    $symbol = $symbols->all[$symbol['full']];
                }
                $url  = $symbol['url'];
                $kind = $symbol['kind'];
            }
        } elseif ('index' === $leaf) {
            $kind = '/' === $url ? 'landing' : 'section';
        }

        $page = [
            'url' => $url, 'source' => $src, 'kind' => $kind, 'symbol' => $symbol, 'html' => $html,
            'status' => $front['status'] ?? 'draft', 'see_also' => $front['see_also'] ?? [],
            'order' => (int) ($front['order'] ?? 50), 'template' => $front['template'] ?? null,
            'title' => $symbol['name'] ?? ($front['title'] ?? throw new BuildFailure("$src: front matter needs a title")),
            'description' => $symbol ? ($symbol['plain'] ?: \ucfirst($symbol['kind']) . ' ' . $symbol['name'] . '.') : ($front['description'] ?? throw new BuildFailure("$src: front matter needs a description")),
        ];
        if (!\in_array($page['status'], STATUSES, true)) {
            throw new BuildFailure("$src: status must be one of " . \implode(', ', STATUSES) . ", not '{$page['status']}'");
        }
        $claim($url, $src);
        $pages[$url] = $page;
    }

    // 2. Every public symbol gets a page, from its docblock; a short name two symbols share gets a page that lists them
    foreach ($symbols->all as $symbol) {
        if (($pages[$symbol['url']]['symbol']['full'] ?? null) === $symbol['full']) {
            continue;
        }
        $claim($symbol['url'], 'the reflected ' . $symbol['full']);
        $pages[$symbol['url']] = [
            'url' => $symbol['url'], 'source' => null, 'kind' => $symbol['kind'], 'symbol' => $symbol, 'html' => '',
            'status' => 'stable', 'see_also' => [], 'order' => 50, 'template' => null,
            'title' => $symbol['name'], 'description' => $symbol['plain'] ?: \ucfirst($symbol['kind']) . ' ' . $symbol['name'] . '.',
        ];
    }
    foreach ($symbols->short as $short => $fulls) {
        if (\count($fulls) > 1) {
            $claim("/$short/", "the page that tells the $short symbols apart");
            $list = '';
            foreach ($fulls as $full) {
                $s     = $symbols->all[$full];
                $list .= '<li><a href="' . $s['url'] . '"><code>' . e($full) . '</code></a> ' . inline($s['desc']) . "</li>\n";
            }
            $pages["/$short/"] = [
                'url' => "/$short/", 'source' => null, 'kind' => 'page', 'symbol' => null, 'status' => 'stable', 'see_also' => [], 'order' => 50, 'template' => null,
                'html' => "<ul class=\"index\">\n$list</ul>\n", 'title' => $short, 'description' => "Several symbols are called $short.",
            ];
        }
    }
    \uasort($pages, static fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

    // 3. A hand-written page and a docblock that both give examples, or See also: the docblock wins
    foreach ($pages as &$page) {
        $s = $page['symbol'];
        if (null === $s || !$page['source'] || 'topic' === $s['kind']) {
            continue;
        }
        $dup = [];
        if (($s['examples'] || \str_contains($s['rest'], '```php')) && \str_contains($page['html'], 'id="examples"')) {
            $dup[]        = 'examples (the page body is ignored)';
            $page['html'] = '';
        }
        if ($s['see'] && $page['see_also']) {
            $dup[]            = 'See also (see_also is ignored)';
            $page['see_also'] = [];
        }
        if ($dup) {
            $notices[] = "duplicate source: {$page['source']} and the docblock of {$s['full']} both give " . \implode(' and ', $dup) . '; the docblock wins';
        }
    }
    unset($page);

    // 4. See-also targets (the docblock's @see first), and [[Symbol]] links in the text
    foreach ($pages as &$page) {
        $page['see'] = $page['symbol']['see'] ?? [];
        foreach ($page['see_also'] as $name) {
            $target = \str_starts_with($name, '/') ? ($pages[$name] ?? null) : $symbols->find($name);
            if (null === $target) {
                throw new BuildFailure(($page['source'] ?? $page['url']) . ": see_also '$name' is neither a documented symbol nor a page");
            }
            $page['see'][] = \str_starts_with($name, '/') ? ['name' => $target['title'], 'url' => $name, 'desc' => $target['description'], 'code' => false] : ['name' => $target['name'], 'url' => $target['url'], 'desc' => $target['desc'], 'code' => true];
        }
        $page['html'] = \preg_replace_callback('~(<pre.*?</pre>)|\[\[([\w\\\\]+(?:::\w+)?)\]\]~s', static function ($m) use ($symbols, $page) {
            if (!isset($m[2])) {
                return $m[1];
            }
            $target = $symbols->find($m[2]) ?? throw new BuildFailure(($page['source'] ?? $page['url']) . ": [[{$m[2]}]] is not a documented symbol");

            return '<a href="' . $target['url'] . '"><code>' . e($target['name']) . '</code></a>';
        }, $page['html']);
    }
    unset($page);

    // 5. Write
    $out = __DIR__ . '/public';
    if (\is_dir($out)) {
        \exec('rm -rf ' . \escapeshellarg($out));
    }
    \mkdir($out, 0777, true);
    $nav      = NAV;
    $write    = static function (string $path, string $content) use ($out): void {
        $file = $out . $path;
        @\mkdir(\dirname($file), 0777, true);
        \file_put_contents($file, $content);
    };
    $children = static fn (string $url) => \array_values(\array_filter($pages, static fn ($p) => !$p['symbol'] && $p['url'] !== $url
        && \str_starts_with($p['url'], $url) && 1 === \substr_count(\substr($p['url'], \strlen($url)), '/')));

    $placeholders = $withoutExamples = 0;
    $built        = [];
    foreach ($pages as $page) {
        $isSymbol = null !== $page['symbol'];
        $vars     = ['page' => $page, 'symbols' => $symbols, 'nav' => $nav, 'siteUrl' => $siteUrl];
        if ('landing' === $page['kind']) {
            $main = render('landing', $vars);
        } elseif ($isSymbol) {
            $s        = $page['symbol'];
            $examples = !empty($s['examples']) || \str_contains($s['rest'] ?? '', '```php') || \str_contains($page['html'], 'id="examples"');
            $withoutExamples += $examples || 'topic' === $s['kind'] ? 0 : 1;
            $main = render('reference', $vars + ['hasExamples' => $examples]);
        } elseif ('section' === $page['kind']) {
            $main = render('section', $vars + ['children' => '/reference/' === $page['url'] ? [] : $children($page['url'])]);
        } else {
            $main = render('page', $vars);
        }
        $placeholders += 'placeholder' === $page['status'] ? 1 : 0;
        // Working notes (<!-- TODO-verify: ... -->) stay in the sources and never reach public/; `--todo` lists them
        $main = \preg_replace('~[ \t]*<!--\s*TODO.*?-->\R?~s', '', $main);
        $html = render('layout', $vars + ['main' => $main]);
        $write($page['url'] . 'index.html', $html);
        $built[$page['url']] = $main . $html;
    }

    // Redirects, as directories because GitHub Pages cannot redirect: /Swerve::publish and the namespace path of every symbol
    $index = [];
    foreach ($symbols->all as $s) {
        $index[] = ['n' => $s['name'], 'f' => $s['full'], 'd' => $s['plain'], 'u' => $s['url'], 'k' => $s['kind']];
        if ($s['alias']) {
            if (isset($files[$s['alias']]) && 'function' === $s['kind']) {
                $notices[] = "alias {$s['alias']} of the function {$s['full']} is not written: {$files[$s['alias']]} is there";
            } else {
                $claim($s['alias'], "the alias of {$s['full']}");
                $write($s['alias'] . 'index.html', stub($s['full'], $s['alias'], $s['url'], $siteUrl));
            }
        }
        if ('method' === $s['kind']) {
            $colon = '/' . \str_replace('\\', '/', $s['name']) . '/';
            $claim($colon, "the redirect for {$s['name']}");
            $write($colon . 'index.html', stub($s['name'], $colon, $s['url'], $siteUrl));
        }
    }
    foreach ($pages as $page) {
        if (!$page['symbol'] && 'landing' !== $page['kind']) {
            $index[] = ['n' => $page['title'], 'f' => '', 'd' => $page['description'], 'u' => $page['url'], 'k' => 'page'];
        }
    }
    $write('/search.json', \json_encode($index, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    $write('/404.html', render('layout', ['page' => ['url' => '/404.html', 'title' => 'Not found', 'description' => 'No such page.', 'kind' => 'notfound', 'status' => 'stable'], 'main' => render('notfound', []), 'nav' => $nav, 'siteUrl' => $siteUrl, 'symbols' => $symbols]));
    $write('/.nojekyll', '');
    \exec('cp -r ' . \escapeshellarg(__DIR__ . '/theme') . ' ' . \escapeshellarg($out . '/theme'));

    // 6. Links: every root-relative link must land on a file we wrote; relative links are not allowed
    $broken = [];
    foreach ($built as $url => $html) {
        \preg_match_all('~\b(?:href|src)="([^"]*)"~', $html, $m);
        foreach (\array_unique($m[1]) as $link) {
            if (\preg_match('~^(?:https?:|mailto:|#|data:)~', $link)) {
                continue;
            }
            if (!\str_starts_with($link, '/')) {
                $broken[] = "$url: relative link '$link' (use /root-relative links)";
                continue;
            }
            $path = \rawurldecode(\preg_replace('~[?#].*$~', '', $link));
            if (!(\str_ends_with($path, '/') ? \is_file("$out{$path}index.html") : (\is_file($out . $path) || \is_file("$out$path/index.html")))) {
                $broken[] = "$url: broken link '$link'";
            }
        }
    }
    if ($broken) {
        throw new BuildFailure(\count($broken) . " broken link(s):\n  " . \implode("\n  ", $broken));
    }

    \printf("Built %d pages (%d reference symbols) in %.1f s -> public/\n", \count($pages), \count($symbols->all), \microtime(true) - $start);
    \printf("  placeholder pages:                    %d\n", $placeholders);
    \printf("  reference pages without examples:     %d\n", $withoutExamples);
    foreach ($notices as $notice) {
        \printf("  note: %s\n", $notice);
    }

    // The reference, per package: what it holds, and the gaps in its docblocks
    $gaps = 0;
    foreach (\array_unique(\array_column($symbols->all, 'package')) as $package) {
        $count = ['class' => 0, 'method' => 0, 'function' => 0];
        foreach ($symbols->all as $s) {
            if (($s['package'] ?? null) === $package) {
                $count['method' === $s['kind'] ? 'method' : ('function' === $s['kind'] ? 'function' : 'class')]++;
            }
        }
        $by = [];
        foreach ($symbols->problems as $p) {
            if ($p[0] === $package) {
                $by[$p[1]][] = $p;
            }
        }
        $gaps += \count($symbols->problems) ? \array_sum(\array_map('count', $by)) : 0;
        \printf("  %s: %d classes, %d methods, %d functions; without summary %d, without example %d, @param mismatch %d, @see unresolved %d, @example file missing %d\n",
            $package, $count['class'], $count['method'], $count['function'],
            \count($by['summary'] ?? []), \count($by['example'] ?? []), \count($by['param'] ?? []), \count($by['see'] ?? []), \count($by['example-file'] ?? []));
        if ($lint) {
            foreach ($by as $kind => $list) {
                if ('example' === $kind) {
                    continue; // hundreds of them: the count above, and the symbols below
                }
                \printf("\n%s, %s (%d):\n", $package, $kind, \count($list));
                foreach ($list as $p) {
                    \printf("  %s\n", 'summary' === $kind ? $p[2] : $p[3]);
                }
            }
            \printf("\n%s, no example (%d), classes and functions only:\n  %s\n", $package, \count($by['example'] ?? []),
                \implode(' ', \array_map(static fn ($p) => $p[2], \array_filter($by['example'] ?? [], static fn ($p) => 'method' !== $symbols->all[$p[2]]['kind'])) ));
        }
    }
    if (\in_array('--todo', $argv, true)) {
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)), '~/(content|templates)/.*\.(md|php)$~');
        foreach ($files as $file) {
            foreach (\file($file->getPathname()) as $n => $line) {
                if (\str_contains($line, 'TODO')) {
                    \printf("TODO %s:%d\n", \substr($file->getPathname(), \strlen(__DIR__) + 1), $n + 1);
                }
            }
        }
    }
    if ($strict && $gaps) {
        \fwrite(\STDERR, "--strict: $gaps docblock gap(s)\n");
        exit(1);
    }
}

try {
    main($siteUrl, $argv);
} catch (BuildFailure $e) {
    \fwrite(\STDERR, "BUILD FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
