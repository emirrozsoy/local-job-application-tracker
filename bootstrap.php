<?php

declare(strict_types=1);

$vendorAutoload = __DIR__ . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';

        try {
            $pdo = \App\Database\Connection::make($config['db']);
            $settings = new \App\Repositories\SettingsRepository($pdo);
            $config = $settings->applyToConfig($config, $settings->all());
        } catch (\Throwable) {
            // The settings table may not exist during first install; config.php remains the fallback.
        }
    }

    return $config;
}

function app_log_error(\Throwable|string $error): void
{
    $message = $error instanceof \Throwable
        ? $error->getMessage() . "\n" . $error->getTraceAsString()
        : $error;

    $dir = __DIR__ . '/storage/logs';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents(
        $dir . '/app.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n\n",
        FILE_APPEND
    );
}
