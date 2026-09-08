<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
    <title><?= $this->section('title', 'Hydra') ?></title>
    <script src="/js/htmx.min.js" defer></script>
    <script src="/js/app.js" defer></script>
</head>
<body hx-headers='{"X-CSRF-Token": "<?= $this->e($this->csrfToken()) ?>"}'>
    <main><?= $this->section('content') ?></main>
</body>
</html>
