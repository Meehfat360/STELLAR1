<?php
/**
 * Pricing rules and validation helpers.
 *
 * @package StellarOverstockSync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class SOS_Rules
{
    /**
     * Disallow instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Compute final store price from source price and mapping config.
     */
    public static function compute_store_price(float $source_price, array $mapping): float
    {
        $profit_type = (string) ($mapping['profit_type'] ?? 'percent');
        $profit_value = isset($mapping['profit_value']) ? (float) $mapping['profit_value'] : 0.0;

        switch ($profit_type) {
            case 'fixed':
                $computed = $source_price + $profit_value;
                break;

            case 'percent_99':
                $computed = $source_price + ($source_price * ($profit_value / 100));
                $computed = floor($computed) + 0.99;
                break;

            case 'percent':
            default:
                $computed = $source_price + ($source_price * ($profit_value / 100));
                break;
        }

        return self::apply_rounding($computed, (string) ($mapping['price_rounding'] ?? 'none'));
    }

    /**
     * Apply optional price rounding.
     */
    public static function apply_rounding(float $price, string $rounding): float
    {
        switch ($rounding) {
            case 'nearest_99':
                return floor($price) + 0.99;

            case 'nearest_95':
                return floor($price) + 0.95;

            case 'nearest_whole':
                return round($price);

            case 'none':
            default:
                return round($price, 2);
        }
    }

    /**
     * Check min/max constraints.
     *
     * @return array{ok: bool, reason?: string}
     */
    public static function validate_bounds(float $computed_price, array $mapping): array
    {
        if (isset($mapping['min_price']) && '' !== (string) $mapping['min_price'] && null !== $mapping['min_price']) {
            if ($computed_price < (float) $mapping['min_price']) {
                return ['ok' => false, 'reason' => 'below_min_price'];
            }
        }

        if (isset($mapping['max_price']) && '' !== (string) $mapping['max_price'] && null !== $mapping['max_price']) {
            if ($computed_price > (float) $mapping['max_price']) {
                return ['ok' => false, 'reason' => 'above_max_price'];
            }
        }

        return ['ok' => true];
    }

    /**
     * Detect shock movement against prior source price.
     */
    public static function exceeds_shock_threshold(float $current_source_price, ?float $previous_source_price, float $threshold_percent): bool
    {
        if (null === $previous_source_price || $previous_source_price <= 0) {
            return false;
        }

        $change_percent = abs((($current_source_price - $previous_source_price) / $previous_source_price) * 100);

        return $change_percent > $threshold_percent;
    }
}
