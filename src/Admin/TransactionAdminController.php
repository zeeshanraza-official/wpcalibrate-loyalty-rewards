<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Admin;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Reports\CsvExporter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller for the admin transaction ledger table and CSV export handler.
 */
class TransactionAdminController
{
    private TransactionRepository $repository;
    private CsvExporter $csvExporter;

    public function __construct(
        ?TransactionRepository $repository = null,
        ?CsvExporter $csvExporter = null
    ) {
        $this->repository = $repository ?? new TransactionRepository();
        $this->csvExporter = $csvExporter ?? new CsvExporter($this->repository);
    }

    /**
     * Listen for export requests.
     */
    public function handleExport(): void
    {
        if (isset($_GET['wpclr_action']) && $_GET['wpclr_action'] === 'export_transactions') {
            $filters = $this->extractFilters();
            $this->csvExporter->export($filters);
        }
    }

    /**
     * Extract sanitized filters from $_GET.
     *
     * @return array<string, mixed>
     */
    public function extractFilters(): array
    {
        $filters = [];

        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = absint($_GET['user_id']);
        }
        if (!empty($_GET['transaction_type'])) {
            $filters['transaction_type'] = sanitize_key($_GET['transaction_type']);
        }
        if (!empty($_GET['status'])) {
            $filters['status'] = sanitize_key($_GET['status']);
        }
        if (!empty($_GET['order_id'])) {
            $filters['order_id'] = absint($_GET['order_id']);
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_GET['date_from']);
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_GET['date_to']);
        }
        if (!empty($_GET['s'])) {
            $filters['search'] = sanitize_text_field($_GET['s']);
        }

        return $filters;
    }

    /**
     * Fetch paginated list of transactions with current filters.
     *
     * @param int $perPage
     * @return array{total: int, total_pages: int, current_page: int, rows: array<int, array<string, mixed>>}
     */
    public function getPaginatedTransactions(int $perPage = 25): array
    {
        $filters = $this->extractFilters();
        $currentPage = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($currentPage - 1) * $perPage;

        $filters['limit'] = $perPage;
        $filters['offset'] = $offset;

        $total = $this->repository->countTransactions($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $rows = $this->repository->getTransactions($filters);

        return [
            'total'        => $total,
            'total_pages'  => $totalPages,
            'current_page' => $currentPage,
            'rows'         => $rows,
        ];
    }
}
