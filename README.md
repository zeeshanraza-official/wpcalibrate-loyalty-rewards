# WPCalibrate Loyalty & Rewards

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/zeeshanraza-official/wpcalibrate-loyalty-rewards/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%20--%207.1.3-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-9.8%20--%2011.2.1-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net)
[![HPOS](https://img.shields.io/badge/HPOS-Compatible-success.svg)](https://woocommerce.com)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](LICENSE)

An enterprise-grade customer loyalty, rewards, and retention engine built natively for WooCommerce. Featuring a financial-grade double-entry ledger, cryptographic event-key idempotency, promotional multiplier campaigns, gamified VIP tier progression, and seamless direct point redemption across both Classic WooCommerce and modern Cart & Checkout Blocks.

---

## 📖 About WPCalibrate Loyalty & Rewards

**WPCalibrate Loyalty & Rewards** transforms one-off shoppers into repeat buyers and brand advocates. Instead of relying on fragile third-party SaaS services or slow disposable coupon codes, the plugin operates directly inside your WooCommerce store using native database tables, high-performance caching, and standard WooCommerce APIs.

### The Problem With Traditional Loyalty Plugins
* **Ledger Corruption & Double Rewards:** Webhook retries, payment gateway status bounces, and browser refreshes often award points multiple times or lose track of point debits.
* **Disposable Coupon Code Bloat:** Generating thousands of temporary WooCommerce coupon codes clutters your database and breaks tax/shipping calculations.
* **Block Checkout Incompatibility:** Modern stores using WooCommerce Cart and Checkout Blocks are unable to render legacy shortcode-based redemption boxes.
* **Sloppy Refund Accounting:** Order refunds frequently fail to proportionally claw back earned points or restore redeemed points.

### The WPCalibrate Solution
1. **Financial-Grade Audit Ledger (`wpcalibrate_rewards_transactions`):** Every point earned, redeemed, expired, or reversed is immutably logged with source IDs, timestamps, and metadata.
2. **Deterministic Event-Key Idempotency:** Every mutation requires a unique event key (`earn_order_{id}_{user}`, `redeem_order_{id}_{user}`, `demo_reg_{user}`). Duplicate requests are automatically prevented at the database level.
3. **Native Direct Cart & Checkout Discounts:** Points are applied directly to cart totals as native fee/discount line items, fully compatible with both Classic and Block checkouts.
4. **Proportional Refund Restoration:** Automated hooks calculate and reverse earned points and restore redeemed points proportionally on full and partial refunds.

---

## ⚡ Key Features

### 🛒 Earning Engine
- **Configurable Earning Ratio:** Define the base earning rate (e.g. 1 point per $1 spent).
- **Margin Protection Rules:** Choose whether to include or exclude order taxes and shipping fees from reward calculations.
- **Category & Product Overrides:** Grant bonus points on high-margin products or exclude clearance categories from point accrual.
- **Action Bonuses:**
  - **Account Registration Bonus:** Welcome new members with bonus points upon sign-up.
  - **First Order Bonus:** Incentivize initial conversion with first-order reward points.
  - **Verified Product Review Bonus:** Automatically reward customers when submitting approved product reviews on purchased merchandise.

### 🎁 Redemption & Cart Discounts
- **Direct Order Discounts:** Convert points into instant currency discounts at checkout.
- **Classic & Block Checkout Support:** Seamless interactive redemption widget rendered above the Cart totals and Checkout payment forms, plus full integration with WooCommerce Store API Cart/Checkout Blocks.
- **Redemption Safeguards:**
  - Minimum points required before redemptions unlock.
  - Maximum points allowed per order.
  - Maximum cart percentage payable with points (margin preservation).
  - Minimum order subtotal required to redeem.

### 🚀 Promotional Multiplier Campaigns
- **Double & Triple Points Events:** Launch scheduled campaigns (e.g., "Weekend 2x Double Points").
- **Targeting Scopes:** Apply multipliers globally across the catalog, or target specific products and categories.
- **Automated Scheduling:** Set start and end date/times evaluated against site local time.

### 🏆 Gamified VIP Loyalty Tiers
- **Lifetime Points Progression:** Reward high-value customers with tiered status (e.g., Bronze, Silver VIP, Gold VIP).
- **Tier Earning Multipliers:** Accelerate point accumulation for VIP members (e.g., 1.25x or 1.5x multiplier).
- **Customer Progress Bar:** Visual progress indicator displayed in the My Account rewards portal showing points needed to reach the next tier.

### ⏳ FIFO Point Expiration & Background Workers
- **Automated Expiry Schedule:** Points expire after a configurable duration (e.g. 365 days).
- **FIFO Consumption:** Redemptions automatically consume the oldest active points first.
- **Expiry Notification Emails:** Automated reminder emails sent to customers before their points expire.
- **Action Scheduler Async Workers:** Point expiration and reminder jobs run safely in background batches with zero storefront performance impact.

### 📊 Customer & Admin Experience
- **My Account Portal (`/my-account/rewards/`):** Dedicated customer hub displaying available balance, monetary value, tier badge, progress bar, expiring alerts, and paginated transaction ledger.
- **Administrative Customer Manager:** Search users, inspect lifetime stats, adjust point balances with mandatory audit reason notes, and reconcile balance caches against the ledger.
- **Audit Ledger & CSV Reporting:** Filter and export transaction history with formula-injection protection (`=`, `+`, `-`, `@` sanitization).
- **1-Click Demo Data & Showcase Generator:** Built-in tool in Admin Tools and Overview to instantly populate realistic demo transactions, sample VIP tiers, and active campaigns for immediate storefront demonstration.

---

## 🔄 Automatic Dashboard Updates via GitHub

This plugin supports direct **1-click updates inside your WordPress Dashboard** whenever a new release is published to GitHub.

### How It Works:
- **Native Release Listener:** Built-in update checking connects to GitHub Releases API. When a new version is released, WordPress automatically displays standard update notices under **Dashboard > Updates** and **Plugins**.
- **1-Click Upgrade:** Clicking **Update Now** automatically downloads the verified release archive and updates the plugin files without manual FTP or ZIP uploads.
- **Version Modal:** Clicking "View version details" renders release notes and changelog directly in the WordPress admin modal.
- **GitHub Updater Compatible:** Fully compatible with [GitHub Updater](https://github.com/afragen/github-updater) and [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).

---

## 🛠️ Architecture & Directory Tree

```
wpcalibrate-loyalty-rewards/
├── assets/
│   ├── css/
│   │   ├── admin.css                   # Admin layout, settings tabs, KPI cards, and guide grids
│   │   └── frontend.css                # My Account dashboard, progress bars, and redemption widgets
│   └── js/
│       ├── admin.js                    # Admin tab navigation and customer action handlers
│       └── redemption.js               # AJAX redemption widget logic and live discount feedback
├── branding/
│   ├── icon-dark.png                   # High-resolution dark brand mark
│   ├── icon-menu.png                   # Crisp 40x40 retina resampled pure white admin sidebar icon
│   └── icon-white.png                  # Vector white brand asset
├── languages/
│   └── wpcalibrate-loyalty-rewards.pot # Translation template catalog
├── src/
│   ├── Activation/                     # Activation, deactivation & schema creation
│   ├── Admin/                          # Admin menu, settings tabs, customer controller & notices
│   ├── Blocks/                         # WooCommerce Cart/Checkout Blocks (Store API) integration
│   ├── Campaigns/                      # Promotional multiplier campaigns engine
│   ├── Customers/                      # My Account rewards area, review & registration bonus services
│   ├── Database/                       # Migration manager, schema & transaction repository
│   ├── Emails/                         # WooCommerce transactional email classes
│   ├── Expiration/                     # Point expiration & Action Scheduler background workers
│   ├── Licensing/                      # Provider-agnostic licensing abstraction
│   ├── Orders/                         # Order earning, order metabox & refund reversal service
│   ├── Points/                         # Balance caching, calculation engine & transaction ledger
│   ├── Redemption/                     # Classic cart redemption & couponless discount service
│   ├── Reports/                        # CSV export & financial reporting
│   ├── Settings/                       # Section-aware settings manager & defaults
│   ├── Support/                        # Demo data generator, privacy manager & system diagnostics
│   ├── Tiers/                          # Loyalty tier calculation & progression service
│   ├── Updates/                        # Native GitHub Dashboard 1-click release update listener
│   └── Plugin.php                      # Core DI container & hook coordinator
├── templates/
│   └── myaccount/
│       └── rewards.php                 # Customer portal view (balance, tiers, and ledger table)
├── LICENSE                             # GNU General Public License v2.0 or later
├── readme.txt                          # WordPress.org standard metadata
├── uninstall.php                       # Configurable database ledger cleanup
└── wpcalibrate-loyalty-rewards.php     # Main plugin bootstrap & HPOS declarations
```

---

## 🗄️ Database Architecture

The plugin stores all loyalty events in a dedicated high-performance custom table:
`{$wpdb->prefix}wpcalibrate_rewards_transactions`

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary record identifier. |
| `user_id` | `BIGINT UNSIGNED` | Customer ID (foreign key to `wp_users.ID`). |
| `points` | `BIGINT` | Point delta (positive for earnings/bonuses, negative for redemptions). |
| `transaction_type`| `VARCHAR(50)` | Event type (`earn_order`, `redeem`, `earn_registration`, `earn_review`, etc.). |
| `status` | `VARCHAR(20)` | Transaction status (`completed`, `cancelled`, `expired`). |
| `source_type` | `VARCHAR(50)` | Domain source entity (`order`, `review`, `registration`, `admin`). |
| `source_id` | `BIGINT UNSIGNED` | Associated entity identifier (Order ID, Comment ID, User ID). |
| `event_key` | `VARCHAR(191) UNIQUE` | Cryptographic idempotency key preventing duplicate records. |
| `order_id` | `BIGINT UNSIGNED` | Linked WooCommerce order ID. |
| `description` | `TEXT` | Human-readable explanation displayed in ledger and customer portal. |
| `metadata` | `LONGTEXT` | JSON payload containing discounts, campaign details, or admin notes. |
| `expires_at` | `DATETIME` | Scheduled point expiry timestamp. |
| `created_at` | `DATETIME` | Record creation timestamp. |

---

## 📋 System Requirements

| Component | Minimum Requirement | Verified Target |
| :--- | :--- | :--- |
| **PHP** | `8.2.0` | PHP 8.2, PHP 8.3+ |
| **WordPress** | `6.7` | WordPress 6.7 to 7.1.3 |
| **WooCommerce** | `9.8` | WooCommerce 9.8 to 11.2.1 |
| **HPOS** | Compatible | High-Performance Order Storage (Custom Tables) |
| **Cart/Checkout** | Supported | Classic Shortcode & WooCommerce Store API Blocks |
| **Action Scheduler**| Supported | Standard background worker queue included with WooCommerce |

---

## 📝 Changelog

### [1.0.2] - 2026-10-17
- **Fixed:** Resolved `Call to undefined method TierService::getTiersConfig()` fatal critical error on demo data generation.
- **Enhanced:** Added `getTiersConfig()` and `saveTiersConfig()` alias methods to `TierService` and resilient parsing for `list` and `tiers` keys.
- **Enhanced:** Added defensive `try ... catch` exception guards across `SettingsPage` demo actions and `DemoDataService` database queries.
- **Enhanced:** Added native `GitHubUpdater` enabling 1-click automatic updates from the WordPress Dashboard.

### [1.0.1] - 2026-10-17
- **Fixed:** Product review bonus points awarding: fixed cross-tab settings checkbox reset bug via section-aware sanitization (`wpclr_settings_section`).
- **Fixed:** Added loose comment approval status checks (`== 1 || 'approved' || true`) and registered `wp_set_comment_status` hook for admin moderation approvals.
- **Added:** 1-Click Demo Data & Showcase Generator (`DemoDataService`) in Tools and Overview tab Step 5.
- **Enhanced:** Added sample VIP Tiers and Double Points campaign auto-provisioning with safe `clearDemoData()` ledger purge.

### [1.0.0] - 2026-10-17
- **Initial Release:** Enterprise-grade WooCommerce loyalty and rewards engine.
- **Financial-Grade Ledger:** Dedicated custom database table with event-key idempotency.
- **Redemption & Checkout:** Direct cart/checkout redemption discounts for Classic and Blocks checkouts.
- **Action Bonuses:** Registration bonus, first-order bonus, and product review bonus.
- **Campaigns & VIP Tiers:** Promotional multiplier events and gamified lifetime loyalty tiers.
- **Refund Management:** Proportional clawback of earned points and restoration of redeemed points.
- **FIFO Expiration:** Background expiration and warning notifications via Action Scheduler.
- **Administration Suite:** Customer management, balance reconciliation, and CSV export.

---

## 🛡️ License

WPCalibrate Loyalty & Rewards is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

---

## 💬 Support & Inquiries

- **Official Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
- **Marketplace:** [https://marketplace.wpcalibrate.com](https://marketplace.wpcalibrate.com)
- **Support Email:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
- **Direct WhatsApp:** [+44 7474 795976](https://wa.me/447474795976)
