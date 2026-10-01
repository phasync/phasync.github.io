<?php if ($page['see'] ?? []): ?>
<h2 id="see-also">See also</h2>
<ul class="see-also">
<?php foreach ($page['see'] as $s): ?>
  <li><a href="<?= $s['url'] ?>"><?= $s['code'] ? '<code>' . e($s['name']) . '</code>' : e($s['name']) ?></a> <?= e($s['desc']) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
