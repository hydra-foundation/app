<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\ViewModels\LoginViewModel $vm */ ?>
<form id="login-form" method="post" action="/login" hx-post="/login" hx-target="this" hx-swap="outerHTML">
    <?= $this->csrf() ?>

    <?php if ($vm->hasErrors()): ?>
        <ul class="error" role="alert">
            <?php foreach ($vm->messages() as $message): ?>
                <li><?= $this->e($message) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>

    <label>
        Username
        <input type="text" name="username" value="<?= $this->e($vm->username) ?>" autocomplete="username" autofocus>
    </label>

    <label>
        Password
        <input type="password" name="password" autocomplete="current-password">
    </label>

    <button type="submit">Sign in</button>
</form>
