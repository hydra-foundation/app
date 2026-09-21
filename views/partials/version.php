<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Core\Versions $versions */ ?>
<?= $this->e(implode(' · ', array_filter([$versions->application(), 'Hydra ' . $versions->hydra()]))) ?>
