<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apiRoot = dirname(__DIR__);
$authVendorAutoload = $apiRoot . '/../auth/vendor/autoload.php';
if (file_exists($authVendorAutoload)) {
    require_once $authVendorAutoload;

    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable($apiRoot . '/../auth');
        $dotenv->safeLoad();
    }
}

require_once $apiRoot . '/services/RealtimeOutboxProcessor.php';

$options = getopt('', ['loop', 'sleep-ms::', 'batch::', 'max-runtime-ms::']);
$loop = array_key_exists('loop', $options);
$sleepMilliseconds = max(50, min(5000, (int)($options['sleep-ms'] ?? 250)));
$batch = max(1, min(50, (int)($options['batch'] ?? MQ_REALTIME_OUTBOX_BATCH_SIZE)));
$maxRuntimeMilliseconds = max(
    100,
    min(10000, (int)($options['max-runtime-ms'] ?? MQ_REALTIME_OUTBOX_MAX_RUNTIME_MS))
);

do {
    $stats = (new RealtimeOutboxProcessor())->drain($batch, $maxRuntimeMilliseconds);
    if (!$loop || $stats['claimed'] > 0 || $stats['failed'] > 0) {
        echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    if ($loop) {
        usleep(($stats['claimed'] > 0 ? 50 : $sleepMilliseconds) * 1000);
    }
} while ($loop);
