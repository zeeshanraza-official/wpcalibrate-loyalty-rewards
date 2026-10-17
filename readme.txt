=== WPCalibrate Loyalty & Rewards ===
Contributors: wpcalibrate
Tags: woocommerce, loyalty, rewards, points, store credit, discount
Requires at least: 6.7
Tested up to: 7.1.3
Requires PHP: 8.2
WC requires at least: 9.8
WC tested up to: 11.2.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A high-integrity loyalty and rewards engine for WooCommerce stores with financial-grade ledger auditing, promotional campaigns, and block checkout support.

== Description ==

**WPCalibrate Loyalty & Rewards** is an enterprise-grade customer loyalty platform built natively for WooCommerce. It allows registered customers to earn points through qualifying purchases, account registration, first orders, and approved product reviews, and redeem accumulated points directly for order discounts.

Designed with financial-grade precision, WPCalibrate Loyalty & Rewards uses a dedicated custom database ledger (`wpcalibrate_rewards_transactions`) as the single source of truth, protected by cryptographic event-key idempotency to eliminate duplicate rewards or deductions across webhook deliveries, payment retries, and browser refreshes.

### Key Features

* **Purchase Earning:** Configurable earning ratio (e.g. 1 point per $1 spent) calculated on eligible order totals with options to include or exclude taxes and shipping.
* **Granular Rule Engine:** Product-level and category-level earning overrides and exclusions.
* **Bonus Events:** Dedicated bonus rewards for account registration, customer's first qualifying order, and approved product reviews.
* **Promotional Campaigns:** Time-bound point multiplier campaigns (e.g., 2x or 3x points) with product, category, or global targeting.
* **Direct Cart & Checkout Redemption:** Seamless point redemption in both Classic WooCommerce Cart/Checkout and WooCommerce Cart/Checkout Blocks without generating disposable coupon codes.
* **Configurable Redemption Safeguards:** Set minimum redemption points, maximum points per order, maximum percentage of cart subtotal payable with points, and minimum order subtotal.
* **Auditable Transaction Ledger:** Every balance mutation creates an immutable ledger entry. Historical records are never altered or deleted.
* **Safe Refunds & Reversals:** Proportional reversal of earned points on full or partial refunds, with automatic restoration of redeemed points.
* **Point Expiry & FIFO Consumption:** Automated expiration with FIFO (first-in, first-out) point consumption and customizable expiry warning email notifications processed safely via Action Scheduler.
* **Optional Loyalty Tiers:** Configurable tier levels (Bronze, Silver, Gold, Platinum) with earning multipliers based on lifetime qualifying points.
* **Customer My Account Hub:** Native "Rewards" endpoint displaying current balance, monetary value, tier status, upcoming expirations, and a paginated transaction history.
* **Administrative Management:** Search customers, adjust balances with mandatory audit notes, reconcile cached balances against the ledger, and export filtered transactions to CSV with formula injection protection.
* **High-Performance Order Storage (HPOS):** Verified compatible with WooCommerce HPOS and traditional storage.
* **Privacy & GDPR Compliance:** Integrates with WordPress Personal Data Export and Erasure tools.

== Installation ==

1. Ensure your hosting environment meets the minimum requirements: PHP 8.2+ and WordPress 6.7+ with WooCommerce 9.8+.
2. Upload the `wpcalibrate-loyalty-rewards` folder to the `/wp-content/plugins/` directory, or install the `wpcalibrate-loyalty-rewards.zip` package via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Navigate to **WPCalibrate > Loyalty & Rewards** in your WordPress administration dashboard to configure your earning rules, redemption settings, and notification preferences.

== Frequently Asked Questions ==

= Does this plugin work with High-Performance Order Storage (HPOS)? =
Yes. WPCalibrate Loyalty & Rewards has been developed and tested for full HPOS compatibility using WooCommerce CRUD APIs.

= Does this plugin support WooCommerce Cart and Checkout Blocks? =
Yes. Both Classic WooCommerce Cart/Checkout and the modern Cart and Checkout Blocks are supported using WooCommerce Store API extension hooks.

= Can customers earn points as guests? =
No. In V1, customers must be logged in to an active customer account to earn and redeem loyalty points. Guest checkouts complete normally without points interactions.

= How does point expiry work? =
When expiry is enabled, earned points receive an expiration timestamp. Points are consumed on a FIFO (First-In, First-Out) basis so the oldest points are redeemed first. Expired points are processed in bounded background batches via Action Scheduler.

= What happens if an order is refunded? =
On a full refund, all points earned from that order are reversed, and any points redeemed on that order are restored to the customer. On a partial refund, points are reversed and restored proportionally based on the refunded eligible amount.

== Changelog ==

= 1.0.0 =
* Initial production release.
* Financial-grade transaction ledger with custom database table and index optimization.
* Deterministic event-key idempotency for all reward transactions.
* Purchase earning, product/category rules, and exclusions.
* Registration bonus, first-order bonus, and product review bonus.
* Time-bound multiplier campaigns and optional lifetime tiers.
* Classic and Block Cart/Checkout redemption integration.
* Automated point expiry and notification scheduling via Action Scheduler.
* Customer My Account Rewards area.
* Administrative customer management, adjustments, reconciliation, and CSV export.
* WooCommerce transactional notification emails.
* HPOS and Cart/Checkout Blocks compatibility declarations.
