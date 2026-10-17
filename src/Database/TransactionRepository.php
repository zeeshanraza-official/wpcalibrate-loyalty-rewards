<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database operations for the loyalty transaction ledger.
 */
class TransactionRepository
{
    /**
     * Insert a new transaction record.
     *
     * @param array<string, mixed> $data
     * @return int Inserted ID or 0 on failure.
     */
    public function insert(array $data): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $now = gmdate('Y-m-d H:i:s');

        $record = [
            'user_id'               => absint($data['user_id'] ?? 0),
            'points'                => (int) ($data['points'] ?? 0),
            'transaction_type'      => sanitize_key((string) ($data['transaction_type'] ?? 'adjustment')),
            'status'                => sanitize_key((string) ($data['status'] ?? 'completed')),
            'source_type'           => isset($data['source_type']) ? sanitize_key((string) $data['source_type']) : null,
            'source_id'             => isset($data['source_id']) ? absint($data['source_id']) : null,
            'event_key'             => isset($data['event_key']) ? sanitize_text_field((string) $data['event_key']) : null,
            'order_id'              => isset($data['order_id']) ? absint($data['order_id']) : null,
            'product_id'            => isset($data['product_id']) ? absint($data['product_id']) : null,
            'campaign_id'           => isset($data['campaign_id']) ? absint($data['campaign_id']) : null,
            'parent_transaction_id' => isset($data['parent_transaction_id']) ? absint($data['parent_transaction_id']) : null,
            'description'           => isset($data['description']) ? sanitize_text_field((string) $data['description']) : null,
            'metadata'              => isset($data['metadata']) ? (is_string($data['metadata']) ? $data['metadata'] : wp_json_encode($data['metadata'])) : null,
            'expires_at'            => isset($data['expires_at']) ? sanitize_text_field((string) $data['expires_at']) : null,
            'created_at'            => $data['created_at'] ?? $now,
            'updated_at'            => $now,
        ];

        $formats = [
            '%d', // user_id
            '%d', // points
            '%s', // transaction_type
            '%s', // status
            $record['source_type'] !== null ? '%s' : null,
            $record['source_id'] !== null ? '%d' : null,
            $record['event_key'] !== null ? '%s' : null,
            $record['order_id'] !== null ? '%d' : null,
            $record['product_id'] !== null ? '%d' : null,
            $record['campaign_id'] !== null ? '%d' : null,
            $record['parent_transaction_id'] !== null ? '%d' : null,
            $record['description'] !== null ? '%s' : null,
            $record['metadata'] !== null ? '%s' : null,
            $record['expires_at'] !== null ? '%s' : null,
            '%s', // created_at
            '%s', // updated_at
        ];

        // Filter out nulls from record & format mapping for precise insert
        $filteredData = [];
        $filteredFormats = [];
        $keys = array_keys($record);
        for ($i = 0, $count = count($keys); $i < $count; $i++) {
            $key = $keys[$i];
            $val = $record[$key];
            if ($val !== null) {
                $filteredData[$key] = $val;
                $filteredFormats[] = $formats[$i];
            }
        }

        $result = $wpdb->insert($table, $filteredData, $filteredFormats);

        if (false === $result) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find a transaction by its primary ID.
     */
    public function findById(int $id): ?array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Find a transaction by its deterministic event_key.
     */
    public function findByEventKey(string $eventKey): ?array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE event_key = %s LIMIT 1", $eventKey), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Find all transactions related to an order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderId(int $orderId): array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $orderId), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Find child transactions for a parent transaction ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByParentId(int $parentId): array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE parent_transaction_id = %d ORDER BY id ASC", $parentId), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Calculate current total point balance from ledger transactions.
     */
    public function calculateBalanceFromLedger(int $userId): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $balance = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE user_id = %d AND status = 'completed'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId
            )
        );

        return (int) $balance;
    }

    /**
     * Calculate lifetime earned points (all positive completed earning events).
     */
    public function calculateLifetimeEarned(int $userId): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $earned = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE user_id = %d AND status = 'completed' AND points > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId
            )
        );

        return (int) $earned;
    }

    /**
     * Calculate lifetime redeemed points.
     */
    public function calculateLifetimeRedeemed(int $userId): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $redeemed = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(ABS(points)), 0) FROM {$table} WHERE user_id = %d AND status = 'completed' AND transaction_type = 'redeem'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId
            )
        );

        return (int) $redeemed;
    }

    /**
     * Calculate lifetime expired points.
     */
    public function calculateLifetimeExpired(int $userId): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $expired = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(ABS(points)), 0) FROM {$table} WHERE user_id = %d AND status = 'completed' AND transaction_type = 'expiry'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId
            )
        );

        return (int) $expired;
    }

    /**
     * Get expiring points for a customer before a given cutoff date.
     */
    public function getExpiringPoints(int $userId, string $cutoffDate): int
    {
        global $wpdb;
        $table = Schema::getTableName();
        $now = gmdate('Y-m-d H:i:s');

        // Sum of positive unexpired earn transactions expiring before cutoff
        $expiring = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(points), 0) FROM {$table} 
                 WHERE user_id = %d 
                 AND status = 'completed' 
                 AND points > 0 
                 AND expires_at IS NOT NULL 
                 AND expires_at > %s 
                 AND expires_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId,
                $now,
                $cutoffDate
            )
        );

        return max(0, (int) $expiring);
    }

    /**
     * Query transactions for a user with pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserTransactions(int $userId, int $limit = 20, int $offset = 0): array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $userId,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Count total transactions for a user.
     */
    public function countUserTransactions(int $userId): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $count = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $userId) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        return (int) $count;
    }

    /**
     * Query transactions with admin filtering and pagination.
     *
     * @param array<string, mixed> $args
     * @return array<int, array<string, mixed>>
     */
    public function getTransactions(array $args = []): array
    {
        global $wpdb;
        $table = Schema::getTableName();

        $where = ['1=1'];
        $params = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = absint($args['user_id']);
        }

        if (!empty($args['transaction_type'])) {
            $where[] = 'transaction_type = %s';
            $params[] = sanitize_key($args['transaction_type']);
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $params[] = absint($args['order_id']);
        }

        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = sanitize_text_field($args['date_from']) . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = sanitize_text_field($args['date_to']) . ' 23:59:59';
        }

        if (!empty($args['search'])) {
            $where[] = '(description LIKE %s OR event_key LIKE %s)';
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        // Allowlisted sorting
        $allowedOrderBy = ['id', 'created_at', 'points', 'user_id', 'transaction_type', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedOrderBy, true) ? $args['orderby'] : 'id';
        $order = (strtoupper($args['order'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

        $limit = max(1, min(100, absint($args['limit'] ?? 20)));
        $offset = max(0, absint($args['offset'] ?? 0));

        $sql = "SELECT * FROM {$table} WHERE {$whereClause} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($prepared, ARRAY_A);

        return $results ?: [];
    }

    /**
     * Count transactions with filters.
     *
     * @param array<string, mixed> $args
     */
    public function countTransactions(array $args = []): int
    {
        global $wpdb;
        $table = Schema::getTableName();

        $where = ['1=1'];
        $params = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = absint($args['user_id']);
        }
        if (!empty($args['transaction_type'])) {
            $where[] = 'transaction_type = %s';
            $params[] = sanitize_key($args['transaction_type']);
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = sanitize_key($args['status']);
        }
        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $params[] = absint($args['order_id']);
        }
        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = sanitize_text_field($args['date_from']) . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = sanitize_text_field($args['date_to']) . ' 23:59:59';
        }
        if (!empty($args['search'])) {
            $where[] = '(description LIKE %s OR event_key LIKE %s)';
            $like = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$whereClause}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        return (int) $wpdb->get_var($sql);
    }
}
