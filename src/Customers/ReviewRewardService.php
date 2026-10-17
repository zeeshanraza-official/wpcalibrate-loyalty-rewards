<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Customers;

use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles awarding loyalty points for approved customer product reviews.
 */
class ReviewRewardService
{
    private TransactionService $transactionService;

    public function __construct(?TransactionService $transactionService = null)
    {
        $this->transactionService = $transactionService ?? new TransactionService();
    }

    /**
     * Register review hooks.
     */
    public function register(): void
    {
        add_action('comment_post', [$this, 'handleCommentPost'], 20, 2);
        add_action('transition_comment_status', [$this, 'handleCommentStatusTransition'], 20, 3);
        add_action('wp_set_comment_status', [$this, 'handleSetCommentStatus'], 20, 2);
    }

    /**
     * Handle immediate comment submission if automatically approved.
     */
    public function handleCommentPost(int $commentId, mixed $commentApproved): void
    {
        if ($commentApproved == 1 || $commentApproved === 'approved' || $commentApproved === true || $commentApproved === '1') {
            $this->maybeAwardReviewPoints($commentId);
        }
    }

    /**
     * Handle comment moderation approval via transition_comment_status.
     */
    public function handleCommentStatusTransition(string $newStatus, string $oldStatus, \WP_Comment $comment): void
    {
        if ($newStatus === 'approved' || $newStatus === 'approve') {
            $this->maybeAwardReviewPoints((int) $comment->comment_ID);
        }
    }

    /**
     * Handle direct comment status change via wp_set_comment_status.
     */
    public function handleSetCommentStatus(int|string $commentId, string $status): void
    {
        if ($status === 'approve' || $status === 'approved' || $status === '1') {
            $this->maybeAwardReviewPoints((int) $commentId);
        }
    }

    /**
     * Validate eligibility and award review bonus idempotently.
     */
    public function maybeAwardReviewPoints(int $commentId): void
    {
        if ($commentId <= 0) {
            return;
        }

        if (!SettingsManager::get('review_bonus_enabled', true)) {
            return;
        }

        $comment = get_comment($commentId);
        if (!$comment) {
            return;
        }

        $approved = (string) $comment->comment_approved;
        if ($approved !== '1' && $approved !== 'approved') {
            return;
        }

        $userId = (int) $comment->user_id;
        // Fallback: match registered user by email if review was submitted with author email
        if ($userId <= 0 && !empty($comment->comment_author_email)) {
            $user = get_user_by('email', $comment->comment_author_email);
            if ($user && $user->ID > 0) {
                $userId = (int) $user->ID;
            }
        }

        if ($userId <= 0) {
            return;
        }

        $productId = (int) $comment->comment_post_ID;
        $postType = get_post_type($productId);
        $commentType = (string) $comment->comment_type;

        // Must be a WooCommerce product post or a designated review comment type
        if ($postType !== 'product' && $commentType !== 'review') {
            return;
        }

        $points = (int) SettingsManager::get('review_bonus_points', 25);
        if ($points <= 0) {
            return;
        }

        // Idempotency: awardReviewBonus uses event_key 'earn_review_{commentId}'
        $this->transactionService->awardReviewBonus($userId, $commentId, $productId, $points);
    }
}
