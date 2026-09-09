<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
    <title><?= $this->section('title', 'Hydra') ?></title>
    <?= $this->section('meta', '') ?>
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16x16.png">
    <link rel="manifest" href="/icons/site.webmanifest">
    <link rel="stylesheet" href="/css/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/css/app.css" />
    <script src="/js/vendor/htmx.min.js" defer></script>
    <script src="/js/app.js" defer></script>
    <script src="/js/vendor/bootstrap.bundle.min.js" defer></script>
</head>
<body hx-headers='{"X-CSRF-Token": "<?= $this->e($this->csrfToken()) ?>"}'>
    <main><?= $this->section('content') ?></main>
</body>
</html>
