<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Reports;

use WPCalibrate\LoyaltyRewards\Database\Schema;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides bounded aggregate analytics and KPI statistics for reporting and overview dashboard.
 */
class ReportService
{
    private TransactionRepository $repository;

    public function __construct(?TransactionRepository $repository = null)
    {
        $this->repository = $repository ?? new TransactionRepository();
    }

    /**
     * Get aggregate program statistics for a date range.
     *
     * @param string|null $dateFrom YYYY-MM-DD
     * @param string|null $dateTo   YYYY-MM-DD
     * @return array<string, mixed>
     */
    public function getSummaryStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $whereClause = "status = 'completed'";
        $params = [];

        if (!empty($dateFrom)) {
            $whereClause .= ' AND created_at >= %s';
            $params[] = sanitize_text_field($dateFrom) . ' 00:00:00';
        }

        if (!empty($dateTo)) {
            $whereClause .= ' AND created_at <= %s';
            $params[] = sanitize_text_field($dateTo) . ' 23:59:59';
        }

        // Total Points Issued (Earned)
        $sqlIssued = "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE {$whereClause} AND points > 0";
        $totalIssued = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sqlIssued, $params) : $sqlIssued);

        // Total Points Redeemed
        $sqlRedeemed = "SELECT COALESCE(SUM(ABS(points)), 0) FROM {$table} WHERE {$whereClause} AND transaction_type = 'redeem'";
        $totalRedeemed = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sqlRedeemed, $params) : $sqlRedeemed);

        // Total Points Expired
        $sqlExpired = "SELECT COALESCE(SUM(ABS(points)), 0) FROM {$table} WHERE {$whereClause} AND transaction_type = 'expiry'";
        $totalExpired = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sqlExpired, $params) : $sqlExpired);

        // Participating Customers in period
        $sqlCustomers = "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$whereClause}";
        $participatingCustomers = (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sqlCustomers, $params) : $sqlCustomers);

        // Total Outstanding Points (Global liability - not constrained by date range)
        $sqlOutstanding = "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE status = 'completed'";
        $totalOutstanding = max(0, (int) $wpdb->get_var($sqlOutstanding));

        $ratio = max(1, (int) SettingsManager::get('redemption_ratio', 100));
        $outstandingMonetaryValue = round($totalOutstanding / $ratio, 2);

        return [
            'total_issued'               => $totalIssued,
            'total_redeemed'             => $totalRedeemed,
            'total_expired'              => $totalExpired,
            'total_outstanding'          => $totalOutstanding,
            'outstanding_monetary_value' => $outstandingMonetaryValue,
            'participating_customers'    => $participatingCustomers,
        ];
    }
}
