#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$wpLoad = '/var/www/html/wp-load.php';

if (! file_exists($wpLoad)) {
    fwrite(STDERR, "Unable to locate wp-load.php at: {$wpLoad}\n");
    exit(1);
}

require_once $wpLoad;

$pluginRoot = dirname(__DIR__);

require_once $pluginRoot . '/includes/class-sos-utils.php';
require_once $pluginRoot . '/includes/class-sos-db.php';
require_once $pluginRoot . '/includes/class-sos-logger.php';
require_once $pluginRoot . '/includes/class-sos-mapper.php';
require_once $pluginRoot . '/includes/class-sos-collector.php';

$forcedBatch = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--batch=')) {
        $forcedBatch = (int) substr($argument, 8);
    }
}

$result = SOS_Collector::run($forcedBatch);

if (! empty($result['success'])) {
    $message = sprintf(
        "Collector completed. Job #%d | status=%s | total=%d | success=%d | fail=%d\n",
        (int) ($result['job_id'] ?? 0),
        (string) ($result['status'] ?? 'unknown'),
        (int) ($result['total'] ?? 0),
        (int) ($result['success_count'] ?? 0),
        (int) ($result['fail_count'] ?? 0)
    );
    fwrite(STDOUT, $message);
    exit(0);
}

fwrite(STDERR, 'Collector failed: ' . (string) ($result['message'] ?? 'Unknown error') . "\n");
exit(1);