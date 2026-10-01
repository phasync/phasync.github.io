<?php /** @var array $page @var array $children @var Symbols $symbols */ ?>
<article>
<?= render('_crumbs', ['page' => $page]) ?>
<h1><?= e($page['title']) ?></h1>
<p class="lead"><?= e($page['description']) ?></p>
<?= $page['html'] ?>
<?php if ('/reference/' === $page['url']): ?>
<?php foreach (['phasync/swerve' => 'Swerve', 'phasync/phasync' => 'phasync'] as $package => $label): ?>
<h2><?= e($label) ?> <small><code><?= e($package) ?></code></small></h2>
<ul class="index">
<?php foreach ($symbols->all as $s): if ('method' === $s['kind'] || 'topic' === $s['kind'] || ($s['package'] ?? '') !== $package) { continue; } ?>
  <li><a href="<?= $s['url'] ?>"><code><?= e($s['name']) ?></code></a> <?= inline($s['desc']) ?></li>
<?php endforeach ?>
</ul>
<?php endforeach ?>
<h2>Other packages</h2>
<ul class="index">
<?php foreach ($symbols->all as $s): if ('topic' !== $s['kind']) { continue; } ?>
  <li><a href="<?= $s['url'] ?>"><code><?= e($s['name']) ?></code></a> <?= inline($s['desc']) ?></li>
<?php endforeach ?>
</ul>
<h2>Advanced</h2>
<p>Channels, wait groups, rate limiters and claims are <a href="/advanced/">Advanced</a>: you can build a lot without them.</p>
<?php else: ?>
<ul class="index">
<?php foreach ($children as $c): ?>
  <li><a href="<?= $c['url'] ?>"><?= e($c['title']) ?></a><?= render('_badge', ['status' => $c['status']]) ?> <?= e($c['description']) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?= render('_see', ['page' => $page]) ?>
</article>
