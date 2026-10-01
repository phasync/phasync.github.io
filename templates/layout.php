<?php
/** @var array $page @var string $main @var array $nav */
$section = match (true) {
    $page['symbol'] ?? null => '/reference/',
    default => '/' . \explode('/', \trim($page['url'], '/'))[0] . '/',
};
$title = '/' === $page['url'] ? 'phasync and Swerve: run your PHP application on Swerve' : $page['title'] . ' - phasync';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($page['description']) ?>">
<?php if ('notfound' !== $page['kind']): ?><link rel="canonical" href="<?= e($siteUrl . $page['url']) ?>">
<?php endif ?>
<script>try{var t=new URLSearchParams(location.search).get('theme')||localStorage.theme;if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
<link rel="stylesheet" href="/theme/phasync.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%23031a30'/%3E%3Cpath d='M5 21c4-10 9-10 11-5s7 5 11-5' fill='none' stroke='%237fb0e0' stroke-width='3'/%3E%3C/svg%3E">
<script src="/theme/phasync.js" defer></script>
<script src="/theme/search.js" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container">
    <!-- LOGO SLOT: a text wordmark until the logo is redone; replace the contents of this link -->
    <a class="wordmark" href="/" aria-label="phasync, home"><span class="wm-name">phasync</span><span class="wm-sub">Swerve</span></a>
    <nav class="site-nav" aria-label="Main">
<?php foreach ($nav as $url => $label): ?>
      <a href="<?= $url ?>"<?= $url === $section ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
    </nav>
    <form class="search" role="search" action="/404.html" hidden>
      <input type="search" name="q" placeholder="Swerve::publish" aria-label="Search the reference" autocomplete="off" spellcheck="false">
      <ul class="suggest" role="listbox" hidden></ul>
    </form>
    <button class="theme-toggle" type="button" aria-label="Switch between light and dark" title="Light / dark"></button>
  </div>
</header>
<main id="main" class="container">
<?= $main ?>
</main>
<footer class="site-footer">
  <div class="container">
    <p>
      <a href="https://github.com/phasync/phasync">phasync/phasync</a>
      <a href="https://github.com/phasync/swerve">phasync/swerve</a>
      <a href="https://github.com/phasync/phasync-ext">phasync/phasync-ext</a>
      <a href="https://github.com/phasync/http-client">phasync/http-client</a>
      <a href="https://github.com/phasync/tether">phasync/tether</a>
    </p>
    <p><a href="/advanced/">Advanced</a> <a href="/reference/">Reference</a> <span>MIT licensed</span></p>
  </div>
</footer>
</body>
</html>
