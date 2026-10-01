<?php
/** @var array $page */
$crumbs = [['Home', '/']];
if ($s = $page['symbol'] ?? null) {
    $crumbs[] = ['Reference', '/reference/'];
    if ('method' === $s['kind']) {
        $class    = \explode('::', $s['name'])[0];
        $crumbs[] = [$class, "/$class/"];
    }
} else {
    $parts = \explode('/', \trim($page['url'], '/'));
    \array_pop($parts);
    $path = '';
    foreach ($parts as $p) {
        $path    .= "/$p";
        $crumbs[] = [\ucfirst(\str_replace('-', ' ', $p)), "$path/"];
    }
}
?>
<nav class="crumbs" aria-label="Breadcrumb"><ol>
<?php foreach ($crumbs as [$label, $url]): ?>
  <li><a href="<?= $url ?>"><?= e($label) ?></a></li>
<?php endforeach ?>
  <li aria-current="page"><?= e($page['symbol'] ? \preg_replace('~^.*::~', '', $page['title']) : $page['title']) ?></li>
</ol></nav>
