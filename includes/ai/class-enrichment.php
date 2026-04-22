<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Enrichment Engine.
 * Uses OpenAI GPT-4o to classify products and rewrite catalog copy.
 * Results are cached in the ai_product_data table.
 */
class Stellar_Meta_AI_Enrichment {

    const OPENAI_URL    = 'https://api.openai.com/v1/chat/completions';
    const MODEL         = 'gpt-4o';
    const CACHE_HOURS   = 168; // 1 week

    private Stellar_Meta_Settings $settings;

    public function __construct() {
        $this->settings = Stellar_Meta_Settings::instance();
    }

    // ── Classification ────────────────────────────────────────────────────────

    /**
     * Classify a product for Meta signal enrichment.
     * Returns cached result if fresh enough.
     */
    public function classify_product( int $product_id ): ?array {
        global $wpdb;

        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . STELLAR_META_DB_PREFIX . "ai_product_data
             WHERE product_id = %d
               AND generated_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $product_id, self::CACHE_HOURS
        ), ARRAY_A );

        if ( $cached ) return $cached;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return null;

        $data = $this->call_classify_api( $product );
        if ( ! $data ) return null;

        $this->store_classification( $product_id, $data );
        return $data;
    }

    private function call_classify_api( WC_Product $product ): ?array {
        $key = $this->settings->openai_key();
        if ( ! $key ) return null;

        $prompt = $this->build_classify_prompt( $product );

        $response = wp_remote_post( self::OPENAI_URL, [
            'headers' => [
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => self::MODEL,
                'temperature' => 0.2,
                'max_tokens'  => 300,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are an e-commerce product analyst. Respond only with valid JSON.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
                'response_format' => [ 'type' => 'json_object' ],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            Stellar_Meta_Logger::error( 'AI: classify WP error', [ 'error' => $response->get_error_message() ] );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $text = $body['choices'][0]['message']['content'] ?? null;
        if ( ! $text ) return null;

        $parsed = json_decode( $text, true );
        if ( ! is_array( $parsed ) ) return null;

        return $parsed;
    }

    private function build_classify_prompt( WC_Product $product ): string {
        $name  = $product->get_name();
        $desc  = wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() );
        $price = $product->get_price();
        $cats  = implode( ', ', wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ) );

        return <<<PROMPT
Classify this e-commerce product for Facebook Ads signal enrichment.

Product: {$name}
Price: {$price} USD
Categories: {$cats}
Description: {$desc}

Return JSON with these exact keys:
- purchase_type: "impulse" | "considered" (impulse = quick decision < $50, considered = research needed)
- price_tier: "budget" | "mid-range" | "luxury"
- auto_tags: comma-separated from: trending, bestseller, discount, seasonal, gift, new-arrival (max 3)
- confidence_score: 0.00 to 1.00 (your confidence in this classification)
PROMPT;
    }

    private function store_classification( int $product_id, array $data ): void {
        global $wpdb;
        $wpdb->replace(
            STELLAR_META_DB_PREFIX . 'ai_product_data',
            [
                'product_id'      => $product_id,
                'purchase_type'   => sanitize_text_field( $data['purchase_type'] ?? '' ),
                'price_tier'      => sanitize_text_field( $data['price_tier'] ?? '' ),
                'auto_tags'       => sanitize_text_field( $data['auto_tags'] ?? '' ),
                'confidence_score'=> (float) ( $data['confidence_score'] ?? 0 ),
                'model_used'      => self::MODEL,
                'generated_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%s', '%s' ]
        );
    }

    // ── Title / Description Rewriting ─────────────────────────────────────────

    public function optimize_product_copy( int $product_id ): ?array {
        global $wpdb;

        // Check if we already have AI copy
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT ai_title, ai_description FROM " . STELLAR_META_DB_PREFIX . "ai_product_data WHERE product_id = %d",
            $product_id
        ), ARRAY_A );

        if ( $existing && ! empty( $existing['ai_title'] ) ) return $existing;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return null;

        return $this->call_copy_api( $product );
    }

    private function call_copy_api( WC_Product $product ): ?array {
        $key = $this->settings->openai_key();
        if ( ! $key ) return null;

        $name = $product->get_name();
        $desc = wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() );

        $response = wp_remote_post( self::OPENAI_URL, [
            'headers' => [
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => self::MODEL,
                'temperature' => 0.7,
                'max_tokens'  => 400,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' => 'You are a world-class Facebook/Instagram ad copywriter. Write compelling, click-worthy product titles and descriptions for catalog ads. Respond only with valid JSON.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => "Rewrite this product for Meta catalog ads.\n\nOriginal title: {$name}\nOriginal description: {$desc}\n\nReturn JSON with:\n- ai_title: compelling title under 150 chars\n- ai_description: engaging description under 200 chars, benefit-focused",
                    ],
                ],
                'response_format' => [ 'type' => 'json_object' ],
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) return null;

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $text   = $body['choices'][0]['message']['content'] ?? null;
        $parsed = $text ? json_decode( $text, true ) : null;

        if ( is_array( $parsed ) ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO " . STELLAR_META_DB_PREFIX . "ai_product_data (product_id, ai_title, ai_description, model_used, generated_at)
                 VALUES (%d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE ai_title = VALUES(ai_title), ai_description = VALUES(ai_description)",
                $product->get_id(),
                sanitize_text_field( $parsed['ai_title'] ?? '' ),
                sanitize_textarea_field( $parsed['ai_description'] ?? '' ),
                self::MODEL,
                current_time( 'mysql' )
            ) );
        }

        return $parsed;
    }

    // ── Bulk Processing ───────────────────────────────────────────────────────

    /**
     * Process up to $limit unclassified products in one batch.
     * Called manually or by a scheduled job.
     */
    public function bulk_classify( int $limit = 20 ): array {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN " . STELLAR_META_DB_PREFIX . "ai_product_data a ON p.ID = a.product_id
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND a.product_id IS NULL
             LIMIT %d",
            $limit
        ) );

        $results = [ 'processed' => 0, 'failed' => 0 ];
        foreach ( $ids as $id ) {
            $res = $this->classify_product( (int) $id );
            $res ? $results['processed']++ : $results['failed']++;
            // Rate-limit: OpenAI RPM cap
            usleep( 200000 ); // 200ms pause between calls
        }
        return $results;
    }
}
