<!doctype html>
<?php /* The theme is a document-level attribute so one palette file can answer
   for the whole page, and a screen that wants another names it in a section. */ ?>
<html lang="en" data-theme="<?= $this->e($this->section('theme', 'paper')) ?>">
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
    <?php /* Palettes last of the shared sheets: base.css names tokens, a theme
       gives them values, and a further theme need only be loaded after this. */ ?>
    <link rel="stylesheet" href="/css/themes/paper.css" />
    <link rel="stylesheet" href="/css/app.css" />
    <?php /* Screens that carry their own stylesheet append it here, after the
       shared theme so it can build on the tokens rather than fight them. */ ?>
    <?= $this->section('meta', '') ?>
    <script src="/js/vendor/htmx.min.js" defer></script>
    <script src="/js/app.js" defer></script>
    <script src="/js/vendor/bootstrap.bundle.min.js" defer></script>
</head>
<body hx-headers:inherited='{"X-CSRF-Token": "<?= $this->e($this->csrfToken()) ?>"}'>
    <main><?= $this->section('content') ?></main>
</body>
</html>
