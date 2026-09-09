<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Hydra<?php $this->stop() ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-4">Sign in</h1>
                    <?= $this->partial('auth/login/form', ['vm' => $vm]) ?>
                </div>
            </div>
        </div>
    </div>
</div>
