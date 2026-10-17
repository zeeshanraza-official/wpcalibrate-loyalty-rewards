<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Blocks;

use WPCalibrate\LoyaltyRewards\Redemption\ClassicCartRedemption;
use WPCalibrate\LoyaltyRewards\Redemption\RedemptionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WooCommerce Cart & Checkout Blocks integration using Store API extension endpoints and block assets.
 */
class BlocksIntegration
{
    private RedemptionService $redemptionService;

    public function __construct(?RedemptionService $redemptionService = null)
    {
        $this->redemptionService = $redemptionService ?? new RedemptionService();
    }

    /**
     * Register Blocks integration hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_blocks_loaded', [$this, 'onBlocksLoaded']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    /**
     * Register Store API schema extensions when Blocks is active.
     */
    public function onBlocksLoaded(): void
    {
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data([
                'endpoint'        => 'cart',
                'namespace'       => 'wpcalibrate_loyalty_rewards',
                'data_callback'   => [$this, 'extendCartStoreApiData'],
                'schema_callback' => [$this, 'extendCartStoreApiSchema'],
                'schema_type'     => ARRAY_A,
            ]);
        }
    }

    /**
     * Extend Store API Cart data with current customer points balance and limits.
     *
     * @return array<string, mixed>
     */
    public function extendCartStoreApiData(): array
    {
        $userId = get_current_user_id();
        $cart = WC()->cart;

        if ($userId <= 0 || !$cart) {
            return [
                'is_eligible'      => false,
                'customer_balance' => 0,
                'applied_points'   => 0,
                'discount_amount'  => 0.0,
                'min_points'       => 0,
                'max_points'       => 0,
                'point_ratio'      => 100,
            ];
        }

        $limits = $this->redemptionService->getCartRedemptionLimits($userId, $cart);
        $appliedPoints = (int) (WC()->session ? WC()->session->get(ClassicCartRedemption::SESSION_KEY, 0) : 0);
        $ratio = max(1, $limits['point_ratio']);

        return [
            'is_eligible'        => $limits['eligible'],
            'customer_balance'   => $limits['customer_balance'],
            'applied_points'     => $appliedPoints,
            'discount_amount'    => round($appliedPoints / $ratio, 2),
            'min_points'         => $limits['min_points'],
            'max_points'         => $limits['max_points'],
            'point_ratio'        => $ratio,
            'point_label_plural' => SettingsManager::get('point_label_plural', 'Points'),
        ];
    }

    /**
     * Provide schema types for Store API data extension.
     *
     * @return array<string, array<string, mixed>>
     */
    public function extendCartStoreApiSchema(): array
    {
        return [
            'is_eligible'        => ['type' => 'boolean', 'readonly' => true],
            'customer_balance'   => ['type' => 'integer', 'readonly' => true],
            'applied_points'     => ['type' => 'integer', 'readonly' => true],
            'discount_amount'    => ['type' => 'number', 'readonly' => true],
            'min_points'         => ['type' => 'integer', 'readonly' => true],
            'max_points'         => ['type' => 'integer', 'readonly' => true],
            'point_ratio'        => ['type' => 'integer', 'readonly' => true],
            'point_label_plural' => ['type' => 'string', 'readonly' => true],
        ];
    }

    /**
     * Register REST API routes for block checkout redemption.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('wpcalibrate/v1', '/cart/redeem', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleRestRedeem'],
            'permission_callback' => static function (): bool {
                return is_user_logged_in();
            },
            'args'                => [
                'points' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('wpcalibrate/v1', '/cart/remove-redeem', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleRestRemoveRedeem'],
            'permission_callback' => static function (): bool {
                return is_user_logged_in();
            },
        ]);
    }

    /**
     * REST endpoint callback to apply points.
     */
    public function handleRestRedeem(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = get_current_user_id();
        $points = (int) $request->get_param('points');

        if (!WC()->cart) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Cart is empty or not initialized.', 'wpcalibrate-loyalty-rewards'),
            ], 400);
        }

        $validation = $this->redemptionService->validateRedemption($userId, $points, WC()->cart);

        if (!$validation['valid']) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $validation['message'],
            ], 400);
        }

        if (WC()->session) {
            WC()->session->set(ClassicCartRedemption::SESSION_KEY, $validation['points']);
        }
        WC()->cart->calculate_totals();

        return new \WP_REST_Response([
            'success'  => true,
            'message'  => $validation['message'],
            'points'   => $validation['points'],
            'discount' => $validation['discount'],
        ], 200);
    }

    /**
     * REST endpoint callback to remove points.
     */
    public function handleRestRemoveRedeem(\WP_REST_Request $request): \WP_REST_Response
    {
        if (WC()->session) {
            WC()->session->__unset(ClassicCartRedemption::SESSION_KEY);
        }
        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Points removed successfully.', 'wpcalibrate-loyalty-rewards'),
        ], 200);
    }
}
