<?php /** @var \App\View\Template $this */ ?>
<?php /** @var \App\View\VerificationBanner $verification */ ?>
<?php if (($message = $verification->message()) !== null): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between gap-3" role="status">
    <span><?= $this->e($message) ?></span>
    <form method="post" action="/verify-email" class="m-0">
        <?= $this->csrf() ?>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Send verification link</button>
    </form>
</div>
<?php endif ?>
