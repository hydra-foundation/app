<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var array<string, array<string, string>> $options */ ?>
<?php /** @var string $selected */ ?>
<?php /** @var string $sample */ ?>
<?php /** @var string $stored */ ?>
<?php /** @var string $fallback */ ?>
<?= $this->partial('admin/settings/nav', ['current' => 'regional']) ?>

<form id="regional-form"
      method="post"
      action="/admin/settings/regional"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      hx-post="/admin/settings/regional"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>

    <div class="regional-field">
        <label class="form-label" for="timezone">Timezone</label>
        <select class="form-select" id="timezone" name="timezone">
        <?php foreach ($options as $region => $zones): ?>
            <optgroup label="<?= $this->e($region) ?>">
            <?php foreach ($zones as $identifier => $label): ?>
                <option value="<?= $this->e($identifier) ?>"<?= $identifier === $selected ? ' selected' : '' ?>><?= $this->e($label) ?></option>
            <?php endforeach ?>
            </optgroup>
        <?php endforeach ?>
        </select>
        <p class="form-text">Dates and times on every admin screen are shown in this zone. Unset, it is <?= $this->e($fallback) ?>.</p>
    </div>

    <?php /* The point of the screen: the setting is right when this line agrees
       with the clock on the wall. */ ?>
    <dl class="admin-show regional-sample">
        <dt>Your time</dt>
        <dd class="type-datetime"><?= $this->e($sample) ?></dd>

        <dt>Stored as</dt>
        <dd class="type-datetime"><?= $this->e($stored) ?></dd>
    </dl>

    <p class="form-text">Exported files keep the stored time, so a download reads the same wherever it is opened.</p>

    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Save</button>
    </div>
</form>
