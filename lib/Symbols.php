<?php

/**
 * The reference, extracted by reflection from the installed packages, so that signatures
 * never drift from the code. Short names are the URLs (/Swerve/, /Swerve/publish/): two
 * documented classes with the same short name fail the build.
 */
final class Symbols
{
    /** A docblock type: `array<int, string>` and `array{0: int}` hold spaces. */
    private const TYPE = '(?:[^\s<{]|<[^>]*>|\{[^}]*\})+';

    /** @var array<string, array> by full name: `Swerve\Swerve`, `Swerve\Swerve::publish` */
    public array $all = [];

    /** @var array<string, string> short name (`Swerve`, `Swerve::publish`) => full name */
    private array $short = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes)
    {
        foreach ($classes as $class) {
            $this->addClass(new ReflectionClass($class));
        }
    }

    /** Add a symbol without a class behind it, for a page written by hand (`topic: true`). */
    public function addTopic(string $full, string $desc): void
    {
        $short = \substr(\strrchr('\\' . $full, '\\'), 1);
        $this->register($full, $short, [
            'full' => $full, 'name' => $short, 'kind' => 'topic', 'url' => "/$short/",
            'desc' => $desc, 'class' => null,
        ]);
    }

    /** `Swerve::publish`, `OrderedChannel`, `phasync\Channel` or `Swerve\Swerve::publish` to the symbol, or null. */
    public function find(string $name): ?array
    {
        $name = \ltrim($name, '\\');
        if (isset($this->all[$name])) {
            return $this->all[$name];
        }

        return isset($this->short[$name]) ? $this->all[$this->short[$name]] : null;
    }

    private function register(string $full, string $short, array $symbol): void
    {
        if (isset($this->short[$short])) {
            throw new BuildFailure(\sprintf('Short-name collision: %s and %s are both "%s", so both want %s', $this->short[$short], $full, $short, $symbol['url']));
        }
        $this->all[$full]    = $symbol;
        $this->short[$short] = $full;
    }

    private function addClass(ReflectionClass $rc): void
    {
        $doc   = self::doc($rc->getDocComment());
        $short = $rc->getShortName();
        $kind  = $rc->isInterface() ? 'interface' : ($rc->isEnum() ? 'enum' : ($rc->isTrait() ? 'trait' : ($rc->implementsInterface(Throwable::class) ? 'exception' : 'class')));

        $methods = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->getDeclaringClass()->getName() === $rc->getName() && !self::isInternal($rm->getDocComment())) {
                $methods[] = $this->method($rc, $rm);
            }
        }

        $props = [];
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $rp) {
            if ($rp->getDeclaringClass()->getName() === $rc->getName()) {
                $props[] = [
                    'name' => $rp->getName(), 'type' => self::type($rp->getType()),
                    'mods' => \trim(($rp->isStatic() ? 'static ' : '') . ($rp->isReadOnly() ? 'readonly' : '')),
                    'desc' => self::doc($rp->getDocComment())['short'],
                ];
            }
        }
        $consts = [];
        foreach ($rc->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $c) {
            if ($c->getDeclaringClass()->getName() === $rc->getName()) {
                $consts[] = ['name' => $c->getName(), 'value' => self::export($c->getValue()), 'desc' => self::doc($c->getDocComment())['short']];
            }
        }

        $symbol = [
            'full' => $rc->getName(), 'name' => $short, 'kind' => $kind, 'url' => "/$short/",
            'package' => \str_starts_with($rc->getName(), 'Swerve\\') ? 'phasync/swerve' : 'phasync/phasync',
            'desc' => $doc['short'], 'rest' => $doc['rest'], 'sig' => self::classSignature($rc, $kind),
            'class' => null, 'methods' => \array_map(static fn ($m) => $m['full'], $methods),
            'props' => $props, 'consts' => $consts,
        ];
        $this->register($rc->getName(), $short, $symbol);
        foreach ($methods as $m) {
            $this->register($m['full'], $m['name'], $m);
        }
    }

    private function method(ReflectionClass $rc, ReflectionMethod $rm): array
    {
        $doc    = self::doc($rm->getDocComment());
        $short  = $rc->getShortName();
        $params = [];
        foreach ($rm->getParameters() as $p) {
            $params[] = [
                'name' => $p->getName(), 'type' => self::type($p->getType()), 'variadic' => $p->isVariadic(),
                'byRef' => $p->isPassedByReference(),
                'default' => $p->isDefaultValueAvailable() ? self::default($p) : null,
                'desc' => $doc['params'][$p->getName()] ?? '',
            ];
        }
        $return = self::type($rm->getReturnType());

        $head = \trim('public ' . ($rm->isAbstract() && !$rc->isInterface() ? 'abstract ' : '') . ($rm->isFinal() ? 'final ' : '') . ($rm->isStatic() ? 'static ' : ''));
        $name = "$short::" . $rm->getName();
        $list = \array_map(static fn ($p) => self::paramSignature($p), $params);
        $line = "$head $name(" . \implode(', ', $list) . ')' . ('' !== $return ? ": $return" : '');
        if (\strlen($line) > 90 && $list) {
            $line = "$head $name(\n    " . \implode(",\n    ", $list) . "\n)" . ('' !== $return ? ": $return" : '');
        }

        return [
            'full' => $rc->getName() . '::' . $rm->getName(), 'name' => $name, 'kind' => 'method',
            'url' => "/$short/" . $rm->getName() . '/', 'class' => $rc->getName(), 'method' => $rm->getName(),
            'desc' => $doc['short'], 'rest' => $doc['rest'], 'sig' => $line, 'params' => $params,
            'return' => $return, 'returnDesc' => $doc['return'], 'throws' => $doc['throws'],
        ];
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
            null === $value  => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_array($value) => [] === $value ? '[]' : \preg_replace('/\s+/', ' ', \var_export($value, true)),
            default          => \var_export($value, true),
        };
    }

    private static function isInternal(string|false $doc): bool
    {
        return false !== $doc && \str_contains($doc, '@internal');
    }

    /**
     * A docblock to its parts: `short` (the first sentence), `rest` (the remaining text, as
     * markdown), and the tags the page uses.
     *
     * @return array{short: string, rest: string, params: array<string,string>, return: string, throws: list<array{0:string,1:string}>}
     */
    public static function doc(string|false $comment): array
    {
        $out = ['short' => '', 'rest' => '', 'params' => [], 'return' => '', 'throws' => []];
        if (false === $comment) {
            return $out;
        }
        $lines = \preg_split('/\R/', \trim($comment));
        $lines = \array_map(static fn ($l) => \preg_replace('~^\s*(/\*\*|\*/|\*)\s?~', '', \preg_replace('~\*/\s*$~', '', $l)), $lines);
        $text  = [];
        $tags  = [];
        foreach ($lines as $line) {
            if (\preg_match('/^@(\w+)\s*(.*)$/', $line, $m)) {
                $tags[] = [$m[1], $m[2]];
            } elseif ($tags && '' !== \trim($line)) {
                $tags[\array_key_last($tags)][1] .= ' ' . \trim($line); // a tag continues on the next line
            } elseif (!$tags) {
                $text[] = $line;
            }
        }
        $text = \trim(\implode("\n", $text), "\n");
        $text = \preg_replace('/\{@see\s+([^}\s]+)[^}]*\}/', '`$1`', $text);

        foreach ($tags as [$tag, $body]) {
            if ('param' === $tag && \preg_match('/^' . self::TYPE . '\s+\$(\w+)\s*(.*)$/', $body, $m)) {
                $out['params'][$m[1]] = $m[2];
            } elseif ('return' === $tag) {
                $out['return'] = \preg_replace('/^' . self::TYPE . '\s*/', '', $body); // the type is the signature's
            } elseif ('throws' === $tag && \preg_match('/^(\S+)\s*(.*)$/', $body, $m)) {
                $out['throws'][] = [\ltrim($m[1], '\\'), $m[2]];
            }
        }

        if (\preg_match('/^(.+?[.!?])(?=\s|$)/s', \trim($text), $m)) {
            $short = $m[1];
        } else {
            $short = \preg_split('/\n\s*\n/', \trim($text))[0];
        }
        $rest = \substr(\trim($text), \strlen($short));
        $rest = \preg_match('/^[ \t]*\S/', $rest) ? \ltrim($rest) : \preg_replace('/^(?:[ \t]*\n)+/', '', $rest);
        $out['short'] = \rtrim(\preg_replace('/\s+/', ' ', $short), ':');
        $out['rest']  = \rtrim($rest);

        return $out;
    }
}
