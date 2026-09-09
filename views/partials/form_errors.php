<?php /** @var \App\View\Template $this */ ?>
<?php /** @var string[] $errors */ ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?= $this->e($error) ?></div>
<?php endforeach ?>
