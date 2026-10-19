<?php
/** The kit's page shell. Vars: project title desc bodyHtml crumbs sub topic cur class prev next sidebar url h2 r */
$P = Render::PROJECTS;
$subs = ['get-started' => ['Get started', $r->startUrl($project)], 'manual' => ['Manual', "/$project/manual/"], 'symbols' => ['Index', "/$project/symbols/"], 'changelog' => ['Changelog', "/$project/changelog/"]];
$mid = $sidebar ? $r->sidebar($project, $topic, $cur, $class, $tree ?? null) : '';
$short = \implode(' / ', \array_map(fn ($c) => $c[0], \array_slice($crumbs, -2)));
$pager = static fn ($x, $c, $l) => $x ? '<a class="' . $c . '" href="' . e($x['url']) . '"><small>' . $l . '</small>' . e($x['title']) . '</a>' : '';
?>
<!doctype html>
<html lang="en" data-section="<?= e($project) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e($P[$project]) ?></title><meta name="description" content="<?= e($desc) ?>">
<?php if ('/404.html' !== $url): ?><link rel="canonical" href="<?= e($site . $url) ?>"><?php endif ?>
<link rel="icon" href="/assets/favicon.png"><link rel="stylesheet" href="/assets/site.css">
<script type="module" src="/assets/phasync-ribbon.js"></script><script type="module" src="/assets/site.js"></script></head>
<body><a class="skip" href="#main">Skip to content</a>
<header class="topbar" id="topbar"><div class="wrap">
<a class="brand" href="/"><phasync-ribbon data-follow interactive="false"></phasync-ribbon>phasync</a>
<button class="iconbtn menubtn" type="button" data-toggle="#topbar" aria-expanded="false" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
<nav aria-label="Main"><ul class="switcher">
<?php foreach ($P as $k => $n): ?><li><a href="/<?= $k ?>/" data-p="<?= $k ?>"<?= $k === $project ? ' aria-current="page"' : '' ?>><span class="dot"></span><?= e($n) ?></a></li>
<?php endforeach ?></ul>
<div class="toplinks"><a href="/docs/">Docs</a><a href="/pricing/">Pricing</a><a href="https://github.com/phasync" class="ext">GitHub</a>
<button class="searchbtn" type="button" data-search aria-label="Search (shortcut /)"><span>Search</span><kbd>/</kbd></button>
<button class="iconbtn" type="button" data-theme-toggle aria-label="Toggle light and dark theme"><svg class="theme-icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/></svg><svg class="theme-icon-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg></button></div>
</nav></div></header>
<nav class="subnav" aria-label="Section"><div class="wrap"><ul>
<?php foreach ($subs as $k => [$t, $u]): ?><li><a href="<?= $u ?>"<?= $k === $sub ? ' aria-current="page"' : '' ?>><?= $t ?></a></li><?php endforeach ?></ul></div></nav>
<?php if ($mid): ?><div class="docbar"><div class="wrap"><button class="btn btn-secondary btn-sm" type="button" data-toggle="#sidebar" aria-expanded="false" aria-controls="sidebar">Menu</button><span><?= e($short) ?></span></div></div><?php endif ?>
<main id="main" class="wrap<?= $mid ? ' docs' : '' ?>">
<?= $mid ?>
<?php if ($mid): ?><div class="scrim"></div><?php endif ?>
<article class="prose">
<?= $r->crumbs($crumbs) ?>
<?= $bodyHtml ?>
<?php if ($prev || $next): ?><nav class="pager" aria-label="Pager"><?= $pager($prev, 'prev', 'Previous') ?><?= $pager($next, 'next', 'Next') ?></nav><?php endif ?>
</article>
<?php if ($mid && $rail && \count($h2[1]) > 1): ?><nav class="rail" aria-label="On this page"><h2>On this page</h2><ul><?php foreach ($h2[1] as $i => $id): ?><li><a href="#<?= $id ?>"><?= \strip_tags($h2[2][$i]) ?></a></li><?php endforeach ?></ul></nav><?php endif ?>
</main>
<footer class="footer"><div class="wrap"><div class="cols">
<div><h2>Projects</h2><ul><?php foreach ($P as $k => $n): ?><li><a href="/<?= $k ?>/"><?= e($n) ?></a></li><?php endforeach ?></ul></div>
<div><h2>Documentation</h2><ul><li><a href="<?= $r->startUrl($project) ?>">Get started</a></li><li><a href="/<?= $project ?>/manual/">Manual</a></li><li><a href="/<?= $project ?>/symbols/">Index</a></li><li><a href="/<?= $project ?>/changelog/">Changelog</a></li></ul></div>
<div><h2>Company</h2><ul><li>Ennerd AS, Norway</li><li><a href="/pricing/">Pricing</a></li><li><a href="https://github.com/phasync" class="ext">GitHub</a></li></ul></div>
<div><h2>Legal</h2><ul><li><a href="/legal/privacy/">Privacy</a></li></ul></div>
</div><div class="fine"><phasync-ribbon data-follow interactive="false"></phasync-ribbon><p>phasync, Swerve, Tether and the extension are MIT.</p></div></div></footer>
<search-dialog src="/search.json"></search-dialog>
</body>
</html>
