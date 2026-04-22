<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cross-device identity stitching.
 * Sets a persistent server-side HttpOnly cookie (stellar_uid) that
 * survives ITP. Links anonymous sessions to authenticated users.
 */
class Stellar_Meta_Identity_Graph {

    const COOKIE_NAME    = 'stellar_uid';
    const COOKIE_EXPIRY  = 365 * DAY_IN_SECONDS;

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',              [ $this, 'ensure_uid_cookie' ], 1 );
        add_action( 'wp_login',          [ $this, 'stitch_on_login' ], 10, 2 );
        add_action( 'woocommerce_thankyou', [ $this, 'stitch_on_order' ], 10 );
    }

    // ── Cookie management ─────────────────────────────────────────────────────

    public function ensure_uid_cookie(): void {
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $uid = sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] );
            $this->touch_identity( $uid );
            return;
        }
        if ( headers_sent() ) return;
        $uid = wp_generate_uuid4();
        setcookie( self::COOKIE_NAME, $uid, [
            'expires'  => time() + self::COOKIE_EXPIRY,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
        $_COOKIE[ self::COOKIE_NAME ] = $uid;
        $this->create_identity( $uid );
    }

    public static function get_uid(): string {
        return sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ?? '' );
    }

    // ── Stitching ─────────────────────────────────────────────────────────────

    public function stitch_on_login( string $user_login, WP_User $user ): void {
        $uid = self::get_uid();
        if ( ! $uid ) return;
        $email_hash = hash( 'sha256', strtolower( trim( $user->user_email ) ) );
        global $wpdb;
        $wpdb->update(
            STELLAR_META_DB_PREFIX . 'identity_graph',
            [ 'email_hash' => $email_hash, 'customer_id' => $user->ID, 'last_seen' => current_time('mysql') ],
            [ 'stellar_uid' => $uid ],
            [ '%s', '%d', '%s' ], [ '%s' ]
        );
    }

    public function stitch_on_order( $order_id ): void {
        $uid = self::get_uid();
        if ( ! $uid ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $email_hash  = hash( 'sha256', strtolower( trim( $order->get_billing_email() ) ) );
        $customer_id = $order->get_customer_id();

        global $wpdb;
        $wpdb->update(
            STELLAR_META_DB_PREFIX . 'identity_graph',
            [ 'email_hash' => $email_hash, 'customer_id' => $customer_id ?: null, 'last_seen' => current_time('mysql') ],
            [ 'stellar_uid' => $uid ],
            [ '%s', '%d', '%s' ], [ '%s' ]
        );

        // Store ga4 client_id in order meta for server-side events
        if ( ! empty( $_COOKIE['_ga'] ) ) {
            $parts = explode( '.', sanitize_text_field( $_COOKIE['_ga'] ) );
            if ( count( $parts ) >= 4 ) {
                $order->update_meta_data( '_stellar_ga4_client_id', $parts[2] . '.' . $parts[3] );
                $order->save();
            }
        }
    }

    // ── DB helpers ────────────────────────────────────────────────────────────

    private function create_identity( string $uid ): void {
        global $wpdb;
        $wpdb->insert(
            STELLAR_META_DB_PREFIX . 'identity_graph',
            [ 'stellar_uid' => $uid, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql') ],
            [ '%s', '%s', '%s' ]
        );
    }

    private function touch_identity( string $uid ): void {
        global $wpdb;
        $wpdb->update(
            STELLAR_META_DB_PREFIX . 'identity_graph',
            [ 'last_seen' => current_time('mysql') ],
            [ 'stellar_uid' => $uid ],
            [ '%s' ], [ '%s' ]
        );
    }
}
