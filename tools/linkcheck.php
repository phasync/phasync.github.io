<?php
// php tools/linkcheck.php [public]: every internal href/src must resolve to a file in the output.
$out = \rtrim($argv[1] ?? __DIR__ . '/../public', '/');
$elsewhere = ['/', '/docs/', '/pricing/']; // managed outside this build
$bad = []; $n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $f) {
    if ('html' !== $f->getExtension()) { continue; }
    $html = \file_get_contents($f->getPathname()); $page = \substr($f->getPathname(), \strlen($out));
    \preg_match_all('~\b(?:href|src)="([^"]*)"~', $html, $m);
    foreach (\array_unique($m[1]) as $l) {
        if ('' === $l || \preg_match('~^(?:https?:|mailto:|#|data:)~', $l)) { continue; }
        $n++;
        $path = \rawurldecode(\html_entity_decode(\preg_replace('~[?#].*$~', '', $l)));
        if (\in_array($path, $elsewhere, true) || \str_starts_with($path, '/legal/')) { continue; }
        if (!\str_starts_with($path, '/')) { $bad[] = "$page: relative '$l'"; continue; }
        if (!(\str_ends_with($path, '/') ? \is_file("$out{$path}index.html") : (\is_file($out . $path) || \is_file("$out$path/index.html")))) { $bad[$l][] = $page; }
    }
}
echo "$n links checked, ", \count($bad), " broken\n";
foreach ($bad as $l => $pages) { echo "  $l  (", \is_array($pages) ? \count($pages) . ' pages, e.g. ' . $pages[0] : $pages, ")\n"; }
exit($bad ? 1 : 0);
