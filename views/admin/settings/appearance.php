<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var array<string, string> $options */ ?>
<?php /** @var string $selected */ ?>
<?php /** @var string $current */ ?>
<?= $this->partial('admin/settings/nav', ['current' => 'appearance']) ?>

<?php /* The swapped frame carries the chosen palette so the page it lands in can
   repaint itself: only #admin-frame is replaced, and the data-theme attribute
   that selects a palette lives on <html>, which is well outside it. */ ?>
<div id="admin-theme" data-theme="<?= $this->e($current) ?>" hx-nonce="<?= $this->e($this->cspNonce()) ?>" hidden></div>

<form id="appearance-form"
      method="post"
      action="/admin/settings/appearance"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      hx-post="/admin/settings/appearance"
      hx-target="#admin-frame">
    <?= $this->csrf() ?>

    <fieldset class="theme-field">
        <legend class="form-label">Theme</legend>

        <div class="theme-choices">
        <?php foreach ($options as $name => $label): ?>
            <label class="theme-choice<?= $name === $selected ? ' selected' : '' ?>" for="theme-<?= $this->e($name) ?>">
                <input type="radio"
                       id="theme-<?= $this->e($name) ?>"
                       name="theme"
                       value="<?= $this->e($name) ?>"
                       <?= $name === $selected ? 'checked' : '' ?>>
                <?php if ($name === \App\View\Themes::AUTO): ?>
                <span class="theme-swatch-pair" aria-hidden="true">
                    <?php foreach ([\App\View\Themes::DAY, \App\View\Themes::NIGHT] as $half): ?>
                    <span class="theme-swatch" data-theme="<?= $this->e($half) ?>">
                        <span class="theme-swatch-bar"></span>
                        <span class="theme-swatch-dot"></span>
                    </span>
                    <?php endforeach ?>
                </span>
                <?php else: ?>
                <span class="theme-swatch" data-theme="<?= $this->e($name) ?>" aria-hidden="true">
                    <span class="theme-swatch-bar"></span>
                    <span class="theme-swatch-dot"></span>
                </span>
                <?php endif ?>
                <span class="theme-name"><?= $this->e($label) ?></span>
            </label>
        <?php endforeach ?>
        </div>

        <p class="form-text">Auto uses Paper from <?= sprintf('%02d:00', \App\View\ThemeResolver::DAY_STARTS) ?> to <?= sprintf('%02d:00', \App\View\ThemeResolver::DAY_ENDS) ?> and Graphite outside those hours, in the timezone set under Regional.</p>
    </fieldset>

    <div class="admin-form-actions">
        <button class="btn btn-primary" type="submit">Save</button>
    </div>
</form>
