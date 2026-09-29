<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $current */ ?>
<?php /* Each category is a screen of this module, not a module of its own, so
   the list is written here. The strip itself is the admin's, the one a family
   of modules draws, so tabs across a module look one way everywhere. */ ?>
<?php $tabs = [] ?>
<?php foreach (['' => 'General', 'account' => 'Account', 'security' => 'Security', 'tokens' => 'API tokens', 'appearance' => 'Appearance', 'regional' => 'Regional'] as $path => $label): ?>
    <?php $tabs[] = ['label' => $label, 'url' => rtrim('/admin/settings/' . $path, '/'), 'active' => $path === $current] ?>
<?php endforeach ?>
<?= $this->partial('admin/partials/tabs', ['tabs' => $tabs, 'label' => 'Settings']) ?>
