<?php

/**
 * The site generator: `php build.php` writes the site to public/.
 *
 * content/**.md (front matter + markdown)   the pages
 * symbols.php + the installed packages       the reference, by reflection (lib/Symbols.php)
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
const NAV = ['/get-started/' => 'Get started', '/frameworks/' => 'Frameworks', '/guides/' => 'Guides', '/library/' => 'Library', '/performance/' => 'Performance', '/reference/' => 'Reference'];

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
    return \preg_replace('~^<p>(.*)</p>\s*$~s', '$1', Markdown::html($text));
}

function main(string $siteUrl): void
{
    $start   = \microtime(true);
    $classes = require __DIR__ . '/symbols.php';
    $symbols = new Symbols($classes);

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
        if (!empty($front['symbol'])) { // a page about something that is not a class we can reflect
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

        if ('reference' === ($dir[0] ?? null)) {
            if (1 === \count($dir) && 'index' === $leaf) {
                $kind = 'section';
            } elseif (2 === \count($dir) && 'index' === $leaf) {
                $symbol = !empty($front['symbol']) ? $symbols->find($front['symbol']) : $symbols->find($dir[1]);
                $symbol ?? throw new BuildFailure("$src is about $dir[1], which is not a documented class (see symbols.php)");
            } elseif (2 === \count($dir)) {
                $symbol = $symbols->find("$dir[1]::$leaf");
                $symbol ?? throw new BuildFailure("$src is about $dir[1]::$leaf, which is not a public method of $dir[1]");
            } else {
                throw new BuildFailure("$src: the reference has only Class/ and Class/method pages");
            }
            if ($symbol) {
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
            'description' => $front['description'] ?? ($symbol['desc'] ?? throw new BuildFailure("$src: front matter needs a description")),
        ];
        if (!\in_array($page['status'], STATUSES, true)) {
            throw new BuildFailure("$src: status must be one of " . \implode(', ', STATUSES) . ", not '{$page['status']}'");
        }
        $claim($url, $src);
        $pages[$url] = $page;
    }

    // 2. Every public symbol gets a page, from its docblock alone when nobody wrote one
    foreach ($symbols->all as $symbol) {
        if (!isset($pages[$symbol['url']])) {
            $claim($symbol['url'], 'the reflected ' . $symbol['full']);
            $pages[$symbol['url']] = [
                'url' => $symbol['url'], 'source' => null, 'kind' => $symbol['kind'], 'symbol' => $symbol, 'html' => '',
                'status' => 'draft', 'see_also' => [], 'order' => 50, 'template' => null,
                'title' => $symbol['name'], 'description' => $symbol['desc'],
            ];
        }
    }
    \uasort($pages, static fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);

    // 3. See-also targets, and [[Symbol]] links in the text
    foreach ($pages as &$page) {
        $page['see'] = [];
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

    // 4. Write
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
            $examples = (bool) \preg_match('~id="examples"~', $page['html']);
            $withoutExamples += $examples || 'topic' === $page['kind'] ? 0 : 1;
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

    // Redirect stubs: /Swerve::publish -> /Swerve/publish/, a directory because GitHub Pages cannot redirect
    $index = [];
    foreach ($symbols->all as $symbol) {
        $index[] = ['n' => $symbol['name'], 'd' => $symbol['desc'], 'u' => $symbol['url'], 'k' => $symbol['kind']];
        if ('method' === $symbol['kind']) {
            $stub = "/{$symbol['name']}/";
            $claim($stub, "the redirect for {$symbol['name']}");
            $target = $siteUrl . $symbol['url'];
            $write($stub . 'index.html', '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . e($symbol['name']) . '</title>'
                . '<link rel="canonical" href="' . e($target) . '"><meta http-equiv="refresh" content="0; url=' . e($symbol['url']) . '">'
                . '<script>location.replace(' . \json_encode($symbol['url']) . ')</script><a href="' . e($symbol['url']) . '">' . e($symbol['name']) . '</a>');
        }
    }
    foreach ($pages as $page) {
        if (!$page['symbol'] && 'landing' !== $page['kind']) {
            $index[] = ['n' => $page['title'], 'd' => $page['description'], 'u' => $page['url'], 'k' => 'page'];
        }
    }
    $write('/search.json', \json_encode($index, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    $write('/404.html', render('layout', ['page' => ['url' => '/404.html', 'title' => 'Not found', 'description' => 'No such page.', 'kind' => 'notfound', 'status' => 'stable'], 'main' => render('notfound', []), 'nav' => $nav, 'siteUrl' => $siteUrl, 'symbols' => $symbols]));
    $write('/.nojekyll', '');
    \exec('cp -r ' . \escapeshellarg(__DIR__ . '/theme') . ' ' . \escapeshellarg($out . '/theme'));

    // 5. Links: every root-relative link must land on a file we wrote; relative links are not allowed
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
    if (\in_array('--todo', $GLOBALS['argv'], true)) {
        $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)), '~/(content|templates)/.*\.(md|php)$~');
        foreach ($files as $file) {
            foreach (\file($file->getPathname()) as $n => $line) {
                if (\str_contains($line, 'TODO')) {
                    \printf("TODO %s:%d\n", \substr($file->getPathname(), \strlen(__DIR__) + 1), $n + 1);
                }
            }
        }
    }
}

try {
    main($siteUrl);
} catch (BuildFailure $e) {
    \fwrite(\STDERR, "BUILD FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
