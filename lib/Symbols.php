<?php

use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Reference\Url;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\DocBlockFactory;

/**
 * The reference, read from the source of the packages: every public, non-@internal class,
 * interface, trait, enum and function, and their public members, with the docblocks parsed
 * (see DOCBLOCK-STANDARD.md). Signatures come from reflection, so they cannot drift.
 *
 * A symbol's canonical URL is its short name (/Swerve/publish/) when that name is unique among
 * the documented classes and functions, and its namespace path (/phasync/Util/WaitGroup/)
 * when it is not; the namespace path is also an alias for every symbol.
 */
final class Symbols
{
    /** @var array<string, array> by full name: `Swerve\Swerve`, `Swerve\Swerve::publish`, `phasync\sleep` */
    public array $all = [];

    /** @var array<string, list<string>> short name => the full names of the classes and functions with it */
    public array $short = [];

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> lint: package, kind, symbol, message */
    public array $problems = [];

    private DocBlockFactory $docs;

    /** @param list<string> $packages composer package names, installed */
    public function __construct(array $packages)
    {
        $this->docs = DocBlockFactory::createInstance();
        foreach ($packages as $package) {
            $root  = \realpath(\Composer\InstalledVersions::getInstallPath($package));
            $auto  = \json_decode(\file_get_contents("$root/composer.json"), true)['autoload'] ?? [];
            $paths = [];
            foreach ($auto['psr-4'] ?? [] as $dirs) {
                \array_push($paths, ...(array) $dirs);
            }
            \array_push($paths, ...($auto['classmap'] ?? []));
            foreach ($this->declarations($root, $paths) as [$kind, $name]) {
                'function' === $kind ? $this->addFunction($package, $root, $name) : $this->addClass($package, $root, $name);
            }
        }
        $this->link();
    }

    /** Add a symbol without a class behind it, for a page written by hand (`symbol:` in the front matter). */
    public function addTopic(string $full, string $desc): void
    {
        $short = \substr(\strrchr('\\' . $full, '\\'), 1);
        if (isset($this->short[$short])) {
            throw new BuildFailure(\sprintf('Short-name collision: %s and %s are both "%s", so both want /%s/', $this->short[$short][0], $full, $short, $short));
        }
        $this->short[$short] = [$full];
        $this->all[$full]    = [
            'full' => $full, 'name' => $short, 'kind' => 'topic', 'url' => "/$short/", 'alias' => null,
            'desc' => $desc, 'plain' => self::plain($desc), 'class' => null, 'see' => [], 'examples' => [],
        ];
    }

    /**
     * `Swerve::publish`, `OrderedChannel`, `phasync\Util\WaitGroup` or `\Swerve\Swerve::publish()` to the
     * symbol, or null. A short name two symbols share is an error: say which one.
     */
    public function find(string $name): ?array
    {
        $name = \preg_replace('/\(\)$/', '', \ltrim($name, '\\'));
        if (isset($this->all[$name])) {
            return $this->all[$name];
        }
        [$class, $member] = \array_pad(\explode('::', $name, 2), 2, null);
        $fulls = $this->short[$class] ?? null;
        if (null !== $fulls && \count($fulls) > 1) {
            throw new BuildFailure("'$class' is ambiguous: " . \implode(' or ', $fulls) . '; use the full name');
        }
        $top = $fulls ? ($this->all[$class] ?? $this->all[$fulls[0]]) : ($this->all[$class] ?? null);

        return null === $top || null === $member ? $top : ($this->all["{$top['full']}::$member"] ?? null);
    }

    // ---------------------------------------------------------------- discovery

    /** @return list<array{0: string, 1: string}> ['class'|'function', full name] for every PHP file under the autoload paths */
    private function declarations(string $root, array $paths): array
    {
        $found = [];
        foreach ($paths as $path) {
            $path  = "$root/" . \trim($path, '/');
            $files = \is_file($path) ? [$path] : \array_keys(\iterator_to_array(new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)), '/\.php$/')));
            foreach ($files as $file) {
                \array_push($found, ...$this->tokenize($file));
            }
        }
        \usort($found, static fn ($a, $b) => \strcmp($a[1], $b[1]));

        return $found;
    }

    /** The classes and functions a file declares at its top level. */
    private function tokenize(string $file): array
    {
        $tokens = \PhpToken::tokenize(\file_get_contents($file));
        $ns     = '';
        $depth  = 0;
        $out    = [];
        $prev   = null;
        for ($i = 0, $n = \count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->isIgnorable()) {
                continue;
            }
            if ($t->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($t->is('}')) {
                $depth--;
            } elseif ($t->is(\T_NAMESPACE) && !$prev?->is(\T_NS_SEPARATOR)) {
                $ns = '';
                for ($j = $i + 1; $j < $n && !$tokens[$j]->is([';', '{']); $j++) {
                    $ns .= $tokens[$j]->isIgnorable() ? '' : $tokens[$j]->text;
                }
                $i = $j - 1;
            } elseif (0 === $depth && $t->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_FUNCTION]) && !$prev?->is([\T_DOUBLE_COLON, \T_NEW])) {
                $name = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!$tokens[$j]->isIgnorable() && !$tokens[$j]->is('&')) {
                        $name = $tokens[$j]->is(\T_STRING) ? $tokens[$j]->text : null;
                        break;
                    }
                }
                if (null !== $name) {
                    $out[] = [$t->is(\T_FUNCTION) ? 'function' : 'class', ('' !== $ns ? "$ns\\" : '') . $name];
                }
            }
            $prev = $t;
        }

        return $out;
    }

    // ---------------------------------------------------------------- reflection

    private function addClass(string $package, string $root, string $name): void
    {
        if (!(\class_exists($name) || \interface_exists($name) || \trait_exists($name) || \enum_exists($name))) {
            return;
        }
        $rc  = new ReflectionClass($name);
        $doc = $this->doc($rc->getDocComment());
        if ($doc['internal']) {
            return;
        }
        $kind = $rc->isInterface() ? 'interface' : ($rc->isEnum() ? 'enum' : ($rc->isTrait() ? 'trait' : ($rc->implementsInterface(Throwable::class) ? 'exception' : 'class')));

        $methods = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            $from = $rm->getDeclaringClass();
            if ($from->getName() !== $rc->getName() && !self::internalClass($from)) {
                continue; // inherited from a documented class: it is on that class's page
            }
            if (null !== ($m = $this->method($package, $root, $rc, $rm))) {
                $methods[] = $m;
            }
        }

        $props = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $rp) {
            $d = $this->doc($rp->getDocComment());
            if ($rp->getDeclaringClass()->getName() === $rc->getName() && !$d['internal']) {
                $props[] = [
                    'name' => $rp->getName(), 'type' => self::type($rp->getType()),
                    'mods' => \trim(($rp->isStatic() ? 'static ' : '') . ($rp->isReadOnly() ? 'readonly' : '')),
                    'desc' => $d['summary'],
                ];
            }
        }
        $consts = [];
        foreach ($rc->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $c) {
            $d = $this->doc($c->getDocComment());
            if ($c->getDeclaringClass()->getName() === $rc->getName() && !$d['internal']) {
                $consts[] = ['name' => $c->getName(), 'value' => self::export($c->getValue()), 'desc' => $d['summary']];
            }
        }

        $this->all[$name] = [
            'full' => $name, 'name' => $name, 'kind' => $kind, 'package' => $package, 'root' => $root, 'class' => null,
            'desc' => $doc['summary'], 'rest' => $doc['rest'], 'sig' => self::classSignature($rc, $kind),
            'methods' => \array_map(static fn ($m) => $m['full'], $methods), 'props' => $props, 'consts' => $consts,
            'doc' => $doc, 'where' => self::where($rc, $root),
        ];
        foreach ($methods as $m) {
            $this->all[$m['full']] = $m;
        }
        $this->short[$rc->getShortName()][] = $name;
    }

    private function addFunction(string $package, string $root, string $name): void
    {
        if (!\function_exists($name)) {
            return;
        }
        $rf  = new ReflectionFunction($name);
        $doc = $this->doc($rf->getDocComment());
        if ($doc['internal']) {
            return;
        }
        $params = self::params($rf, $doc);
        $return = self::type($rf->getReturnType());
        $this->all[$name] = [
            'full' => $name, 'name' => $name, 'kind' => 'function', 'package' => $package, 'root' => $root, 'class' => null,
            'desc' => $doc['summary'], 'rest' => $doc['rest'], 'sig' => self::signature('function', $rf->getShortName(), $params, $return),
            'params' => $params, 'return' => $return, 'returnDesc' => $doc['return'], 'throws' => $doc['throws'],
            'doc' => $doc, 'where' => self::where($rf, $root),
        ];
        $this->short[$rf->getShortName()][] = $name;
    }

    private function method(string $package, string $root, ReflectionClass $rc, ReflectionMethod $rm): ?array
    {
        $doc = $this->doc($rm->getDocComment());
        if ($doc['inherit'] && !$doc['internal']) { // no docblock of its own: the one it implements or overrides
            try {
                for ($p = $rm->getPrototype(); $doc['inherit']; $p = $p->getPrototype()) {
                    $doc = $this->doc($p->getDocComment());
                }
            } catch (ReflectionException) {
            }
        }
        if ($doc['internal']) {
            return null;
        }
        $params = self::params($rm, $doc);
        $return = self::type($rm->getReturnType());
        $head   = \trim('public ' . ($rm->isAbstract() && !$rc->isInterface() ? 'abstract ' : '') . ($rm->isFinal() ? 'final ' : '') . ($rm->isStatic() ? 'static ' : ''));

        return [
            'full' => $rc->getName() . '::' . $rm->getName(), 'kind' => 'method', 'package' => $package, 'root' => $root,
            'class' => $rc->getName(), 'method' => $rm->getName(),
            'desc' => $doc['summary'], 'rest' => $doc['rest'], 'sig' => self::signature($head, $rc->getShortName() . '::' . $rm->getName(), $params, $return),
            'params' => $params, 'return' => $return, 'returnDesc' => $doc['return'], 'throws' => $doc['throws'],
            'doc' => $doc, 'where' => self::where($rm, $root),
        ];
    }

    private static function params(ReflectionFunctionAbstract $rf, array $doc): array
    {
        $params = [];
        foreach ($rf->getParameters() as $p) {
            $params[] = [
                'name' => $p->getName(), 'type' => self::type($p->getType()), 'variadic' => $p->isVariadic(),
                'byRef' => $p->isPassedByReference(),
                'default' => $p->isDefaultValueAvailable() ? self::default($p) : null,
                'desc' => $doc['params'][$p->getName()] ?? '',
            ];
        }

        return $params;
    }

    private static function signature(string $head, string $name, array $params, string $return): string
    {
        $list = \array_map(static fn ($p) => self::paramSignature($p), $params);
        $line = "$head $name(" . \implode(', ', $list) . ')' . ('' !== $return ? ": $return" : '');
        if (\strlen($line) > 90 && $list) {
            $line = "$head $name(\n    " . \implode(",\n    ", $list) . "\n)" . ('' !== $return ? ": $return" : '');
        }

        return $line;
    }

    private static function internalClass(ReflectionClass $rc): bool
    {
        return false !== $rc->getDocComment() && \str_contains($rc->getDocComment(), '@internal');
    }

    /** `phasync/src/Util/WaitGroup.php:42`, to name where a docblock is when the build complains. */
    private static function where(Reflector $r, string $root): string
    {
        return \basename($root) . \substr((string) $r->getFileName(), \strlen($root)) . ':' . $r->getStartLine();
    }

    private static function classSignature(ReflectionClass $rc, string $kind): string
    {
        $parts = [];
        if ($rc->isFinal()) {
            $parts[] = 'final';
        }
        if ($rc->isAbstract() && !$rc->isInterface() && !$rc->isEnum()) {
            $parts[] = 'abstract';
        }
        $parts[] = 'exception' === $kind ? 'class' : $kind;
        $parts[] = $rc->getShortName();
        if (($parent = $rc->getParentClass())) {
            $parts[] = 'extends ' . $parent->getShortName();
        }
        $implements = \array_map(static fn ($i) => '\\' . $i, \array_keys($rc->getInterfaces()));
        if ($rc->isInterface()) {
            $parts[] = $implements ? 'extends ' . \implode(', ', \array_map(self::shorten(...), $implements)) : '';
        } elseif ($implements) {
            $parts[] = 'implements ' . \implode(', ', \array_map(self::shorten(...), $implements));
        }

        return \trim(\implode(' ', \array_filter($parts)));
    }

    private static function paramSignature(array $p): string
    {
        return \trim(('' !== $p['type'] ? $p['type'] . ' ' : '') . ($p['byRef'] ? '&' : '') . ($p['variadic'] ? '...' : '') . '$' . $p['name']
            . (null !== $p['default'] ? ' = ' . $p['default'] : ''));
    }

    private static function type(?ReflectionType $type): string
    {
        return null === $type ? '' : self::shorten((string) $type);
    }

    /** `?Swerve\Subscription` to `?Subscription`: the namespaces are on the page, not in every signature. */
    private static function shorten(string $type): string
    {
        return \preg_replace('/\\\\?(?:\w+\\\\)+(\w+)/', '$1', $type);
    }

    private static function default(ReflectionParameter $p): string
    {
        if ($p->isDefaultValueConstant()) {
            return \ltrim($p->getDefaultValueConstantName(), '\\');
        }

        return self::export($p->getDefaultValue());
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            null === $value            => 'null',
            \is_bool($value)           => $value ? 'true' : 'false',
            \is_array($value)          => [] === $value ? '[]' : \preg_replace('/\s+/', ' ', \var_export($value, true)),
            $value instanceof UnitEnum => self::shorten(\var_export($value, true)),
            \is_object($value)         => 'new ' . self::shorten($value::class),
            default                    => \var_export($value, true),
        };
    }

    // ---------------------------------------------------------------- docblocks

    /**
     * A docblock to its parts. `summary` is the first paragraph and `rest` the rest, both markdown.
     * `inherit` says the docblock has no text of its own (or says `@inheritDoc`).
     *
     * @return array{summary: string, rest: string, params: array<string,string>, paramOrder: list<string>, return: string, throws: list<array{0:string,1:string}>, see: list<array{0:string,1:string,2:string}>, examples: list<array{0:string,1:string}>, since: string, deprecated: ?string, internal: bool, inherit: bool}
     */
    private function doc(string|false $comment): array
    {
        $out = ['summary' => '', 'rest' => '', 'params' => [], 'paramOrder' => [], 'return' => '', 'throws' => [], 'see' => [], 'examples' => [], 'since' => '', 'deprecated' => null, 'internal' => false, 'inherit' => true];
        if (false === $comment) {
            return $out;
        }
        $d = $this->docs->create($comment);

        $out['internal'] = $d->hasTag('internal');

        // The text is read from the comment itself: the library drops `{}` from a description
        $lines = \preg_split('/\R/', \preg_replace(['~^\s*/\*\*[ \t]?~', '~\s*\*/\s*$~'], '', $comment));
        $text  = [];
        foreach ($lines as $line) {
            $line = \preg_replace('~^[ \t]*\*[ ]?~', '', $line);
            if (\preg_match('~^@\w~', $line)) {
                break;
            }
            $text[] = $line;
        }
        [$summary, $rest] = \array_pad(\preg_split('/\n\s*\n/', \trim(\implode("\n", $text)), 2), 2, '');
        $out['summary']   = \trim(\preg_replace('/\s+/', ' ', $summary));
        $out['rest']      = \trim($rest, "\n");
        $out['inherit']  = ('' === $out['summary'] && '' === $out['rest']) || $d->hasTag('inheritdoc');
        foreach ($d->getTags() as $t) {
            if ($t instanceof Param) {
                $out['params'][$t->getVariableName()] = \trim((string) $t->getDescription());
                $out['paramOrder'][]                  = $t->getVariableName();
            } elseif ($t instanceof Return_) {
                $out['return'] = \trim((string) $t->getDescription());
            } elseif ($t instanceof Throws) {
                $out['throws'][] = [\ltrim((string) $t->getType(), '\\'), \trim((string) $t->getDescription())];
            } elseif ($t instanceof See) {
                $ref          = $t->getReference();
                $out['see'][] = [$ref instanceof Url ? (string) $ref : \preg_replace('/\(\)$/', '', \ltrim((string) $ref, '\\')), \trim((string) $t->getDescription()), $ref instanceof Url ? 'url' : 'symbol'];
            } elseif ('example' === $t->getName()) {
                [$path, $caption]  = \array_pad(\preg_split('/\s+/', \trim(\preg_replace('/^@example\s*/', '', $t->render())), 2), 2, '');
                $out['examples'][] = [$path, $caption];
            } elseif ('since' === $t->getName()) {
                $out['since'] = \trim(\preg_replace('/^@since\s*/', '', $t->render()));
            } elseif ('deprecated' === $t->getName()) {
                $out['deprecated'] = \trim(\preg_replace('/^@deprecated\s*/', '', $t->render()));
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- URLs, links, lint

    /** Canonical URLs and aliases, then everything that points at another symbol, and the lint. */
    private function link(): void
    {
        foreach ($this->short as $short => $fulls) {
            foreach ($fulls as $full) {
                $s           = &$this->all[$full];
                $fqn         = self::path($full);
                $s['url']    = 1 === \count($fulls) ? "/$short/" : $fqn;
                $s['alias']  = $s['url'] !== $fqn ? $fqn : null;
                $s['name']   = 1 === \count($fulls) ? $short : $full;
                $s['fqnUrl'] = $fqn;
                unset($s);
            }
        }
        $namespaces = []; // '/phasync/Util/' for phasync\Util\WaitGroup: a method with that URL would clash
        foreach ($this->all as $s) {
            if (!\in_array($s['kind'], ['method', 'topic'], true)) {
                $parts = \explode('\\', $s['full']);
                \array_pop($parts);
                for ($i = 1; $i <= \count($parts); $i++) {
                    $namespaces['/' . \implode('/', \array_slice($parts, 0, $i)) . '/'] = true;
                }
            }
        }
        foreach ($this->all as &$s) {
            if ('method' !== $s['kind']) {
                continue;
            }
            $class           = $this->all[$s['class']];
            $s['name']       = $class['name'] . '::' . $s['method'];
            $s['url']        = $class['url'] . $s['method'] . '/';
            $s['fqnUrl']     = $class['fqnUrl'] . $s['method'] . '/';
            $s['alias']      = $s['url'] !== $s['fqnUrl'] ? $s['fqnUrl'] : null;
            $s['classUrl']   = $class['url'];
            $s['classLabel'] = $class['name'];
            foreach ([$s['url'], $s['fqnUrl']] as $url) {
                if (isset($namespaces[$url])) {
                    throw new BuildFailure("{$s['full']} would be at $url, which is the namespace path of other symbols ({$s['where']}): rename the method, or mark one of them @internal");
                }
            }
        }
        unset($s);

        foreach ($this->all as &$s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            $doc = $s['doc'];
            $at  = $s['where'];
            $ctx = 'function' === $s['kind'] ? null : ($s['class'] ?? $s['full']);
            $own = $s['class'] ?? $s['full'];
            $ns  = \str_contains($own, '\\') ? \substr($own, 0, \strrpos($own, '\\')) : '';

            $inline = function ($m) use ($ctx, $ns) {
                $t = $this->resolve($m[1], $ctx, $ns);

                return isset($t['kind']) ? '[`' . $t['name'] . '`](' . $t['url'] . ')' : '`' . \ltrim($m[1], '\\') . '`';
            };
            foreach (['desc', 'rest'] as $field) {
                $s[$field] = \preg_replace_callback('/\{@(?:see|link)\s+([^}\s]+)[^}]*\}/', $inline, $s[$field]);
            }
            $s['plain'] = self::plain($s['desc']);
            $text       = static fn (string $t) => \preg_replace_callback('/\{@(?:see|link)\s+([^}\s]+)[^}]*\}/', $inline, $t);
            foreach ($s['params'] ?? [] as $i => $p) {
                $s['params'][$i]['desc'] = $text((string) $p['desc']);
            }
            if (isset($s['returnDesc'])) {
                $s['returnDesc'] = $text($s['returnDesc']);
                $s['throws']     = \array_map(static fn ($t) => [$t[0], $text($t[1])], $s['throws']);
            }

            $s['examples'] = [];
            foreach ($doc['examples'] as [$path, $caption]) {
                $file = $s['root'] . '/' . $path;
                if (\is_file($file)) {
                    $s['examples'][] = ['path' => $path, 'caption' => $caption, 'code' => \preg_replace('/^<\?php\s*\n(?:\s*\n)?/', '', \file_get_contents($file))];
                } else {
                    $this->problem($s, 'example-file', "@example $path: no such file in {$s['package']} ($at)");
                }
            }

            if ('' === $s['desc']) {
                $this->problem($s, 'summary', "no summary ($at)");
            }
            if (!$doc['examples'] && !\str_contains($s['rest'], '```php')) {
                $this->problem($s, 'example', "no example ($at)");
            }
            $names = \array_column($s['params'] ?? [], 'name');
            if ($doc['paramOrder'] !== \array_values(\array_intersect($names, $doc['paramOrder']))) {
                $this->problem($s, 'param', '@param ' . \implode(', ', $doc['paramOrder']) . ' does not match the signature (' . \implode(', ', $names) . ") ($at)");
            }
        }
        unset($s);

        // The See also lists, after every summary has its {@see} links resolved: a target's summary is copied
        foreach ($this->all as &$s) {
            if ('topic' === $s['kind']) {
                continue;
            }
            $at  = $s['where'];
            $ctx = 'function' === $s['kind'] ? null : ($s['class'] ?? $s['full']);
            $own = $s['class'] ?? $s['full'];
            $ns  = \str_contains($own, '\\') ? \substr($own, 0, \strrpos($own, '\\')) : '';

            $s['see'] = [];
            foreach ($s['doc']['see'] as [$ref, $text, $type]) {
                if ('url' === $type) {
                    $s['see'][] = ['name' => $ref, 'url' => $ref, 'desc' => $text, 'code' => false];
                } elseif (null === ($t = $this->resolve($ref, $ctx, $ns))) {
                    $this->problem($s, 'see', "@see $ref does not resolve ($at)");
                } else {
                    $s['see'][] = ['name' => $t['name'], 'url' => $t['url'] ?? null, 'desc' => '' !== $text ? $text : ($t['desc'] ?? ''), 'code' => true];
                }
            }
        }
        unset($s);
    }

    /**
     * A `@see` or `{@see}` target to a documented symbol, to `['name' => ...]` (no `kind`) when it is
     * a PHP symbol that exists but has no page here, or null. `$class` is where the docblock is, so
     * that `seek()` finds the method of the class it is written in.
     */
    private function resolve(string $ref, ?string $class, string $ns): ?array
    {
        $ref = \preg_replace('/\(\)$/', '', \ltrim($ref, '\\'));
        if (!\str_contains($ref, '::') && null !== $class && isset($this->all["$class::$ref"])) {
            return $this->all["$class::$ref"];
        }
        if (null !== ($t = ('' !== $ns ? $this->find("$ns\\$ref") : null) ?? $this->find($ref))) {
            return $t;
        }
        [$c, $m] = \array_pad(\explode('::', $ref, 2), 2, null);
        $exists  = null === $m
            ? (\class_exists($c) || \interface_exists($c) || \function_exists($c) || \trait_exists($c) || \enum_exists($c))
            : (\class_exists($c) || \interface_exists($c)) && \method_exists($c, $m);

        return $exists ? ['name' => $ref] : null;
    }

    private function problem(array $s, string $kind, string $message): void
    {
        $this->problems[] = [$s['package'], $kind, $s['full'], $message];
    }

    /** A summary without its markdown, for search and the meta description. */
    public static function plain(string $markdown): string
    {
        return \preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', \str_replace(['`', '*'], '', $markdown));
    }

    /** `phasync\Util\WaitGroup` to `/phasync/Util/WaitGroup/`. */
    private static function path(string $full): string
    {
        return '/' . \str_replace('\\', '/', $full) . '/';
    }
}
