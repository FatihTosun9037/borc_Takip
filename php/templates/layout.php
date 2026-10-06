<?php
http_response_code($status ?? 200);
$plain = in_array($template, ['giris', 'kayit', 'status'], true);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Borç Takip</title>
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#e7f3ec">
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0c1411">
  <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <?php if (!$plain && isset($user)): ?>
    <header class="header">
      <div class="header-inner">
        <div>
          <p class="brand">Borç Takip</p>
          <p class="muted"><?= e($user['fullName']) ?></p>
        </div>
        <button type="button" id="logout" class="btn ghost slim">Çıkış</button>
      </div>
    </header>
  <?php endif; ?>
  <?php include __DIR__ . '/' . $template . '.php'; ?>
  <script src="/assets/app.js"></script>
</body>
</html>
