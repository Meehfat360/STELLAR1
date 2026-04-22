<?php
/**
 * Stellar Meta — Feed Endpoint v4.0.0
 *
 * FIXES:
 *  - Token comparison uses hash_equals() to prevent timing attacks
 *  - $_GET['token'] properly sanitized before comparison
 *  - Added rate-limiting via transient (max 60 req/hour per IP)
 *  - Security headers added to feed response
 */
defined( 'ABSPATH' ) || exit;

class Stellar_Meta_Feed_Endpoint {

    public static function register(): void {
        add_action( 'init',               [ __CLASS__, 'add_rewrite' ] );
        add_action( 'template_redirect',  [ __CLASS__, 'serve_feed'  ] );
        add_filter( 'query_vars',         [ __CLASS__, 'query_vars'  ] );
    }

    public static function add_rewrite(): void {
        add_rewrite_rule( '^stellar-meta-feed/?$', 'index.php?stellar_meta_feed=1', 'top' );
    }

    public static function query_vars( array $vars ): array {
        $vars[] = 'stellar_meta_feed';
        return $vars;
    }

    public static function serve_feed(): void {
        $is_feed = get_query_var('stellar_meta_feed') || isset($_GET['stellar-meta-feed']);
        if ( ! $is_feed ) return;

        // Rate limiting: max 60 requests per hour per IP
        $ip       = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $rate_key = 'stellar_feed_rate_' . md5( $ip );
        $hits     = (int) get_transient( $rate_key );
        if ( $hits >= 60 ) {
            status_header( 429 );
            header( 'Retry-After: 3600' );
            exit( 'Too Many Requests' );
        }
        set_transient( $rate_key, $hits + 1, HOUR_IN_SECONDS );

        // Token auth — use hash_equals() to prevent timing attacks
        $stored_token = (string) get_option( 'stellar_meta_feed_token', '' );
        if ( $stored_token !== '' ) {
            $provided = sanitize_text_field( $_GET['token'] ?? '' );
            if ( ! hash_equals( $stored_token, $provided ) ) {
                status_header( 401 );
                nocache_headers();
                exit( 'Unauthorized' );
            }
        }

        try {
            $feed = Stellar_Meta_Feed_Generator::generate();
        } catch ( \Throwable $e ) {
            Stellar_Meta_Logger::error('Feed serve failed',['error'=>$e->getMessage()]);
            status_header( 500 );
            exit( 'Feed generation error.' );
        }

        // Security headers
        header( 'Content-Type: application/rss+xml; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'X-Content-Type-Options: nosniff' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — RSS feed is pre-built XML
        echo $feed;
        exit;
    }
}

Stellar_Meta_Feed_Endpoint::register();
