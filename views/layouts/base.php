<!doctype html>
<?php /** @var \App\View\ThemeResolver $theme */ ?>
<?php /** @var \App\View\Themes $themes */ ?>
<?php /* The theme is a document-level attribute so one palette file can answer
   for the whole page, and a screen that wants another names it in a section.
   Asked for here rather than at the view's construction: it reads the session,
   and the console builds a view without one. */ ?>
<html lang="en" data-theme="<?= $this->e($this->section('theme', $theme->current())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->section('title', 'Hydra') ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="manifest" href="/icons/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600&family=Zilla+Slab:wght@600&family=DM+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="/css/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/css/vendor/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="/css/base.css" />
    <?php /* Every palette on disk is linked and the data-theme attribute picks
       one, so switching is a repaint rather than a new stylesheet. Order does
       not matter: each is scoped to its own name. */ ?>
    <?php foreach ($themes->names() as $name): ?>
    <link rel="stylesheet" href="/css/themes/<?= $this->e($name) ?>.css" />
    <?php endforeach ?>
    <link rel="stylesheet" href="/css/app.css" />
    <?php /* Screens that carry their own stylesheet append it here, after the
       shared theme so it can build on the tokens rather than fight them. */ ?>
    <?= $this->section('meta', '') ?>
    <script src="/js/vendor/htmx.min.js" defer></script>
    <script src="/js/app.js" defer></script>
    <script src="/js/vendor/bootstrap.bundle.min.js" defer></script>
</head>
<body hx-headers:inherited='{"X-CSRF-Token": "<?= $this->e($this->csrfToken()) ?>"}'>
    <?php /* Where a failed htmx request lands. The error renderer swaps into it
       out-of-band, so a refusal is read here instead of replacing whatever the
       reader was working in. Empty most of the time, and styled only when it is
       not. */ ?>
    <div id="app-error" role="alert"></div>
    <main><?= $this->section('content') ?></main>
</body>
</html>
