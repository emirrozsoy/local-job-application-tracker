<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function readLastLines(string $path, int $lines = 250): string
{
    if (!is_file($path) || !is_readable($path)) {
        return 'Log dosyası bulunamadı veya okunamıyor: ' . $path;
    }

    $file = new SplFileObject($path, 'r');
    $file->seek(PHP_INT_MAX);
    $lastLine = $file->key();
    $start = max(0, $lastLine - $lines);
    $buffer = [];

    for ($line = $start; $line <= $lastLine; $line++) {
        $file->seek($line);
        $buffer[] = $file->current();
    }

    return trim(implode('', $buffer));
}

$logs = [
    'Uygulama Logu' => __DIR__ . '/storage/logs/app.log',
    'Apache Error Log' => '/Applications/XAMPP/xamppfiles/logs/error_log',
    'PHP Error Log' => '/Applications/XAMPP/xamppfiles/logs/php_error_log',
];

?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error Logları</title>
    <style>
        body { margin:0; background:#f4f6f8; color:#18202a; font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .wrap { width:min(1200px, calc(100% - 32px)); margin:28px auto; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:18px; }
        .panel { background:#fff; border:1px solid #dbe1e8; border-radius:8px; padding:18px; margin-bottom:18px; }
        h1, h2 { margin:0 0 8px; letter-spacing:0; }
        a, button { color:#1667d8; }
        .btn { display:inline-flex; text-decoration:none; border:1px solid #ccd9ea; border-radius:8px; padding:10px 14px; background:#eef3fa; color:#0f4fa8; font-weight:650; }
        pre { margin:0; white-space:pre-wrap; word-break:break-word; background:#0f1720; color:#dce7f7; border-radius:8px; padding:14px; max-height:420px; overflow:auto; }
        .muted { color:#687385; }
    </style>
</head>
<body>
<main class="wrap">
    <div class="topbar">
        <div>
            <h1>Error Logları</h1>
            <div class="muted">Son 250 satır gösterilir. Sayfayı yenileyerek güncel logları görebilirsin.</div>
        </div>
        <div>
            <a class="btn" href="job_applications.php">Ana Sayfa</a>
            <a class="btn" href="logs.php">Yenile</a>
        </div>
    </div>

    <?php foreach ($logs as $title => $path): ?>
        <section class="panel">
            <h2><?= e($title) ?></h2>
            <div class="muted"><?= e($path) ?></div>
            <pre><?= e(readLastLines($path)) ?></pre>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
