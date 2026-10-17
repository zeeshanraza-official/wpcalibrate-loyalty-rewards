<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Reports;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles capability-protected, formula-injection-safe CSV exports of loyalty transactions.
 */
class CsvExporter
{
    private TransactionRepository $repository;

    public function __construct(?TransactionRepository $repository = null)
    {
        $this->repository = $repository ?? new TransactionRepository();
    }

    /**
     * Escape potential spreadsheet formula injection characters (=, +, -, @, tab, CR).
     */
    public static function escapeFormula(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Export filtered transactions to CSV stream directly to browser.
     *
     * @param array<string, mixed> $filters
     */
    public function export(array $filters = []): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized access to export.', 'wpcalibrate-loyalty-rewards'), 403);
        }

        check_admin_referer('wpclr_export_csv', 'wpclr_nonce');

        // Disable caching & output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }

        $filename = 'wpcalibrate-loyalty-transactions-' . gmdate('Y-m-d-His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if (!$out) {
            exit;
        }

        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");

        // CSV Header
        fputcsv($out, [
            'Transaction ID',
            'Date (UTC)',
            'User ID',
            'Customer Name',
            'Customer Email',
            'Type',
            'Points Delta',
            'Status',
            'Order ID',
            'Description',
            'Expires At',
        ]);

        // Stream in bounded batches of 500 rows
        $batchSize = 500;
        $offset = 0;

        $filters['limit'] = $batchSize;
        $filters['orderby'] = 'id';
        $filters['order'] = 'DESC';

        do {
            $filters['offset'] = $offset;
            $rows = $this->repository->getTransactions($filters);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $userId = (int) $row['user_id'];
                $user = get_userdata($userId);
                $customerName = $user ? $user->display_name : 'Unknown';
                $customerEmail = $user ? $user->user_email : 'Unknown';

                fputcsv($out, [
                    $row['id'],
                    $row['created_at'],
                    $userId,
                    self::escapeFormula($customerName),
                    self::escapeFormula($customerEmail),
                    self::escapeFormula((string) $row['transaction_type']),
                    $row['points'],
                    self::escapeFormula((string) $row['status']),
                    $row['order_id'] ?: '',
                    self::escapeFormula((string) ($row['description'] ?? '')),
                    $row['expires_at'] ?: '',
                ]);
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);

        fclose($out);
        exit;
    }
}
