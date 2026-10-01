<?php if ('stable' !== $status): ?> <span class="badge <?= e($status) ?>"><?= 'placeholder' === $status ? 'Placeholder' : 'Draft' ?></span><?php endif ?>
