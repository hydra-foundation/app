<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \App\Entities\User $user */ ?>
<?php /** @var int $total */ ?>
<?php /** @var array<string, int> $byRole */ ?>
<?php /** @var list<array<string, mixed>> $newest */ ?>
<p class="dash-meta">Signed in as <strong><?= $this->e($user->username) ?></strong></p>

<div class="stats">
    <div class="stat">
        <div class="stat-label">Users</div>
        <div class="stat-value"><?= $this->e($total) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Admins</div>
        <div class="stat-value"><?= $this->e($byRole['admin'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Standard</div>
        <div class="stat-value"><?= $this->e($byRole['user'] ?? 0) ?></div>
    </div>
</div>

<h2 class="section-title">Newest accounts</h2>

<div class="table-responsive dash-table">
    <table class="table align-middle">
        <thead>
            <tr><th scope="col">Username</th><th scope="col">Role</th><th scope="col">Created</th></tr>
        </thead>
        <tbody>
            <?php foreach ($newest as $row): ?>
                <tr>
                    <td><?= $this->e($row['username']) ?></td>
                    <td class="type-select"><?= $this->e($row['role']) ?></td>
                    <td class="type-datetime"><?= $this->e($row['created_at']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
