<?php /** @var array $page */ ?>
<article>
<?= render('_crumbs', ['page' => $page]) ?>
<h1><?= e($page['title']) ?><?= render('_badge', ['status' => $page['status']]) ?></h1>
<p class="lead"><?= e($page['description']) ?></p>
<?= $page['html'] ?>
<?= render('_see', ['page' => $page]) ?>
</article>
