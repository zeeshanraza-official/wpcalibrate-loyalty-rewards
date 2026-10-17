<?php
/**
 * WooCommerce My Account Rewards Dashboard & History Template.
 *
 * @var int    $balance
 * @var float  $rewardValue
 * @var string $pointLabelPlural
 * @var array  $tierInfo
 * @var int    $expiringPoints
 * @var array  $transactions
 * @var int    $currentPage
 * @var int    $totalPages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wpclr-myaccount-rewards">
    <!-- Rewards Overview Cards -->
    <div class="wpclr-overview-grid">
        <!-- Balance Card -->
        <div class="wpclr-card wpclr-card-balance">
            <div class="wpclr-card-label"><?php esc_html_e('Available Balance', 'wpcalibrate-loyalty-rewards'); ?></div>
            <div class="wpclr-card-value"><?php echo esc_html(number_format_i18n($balance)); ?></div>
            <div class="wpclr-card-sub">
                <?php
                printf(
                    /* translators: %s: Monetary value */
                    esc_html__('Estimated value: %s', 'wpcalibrate-loyalty-rewards'),
                    '<strong>' . wc_price($rewardValue) . '</strong>'
                );
                ?>
            </div>
        </div>

        <!-- Tier Card (if enabled) -->
        <?php if (!empty($tierInfo['enabled'])) : ?>
            <div class="wpclr-card wpclr-card-tier">
                <div class="wpclr-card-label"><?php esc_html_e('Loyalty Tier', 'wpcalibrate-loyalty-rewards'); ?></div>
                <div class="wpclr-card-value">
                    <?php echo esc_html($tierInfo['current_tier']['name'] ?? __('Member', 'wpcalibrate-loyalty-rewards')); ?>
                </div>
                <div class="wpclr-card-sub">
                    <?php if (!empty($tierInfo['next_tier'])) : ?>
                        <?php
                        printf(
                            /* translators: 1: Points needed, 2: Next tier name */
                            esc_html__('%1$d points needed for %2$s', 'wpcalibrate-loyalty-rewards'),
                            (int) $tierInfo['points_needed'],
                            esc_html($tierInfo['next_tier']['name'])
                        );
                        ?>
                        <div class="wpclr-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr((string) $tierInfo['progress_percent']); ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="wpclr-progress-fill" style="width: <?php echo esc_attr((string) $tierInfo['progress_percent']); ?>%;"></div>
                        </div>
                    <?php else : ?>
                        <span class="wpclr-badge-top"><?php esc_html_e('Top tier unlocked!', 'wpcalibrate-loyalty-rewards'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upcoming Expiration (if any) -->
        <?php if ($expiringPoints > 0) : ?>
            <div class="wpclr-card wpclr-card-expiring">
                <div class="wpclr-card-label"><?php esc_html_e('Expiring Soon', 'wpcalibrate-loyalty-rewards'); ?></div>
                <div class="wpclr-card-value text-danger"><?php echo esc_html(number_format_i18n($expiringPoints)); ?></div>
                <div class="wpclr-card-sub text-danger">
                    <?php esc_html_e('Expiring within the next 30 days.', 'wpcalibrate-loyalty-rewards'); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- How to Earn Info -->
    <div class="wpclr-section wpclr-ways-to-earn">
        <h3 class="wpclr-section-title"><?php esc_html_e('How to Earn Points', 'wpcalibrate-loyalty-rewards'); ?></h3>
        <ul class="wpclr-earn-list">
            <li>
                <span class="wpclr-icon">🛒</span>
                <div>
                    <strong><?php esc_html_e('Shop & Earn:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                    <?php esc_html_e('Earn loyalty points automatically with every qualifying purchase.', 'wpcalibrate-loyalty-rewards'); ?>
                </div>
            </li>
            <li>
                <span class="wpclr-icon">⭐</span>
                <div>
                    <strong><?php esc_html_e('Write Product Reviews:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                    <?php esc_html_e('Receive bonus points for every approved product review you submit.', 'wpcalibrate-loyalty-rewards'); ?>
                </div>
            </li>
            <li>
                <span class="wpclr-icon">🏷️</span>
                <div>
                    <strong><?php esc_html_e('Double Points Events:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                    <?php esc_html_e('Participate in promotional multiplier campaigns for accelerated rewards.', 'wpcalibrate-loyalty-rewards'); ?>
                </div>
            </li>
        </ul>
    </div>

    <!-- Transaction History -->
    <div class="wpclr-section wpclr-history-section">
        <h3 class="wpclr-section-title"><?php esc_html_e('Reward Points History', 'wpcalibrate-loyalty-rewards'); ?></h3>

        <?php if (empty($transactions)) : ?>
            <p class="woocommerce-info"><?php esc_html_e('No rewards activity recorded yet.', 'wpcalibrate-loyalty-rewards'); ?></p>
        <?php else : ?>
            <div class="wpclr-table-responsive">
                <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table wpclr-table">
                    <thead>
                        <tr>
                            <th scope="col" class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Date', 'wpcalibrate-loyalty-rewards'); ?></span></th>
                            <th scope="col" class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Description', 'wpcalibrate-loyalty-rewards'); ?></span></th>
                            <th scope="col" class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Order', 'wpcalibrate-loyalty-rewards'); ?></span></th>
                            <th scope="col" class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Points', 'wpcalibrate-loyalty-rewards'); ?></span></th>
                            <th scope="col" class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Status', 'wpcalibrate-loyalty-rewards'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx) : ?>
                            <?php
                            $isCredit = (int) $tx['points'] > 0;
                            $orderId = !empty($tx['order_id']) ? (int) $tx['order_id'] : null;
                            ?>
                            <tr class="woocommerce-orders-table__row">
                                <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e('Date', 'wpcalibrate-loyalty-rewards'); ?>">
                                    <time datetime="<?php echo esc_attr((string) $tx['created_at']); ?>">
                                        <?php echo esc_html(wp_date(get_option('date_format', 'Y-m-d'), strtotime((string) $tx['created_at']))); ?>
                                    </time>
                                </td>
                                <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e('Description', 'wpcalibrate-loyalty-rewards'); ?>">
                                    <?php echo esc_html((string) ($tx['description'] ?? '')); ?>
                                </td>
                                <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e('Order', 'wpcalibrate-loyalty-rewards'); ?>">
                                    <?php if ($orderId) : ?>
                                        <a href="<?php echo esc_url(wc_get_endpoint_url('view-order', (string) $orderId, wc_get_page_permalink('myaccount'))); ?>">
                                            #<?php echo esc_html((string) $orderId); ?>
                                        </a>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e('Points', 'wpcalibrate-loyalty-rewards'); ?>">
                                    <span class="wpclr-badge-points <?php echo $isCredit ? 'wpclr-credit' : 'wpclr-debit'; ?>">
                                        <?php echo $isCredit ? '+' : ''; ?><?php echo esc_html(number_format_i18n((int) $tx['points'])); ?>
                                    </span>
                                </td>
                                <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e('Status', 'wpcalibrate-loyalty-rewards'); ?>">
                                    <span class="wpclr-status-tag wpclr-status-<?php echo esc_attr(sanitize_html_class((string) $tx['status'])); ?>">
                                        <?php echo esc_html(ucfirst((string) $tx['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1) : ?>
                <nav class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination" aria-label="<?php esc_attr_e('Rewards History Pagination', 'wpcalibrate-loyalty-rewards'); ?>">
                    <?php if ($currentPage > 1) : ?>
                        <a class="woocommerce-button woocommerce-button--previous woocommerce-Button button" href="<?php echo esc_url(add_query_arg('rewards_page', $currentPage - 1)); ?>">
                            <?php esc_html_e('Previous', 'wpcalibrate-loyalty-rewards'); ?>
                        </a>
                    <?php endif; ?>

                    <span class="wpclr-page-indicator">
                        <?php
                        printf(
                            /* translators: 1: Current page, 2: Total pages */
                            esc_html__('Page %1$d of %2$d', 'wpcalibrate-loyalty-rewards'),
                            $currentPage,
                            $totalPages
                        );
                        ?>
                    </span>

                    <?php if ($currentPage < $totalPages) : ?>
                        <a class="woocommerce-button woocommerce-button--next woocommerce-Button button" href="<?php echo esc_url(add_query_arg('rewards_page', $currentPage + 1)); ?>">
                            <?php esc_html_e('Next', 'wpcalibrate-loyalty-rewards'); ?>
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
