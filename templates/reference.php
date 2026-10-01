<?php
/** @var array $page @var Symbols $symbols @var bool $hasExamples */
$s       = $page['symbol'];
$isClass = !\in_array($s['kind'], ['method', 'topic'], true);
$label   = ['class' => 'Class', 'interface' => 'Interface', 'exception' => 'Exception', 'enum' => 'Enum', 'trait' => 'Trait', 'method' => 'Method', 'topic' => 'Topic'][$s['kind']];
?>
<article class="reference">
<?= render('_crumbs', ['page' => $page]) ?>
<h1><?= e($s['name']) ?> <span class="badge kind"><?= $label ?></span><?= render('_badge', ['status' => $page['source'] ? $page['status'] : 'stable']) ?></h1>
<p class="lead"><?= e($s['desc']) ?></p>

<?php if ('topic' !== $s['kind']): ?>
<h2 id="description">Description</h2>
<div class="signature"><?= code($s['sig'], 'php') ?></div>
<?= '' !== $s['rest'] ? Markdown::html($s['rest']) : '' ?>
<p class="meta"><?= 'method' === $s['kind'] ? 'Defined in <a href="/' . e(\explode('::', $s['name'])[0]) . '/"><code>' . e($s['class']) . '</code></a>.' : 'Defined in <code>' . e($s['full']) . '</code>, package <code>' . e($s['package']) . '</code>.' ?></p>
<?php endif ?>

<?php if ('method' === $s['kind']): ?>
<h2 id="parameters">Parameters</h2>
<?php if (!$s['params']): ?>
<p>This method has no parameters.</p>
<?php else: ?>
<dl class="params">
<?php foreach ($s['params'] as $p): ?>
  <dt><code>$<?= e($p['name']) ?></code> <?php if ($p['type']): ?><span class="type"><?= e($p['type']) ?></span><?php endif ?><?php if (null !== $p['default']): ?> <span class="default">default <code><?= e($p['default']) ?></code></span><?php endif ?></dt>
  <dd><?= $p['desc'] ? inline($p['desc']) : '' ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>

<h2 id="return-values">Return values</h2>
<p><?php if ($s['returnDesc']): ?><?= inline(\ucfirst($s['returnDesc'])) ?> <span class="type"><?= e($s['return']) ?></span><?php elseif ('void' === $s['return']): ?>No value is returned.<?php elseif ($s['return']): ?>Returns <code><?= e($s['return']) ?></code>.<?php else: ?>No return type is declared.<?php endif ?></p>

<?php if ($s['throws']): ?>
<h2 id="errors">Errors / Exceptions</h2>
<dl class="params">
<?php foreach ($s['throws'] as [$class, $desc]): $t = $symbols->find($class); ?>
  <dt><code><?= $t ? '<a href="' . $t['url'] . '">' . e($class) . '</a>' : e($class) ?></code></dt>
  <dd><?= $desc ? inline(\ucfirst($desc)) : '' ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
<?php endif ?>

<?php if ($isClass): ?>
<?php if ($s['consts']): ?>
<h2 id="constants">Constants</h2>
<dl class="params">
<?php foreach ($s['consts'] as $c): ?>
  <dt><code><?= e($c['name']) ?></code> <span class="default">= <code><?= e($c['value']) ?></code></span></dt>
  <dd><?= e($c['desc']) ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
<?php if ($s['props']): ?>
<h2 id="properties">Properties</h2>
<dl class="params">
<?php foreach ($s['props'] as $p): ?>
  <dt><code>$<?= e($p['name']) ?></code> <span class="type"><?= e(\trim($p['mods'] . ' ' . $p['type'])) ?></span></dt>
  <dd><?= e($p['desc']) ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
<?php if ($s['methods']): ?>
<h2 id="methods">Methods</h2>
<ul class="index">
<?php foreach ($s['methods'] as $full): $m = $symbols->all[$full]; ?>
  <li><a href="<?= $m['url'] ?>"><code><?= e($m['name']) ?></code></a> <?= e($m['desc']) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php endif ?>

<?= $page['html'] ?>
<?php if (!$hasExamples && 'topic' !== $s['kind']): ?>
<h2 id="examples">Examples</h2>
<p class="callout placeholder"><strong>Examples: placeholder.</strong> This page is generated from the docblock; hand-written examples are still to come.</p>
<?php endif ?>
<?= render('_see', ['page' => $page]) ?>
</article>
