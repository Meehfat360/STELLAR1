<?php
/**
 * Job logging helpers.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Logger
{
    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Start a job record.
     */
    public static function start_job(string $job_type, array $notes = []): int
    {
        global $wpdb;

        $table = SOS_DB::table('price_jobs');
        $result = $wpdb->insert(
            $table,
            [
                'job_type'      => sanitize_key($job_type),
                'status'        => 'running',
                'total_items'   => 0,
                'success_count' => 0,
                'fail_count'    => 0,
                'started_at'    => SOS_Utils::mysql_now_utc(),
                'notes'         => [] === $notes ? null : wp_json_encode($notes),
            ],
            ['%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );

        if (! $result) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Finalize a job record.
     */
    public static function finish_job(int $job_id, string $status, int $total_items, int $success_count, int $fail_count, array $notes = []): bool
    {
        global $wpdb;

        if ($job_id <= 0) {
            return false;
        }

        $table = SOS_DB::table('price_jobs');

        $result = $wpdb->update(
            $table,
            [
                'status'        => sanitize_key($status),
                'total_items'   => $total_items,
                'success_count' => $success_count,
                'fail_count'    => $fail_count,
                'ended_at'      => SOS_Utils::mysql_now_utc(),
                'notes'         => [] === $notes ? null : wp_json_encode($notes),
            ],
            ['id' => $job_id],
            ['%s', '%d', '%d', '%d', '%s', '%s'],
            ['%d']
        );

        return false !== $result;
    }
}
