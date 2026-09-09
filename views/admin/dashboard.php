<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var int $total */ ?>
<?php /** @var array<string, int> $byRole */ ?>
<?php /** @var list<array<string, mixed>> $newest */ ?>
<p class="text-body-secondary">Signed in as <strong><?= $this->e($user->username) ?></strong>.</p>

<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small">Users</div>
                <div class="fs-2"><?= $this->e($total) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small">Admins</div>
                <div class="fs-2"><?= $this->e($byRole['admin'] ?? 0) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-body-secondary small">Standard</div>
                <div class="fs-2"><?= $this->e($byRole['user'] ?? 0) ?></div>
            </div>
        </div>
    </div>
</div>

<h2 class="h5 mb-3">Newest accounts</h2>

<div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead>
            <tr><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Created</th></tr>
        </thead>
        <tbody>
            <?php foreach ($newest as $row): ?>
                <tr>
                    <td><?= $this->e($row['username']) ?></td>
                    <td><?= $this->e($row['role']) ?></td>
                    <td><?= $this->e($row['created_at']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
