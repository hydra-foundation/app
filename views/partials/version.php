<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Core\Versions $versions */ ?>
<?php $hydra = $versions->hydra() ?>
<?= $this->e(implode(' · ', array_filter([$versions->application(), $hydra === null ? null : 'Hydra ' . $hydra]))) ?>
