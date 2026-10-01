<?php if ($page['see'] ?? []): ?>
<h2 id="see-also">See also</h2>
<ul class="see-also">
<?php foreach ($page['see'] as $s): ?>
  <li><?php $label = $s['code'] ? '<code>' . e($s['name']) . '</code>' : e($s['name']); ?><?= $s['url'] ? '<a href="' . $s['url'] . '">' . $label . '</a>' : $label ?> <?= inline($s['desc']) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
