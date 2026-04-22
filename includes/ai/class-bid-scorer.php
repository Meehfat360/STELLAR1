<?php
defined( 'ABSPATH' ) || exit;

/**
 * Real-time visitor bid signal scoring (0-100).
 * Score is passed to Meta as custom_data.bid_signal in CAPI events.
 */
class Stellar_Meta_Bid_Scorer {

    public static function score_current_visitor(): int {
        $settings = Stellar_Meta_Settings::instance();
        if ( ! $settings->is_bid_scorer_enabled() ) return 50;

        $score = 40; // base

        // Returning visitor
        if ( ! empty( $_COOKIE['stellar_uid'] ) ) $score += 10;

        // Logged in user
        if ( is_user_logged_in() ) $score += 10;

        // LTV tier bonus
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $ltv = Stellar_Meta_LTV_Predictor::get_score( $user_id );
            if ( $ltv ) {
                $bonus = match( $ltv['ltv_tier'] ) { 'vip'=>20, 'high'=>15, 'mid'=>8, default=>0 };
                $score += $bonus;
            }
        }

        // Device (mobile slightly lower conversion)
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ( stripos( $ua, 'mobile' ) !== false ) $score -= 5;

        // Time of day (peak hours bonus)
        $hour = (int) gmdate('G');
        if ( $hour >= 18 && $hour <= 22 ) $score += 5; // evening peak

        // Product page view
        if ( is_product() ) $score += 8;
        if ( is_cart() )    $score += 12;
        if ( is_checkout() )$score += 15;

        return max( 0, min( 100, $score ) );
    }
}
