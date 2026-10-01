<?php

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Markdown with front matter. Content files are ours and may hold HTML; docblocks are
 * escaped. Code blocks are rendered by `code()`: php is highlighted, `php run` is marked as
 * runnable (CI will run those), `terminal` gets a prompt.
 */
final class Markdown implements NodeRendererInterface
{
    private static array $converters = [];

    /** @return array{0: array, 1: string} the front matter and the HTML */
    public static function file(string $source): array
    {
        $result = self::converter(true)->convert($source);
        $front  = $result instanceof RenderedContentWithFrontMatter ? $result->getFrontMatter() : [];

        return [\is_array($front) ? $front : [], (string) $result];
    }

    public static function html(string $markdown, bool $trusted = false): string
    {
        return (string) self::converter($trusted)->convert($markdown);
    }

    private static function converter(bool $trusted): MarkdownConverter
    {
        return self::$converters[(int) $trusted] ??= (static function () use ($trusted) {
            $env = new Environment([
                'html_input'         => $trusted ? 'allow' : 'escape',
                'allow_unsafe_links' => false,
                'heading_permalink'  => ['symbol' => '#', 'insert' => 'after', 'html_class' => 'anchor', 'id_prefix' => '', 'fragment_prefix' => '', 'aria_hidden' => true, 'min_heading_level' => 2, 'max_heading_level' => 4],
            ]);
            $env->addExtension(new CommonMarkCoreExtension());
            $env->addExtension(new GithubFlavoredMarkdownExtension());
            $env->addExtension(new HeadingPermalinkExtension());
            if ($trusted) {
                $env->addExtension(new FrontMatterExtension());
            }
            $renderer = new self();
            $env->addRenderer(FencedCode::class, $renderer, 10);
            $env->addRenderer(IndentedCode::class, $renderer, 10);

            return new MarkdownConverter($env);
        })();
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        $words = $node instanceof FencedCode ? $node->getInfoWords() : ['php']; // indented code is in docblocks: php
        $code  = \rtrim($node->getLiteral(), "\n");

        return self::code($code, $words[0] ?? '', \in_array('run', $words, true));
    }

    /** One code block, as the theme styles it: a `.code` (or `.terminal`) around `<pre><code>`. */
    public static function code(string $code, string $lang, bool $run = false): string
    {
        if ('terminal' === $lang) {
            $html = \preg_replace('/^\$ /m', '<span class="prompt">$ </span>', \htmlspecialchars($code, \ENT_NOQUOTES));

            return "<div class=\"terminal\"><pre><code>$html</code></pre></div>\n";
        }
        $html = 'php' === $lang ? self::highlight($code) : \htmlspecialchars($code, \ENT_NOQUOTES);
        $attr = ('' !== $lang ? ' data-lang="' . \htmlspecialchars($lang) . '"' : '') . ($run ? ' data-run' : '');

        return "<div class=\"code\"$attr><pre><code>$html</code></pre></div>\n";
    }

    /** PHP's own highlighter, with its inline colours turned into classes (see `.hl-*` in the theme). */
    private static function highlight(string $code): string
    {
        static $ready = false;
        if (!$ready) {
            foreach (['comment' => '#000001', 'default' => '#000002', 'html' => '#000003', 'keyword' => '#000004', 'string' => '#000005'] as $name => $colour) {
                \ini_set("highlight.$name", $colour);
            }
            $ready = true;
        }
        $snippet = \str_contains($code, '<?php') ? $code : "<?php\n$code";
        $html    = \highlight_string($snippet, true);
        $html    = \preg_replace('~^<pre><code[^>]*>|</code></pre>$~', '', \trim($html));
        $html    = \preg_replace('~<span style="color: #000002">(.*?)</span>~s', '$1', $html);
        $html    = \str_replace(['<span style="color: #000001">', '<span style="color: #000004">', '<span style="color: #000005">', '<span style="color: #000003">'],
            ['<span class="hl-c">', '<span class="hl-k">', '<span class="hl-s">', '<span>'], $html);
        if (!\str_contains($code, '<?php')) {
            $html = \preg_replace('~^(?:<span[^>]*>)?&lt;\?php\n(?:</span>)?~', '', $html);
        }

        return \rtrim($html);
    }
}
