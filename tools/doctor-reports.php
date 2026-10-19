<?php
// php tools/doctor-reports.php [framework ...]: for each framework (default: all below), create a
// fresh skeleton app in a scratch dir, install its Swerve adapter and phasync/doctor (released
// versions, from Packagist), run the doctor, and rewrite the "doctor-report" block of that
// framework's content/frameworks/<page>.md. Never touches vendor/ or the repo itself for the apps.
//
// Scratch dir: $DOCTOR_REPORTS_SCRATCH, or a doctor-reports/ folder under the system temp dir.
// GITHUB_TOKEN: read from `gh auth token` when not already set, to avoid Packagist rate limits.

$root = \dirname(__DIR__);
$scratch = \getenv('DOCTOR_REPORTS_SCRATCH') ?: \sys_get_temp_dir() . '/doctor-reports';
if (\str_starts_with(\realpath($root) . '/', \rtrim($scratch, '/') . '/')) {
    fwrite(STDERR, "Refusing a scratch dir that contains the repo: $scratch\n");
    exit(2);
}
@\mkdir($scratch, 0777, true);

if ('' === (string) \getenv('GITHUB_TOKEN')) {
    $token = \trim((string) @\shell_exec('gh auth token 2>/dev/null'));
    if ('' !== $token) {
        \putenv("GITHUB_TOKEN=$token");
    }
}

// php8.4 without grpc: the extension can hang composer/doctor; laminas-mvc needs php8.4 anyway.
$php84ConfD = "$scratch/php84-conf.d";
if (!\is_dir($php84ConfD)) {
    \mkdir($php84ConfD, 0777, true);
    foreach (\glob('/etc/php/8.4/cli/conf.d/*.ini') ?: [] as $ini) {
        if ('30-grpc.ini' !== \basename($ini)) {
            @\symlink($ini, "$php84ConfD/" . \basename($ini));
        }
    }
}

/** Runs a shell command, returns [exit code, combined stdout, stderr]. $env overrides are set with
 *  putenv() (and restored after) so the child inherits the rest of this process's environment. */
function run(string $cmd, string $cwd, array $env = []): array
{
    $previous = [];
    foreach ($env as $k => $v) {
        $previous[$k] = \getenv($k);
        \putenv("$k=$v");
    }
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = \proc_open($cmd, $descriptors, $pipes, $cwd, null);
    $out = \stream_get_contents($pipes[1]);
    $err = \stream_get_contents($pipes[2]);
    foreach ($pipes as $p) {
        \fclose($p);
    }
    $code = \proc_close($proc);
    foreach ($previous as $k => $v) {
        false === $v ? \putenv($k) : \putenv("$k=$v");
    }

    return [$code, $out, $err];
}

/** Runs $cmd, prints it, and exits the whole script if it fails. */
function must(string $cmd, string $cwd, array $env = []): string
{
    echo "    \$ $cmd\n";
    [$code, $out, $err] = run($cmd, $cwd, $env);
    if (0 !== $code) {
        fwrite(STDERR, "$out\n$err\nexit $code\n");
        exit(1);
    }

    return $out . $err;
}

// Each entry: the page in content/frameworks/, the skeleton to create-project, the released
// Swerve adapter package, and the php binary to build and scan it with (laminas needs 8.4).
$frameworks = [
    'laravel' => [
        'title' => 'Laravel',
        'skeleton' => 'laravel/laravel',
        'create' => 'laravel/laravel',
        'adapter' => 'phasync/swerve-laravel',
        'php' => 'php',
    ],
    'symfony' => [
        'title' => 'Symfony',
        'skeleton' => 'symfony/skeleton',
        // symfony/skeleton's own extra.symfony.require locks every symfony/* package to the
        // skeleton's version; swerve-symfony supports 6.4 and 7.4, not the newest major.
        'create' => 'symfony/skeleton:7.4.*',
        'adapter' => 'phasync/swerve-symfony',
        'php' => 'php',
    ],
    'cakephp' => [
        'title' => 'CakePHP',
        'skeleton' => 'cakephp/app',
        'create' => 'cakephp/app:5.*',
        'adapter' => 'phasync/swerve-cakephp',
        'php' => 'php',
    ],
    'codeigniter' => [
        'title' => 'CodeIgniter',
        'skeleton' => 'codeigniter4/appstarter',
        'create' => 'codeigniter4/appstarter',
        'adapter' => 'phasync/swerve-codeigniter',
        'php' => 'php',
    ],
    'yii' => [
        'title' => 'Yii',
        'skeleton' => 'yiisoft/app',
        'create' => 'yiisoft/app:^1.4',
        'adapter' => 'phasync/swerve-yii',
        'requireFlags' => '-W', // yiisoft/di versions pulled in by the skeleton are otherwise locked
        'php' => 'php',
    ],
    'psr-15' => [
        'title' => 'PSR-15',
        'skeleton' => 'slim/slim-skeleton',
        'create' => 'slim/slim-skeleton',
        'adapter' => 'phasync/swerve-psr15',
        'php' => 'php',
    ],
    'laminas' => [
        'title' => 'Laminas',
        'skeleton' => 'laminas/laminas-mvc-skeleton',
        'create' => 'laminas/laminas-mvc-skeleton',
        'createFlags' => '--no-install --no-scripts --ignore-platform-req=php',
        'adapter' => 'phasync/swerve-laminas',
        'php' => 'php8.4', // the skeleton declares php up to 8.3; laminas-mvc 3.8 supports 8.4
        'patch' => true,
    ],
];

$only = \array_slice($argv, 1);
$selected = $only ? \array_intersect_key($frameworks, \array_flip($only)) : $frameworks;
if ($only && \count($selected) !== \count($only)) {
    fwrite(STDERR, 'Unknown framework(s): ' . \implode(', ', \array_diff($only, \array_keys($frameworks))) . "\n");
    exit(2);
}

foreach ($selected as $slug => $f) {
    echo "== {$f['title']} ==\n";
    $appDir = "$scratch/$slug";
    $php = $f['php'];
    $env = 'php8.4' === $php ? ['PHP_INI_SCAN_DIR' => $php84ConfD] : [];
    $metaFile = "$appDir/.doctor-reports-meta.json";

    if (!\is_file($metaFile)) {
        @\exec('rm -rf ' . \escapeshellarg($appDir));
        $flags = $f['createFlags'] ?? '--no-interaction --no-progress --prefer-dist';
        $out = must("$php \$(command -v composer) create-project $flags " . \escapeshellarg($f['create']) . ' app', $scratch, $env);
        // Keep the directory name "app" the fixtures use, but operate on $slug from here on.
        \rename("$scratch/app", $appDir);
        if (!\preg_match('/Installing ' . \preg_quote($f['skeleton'], '/') . ' \(([^)]+)\)/', $out, $m)) {
            fwrite(STDERR, "Could not read the installed version of {$f['skeleton']} from create-project output\n");
            exit(1);
        }
        \file_put_contents($metaFile, \json_encode(['skeleton_version' => $m[1], 'created' => \date('c')]));
    }
    $meta = \json_decode((string) \file_get_contents($metaFile), true);

    must('composer config minimum-stability stable', $appDir, $env);
    must('composer config prefer-stable true', $appDir, $env);

    if ($f['patch'] ?? false) {
        // laminas-mvc-skeleton: widen php to 8.4, drop the interactive skeleton-installer plugin
        // and its prompts, and the require-dev/autoload-dev this app doesn't need.
        $composerJson = "$appDir/composer.json";
        $c = \json_decode((string) \file_get_contents($composerJson), true);
        $c['require']['php'] = '~8.2.0 || ~8.3.0 || ~8.4.0';
        unset($c['require']['laminas/laminas-skeleton-installer'], $c['require-dev'], $c['autoload-dev'], $c['scripts']['post-create-project-cmd']);
        \file_put_contents($composerJson, \json_encode($c, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }

    $flags = $f['requireFlags'] ?? '';
    must("$php \$(command -v composer) require --no-interaction --no-progress $flags " . \escapeshellarg($f['adapter']), $appDir, $env);
    must("$php \$(command -v composer) require --no-interaction --no-progress --dev phasync/doctor", $appDir, $env);

    [$code, $out, $err] = run("$php vendor/bin/phasync-doctor --format=json", $appDir, $env);
    if (2 === $code || !($doctor = \json_decode($out, true))) {
        fwrite(STDERR, "phasync-doctor crashed on $slug (exit $code)\n$out\n$err\n");
        continue;
    }
    \file_put_contents("$scratch/$slug.json", $out);

    $adapterVersion = $doctor['project']['adapter']['version'] ?? '?';
    $doctorVersion = $doctor['doctor_version'] ?? '?';
    $block = render($f['title'], $f['skeleton'], $meta['skeleton_version'], $f['adapter'], $adapterVersion, $doctorVersion, $doctor);

    $page = "$root/content/frameworks/$slug.md";
    $md = (string) \file_get_contents($page);
    $begin = '<!-- doctor-report:begin -->';
    $end = '<!-- doctor-report:end -->';
    if (\str_contains($md, $begin)) {
        $md = \preg_replace('/' . \preg_quote($begin, '/') . '.*?' . \preg_quote($end, '/') . '/s', "$begin\n$block\n$end", $md);
    } else {
        // Insert right after the "## Your application" section's paragraphs, before the next heading.
        $md = \preg_replace_callback('/^## Your application\n(?:(?!^## ).*\n)*/m', function ($m) use ($begin, $end, $block) {
            return \rtrim($m[0]) . "\n\n$begin\n$block\n$end\n\n";
        }, $md, 1);
    }
    \file_put_contents($page, $md);

    $counts = $doctor['summary']['by_rule'] ?? [];
    echo "   {$f['skeleton']} {$meta['skeleton_version']}, {$f['adapter']} $adapterVersion: ", $counts ? \implode(', ', \array_map(fn ($k, $v) => "$k=$v", \array_keys($counts), $counts)) : 'no findings', "\n";
}

/** Builds the markdown block for one framework's doctor JSON report. */
function render(string $title, string $skeletonPkg, string $skeletonVersion, string $adapterPkg, string $adapterVersion, string $doctorVersion, array $doctor): string
{
    $date = \date('Y-m-d');
    $lines = [];
    $lines[] = "### What phasync/doctor reports on a fresh $title app";
    $lines[] = '';
    $lines[] = "$skeletonPkg $skeletonVersion with $adapterPkg $adapterVersion, scanned by phasync/doctor $doctorVersion on $date.";
    $lines[] = '';
    $lines[] = $doctor['project']['note'] ?? '';
    $lines[] = '';

    $findings = $doctor['findings'] ?? [];
    if (!$findings) {
        $lines[] = "It reports nothing on this skeleton's own code.";
    } else {
        $lines[] = '| Severity | Rule | Symbol | Location | Question |';
        $lines[] = '| --- | --- | --- | --- | --- |';
        foreach ($findings as $f) {
            $q = $f['question'] ?? '';
            $short = '';
            if ('' !== $q) {
                $cut = \strpos($q, '?');
                $short = false !== $cut ? \substr($q, 0, $cut + 1) : $q;
                if (\strlen($short) > 100) {
                    $short = '';
                }
            }
            $symbol = '`' . \str_replace('|', '\\|', $f['symbol']) . '`';
            $loc = "`{$f['file']}:{$f['line']}`";
            $lines[] = "| {$f['severity']} | {$f['rule']} | $symbol | $loc | $short |";
        }
    }
    $lines[] = '';
    $lines[] = 'See [Making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) for the fix for each finding.';

    return \implode("\n", $lines);
}
