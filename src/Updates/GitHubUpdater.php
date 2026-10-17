<?php
/**
 * Native GitHub Release Updater.
 *
 * Enables seamless WordPress Dashboard update checks and one-click upgrades
 * directly from GitHub releases without requiring third-party plugins.
 *
 * @package WPCalibrate\LoyaltyRewards\Updates
 */

declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Updates;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WordPress Dashboard automatic update notifications and 1-click upgrades
 * directly through GitHub Releases API.
 */
class GitHubUpdater
{
    private const GITHUB_REPO   = 'zeeshanraza-official/wpcalibrate-loyalty-rewards';
    private const TRANSIENT_KEY = 'wpcalibrate_loyalty_rewards_github_update';
    private const CACHE_TTL     = 21600; // 6 hours

    private string $pluginFile;
    private string $slug;
    private string $currentVersion;

    public function __construct(
        string $pluginFile,
        string $slug,
        string $currentVersion
    ) {
        $this->pluginFile     = $pluginFile;
        $this->slug           = $slug;
        $this->currentVersion = $currentVersion;
    }

    /**
     * Register WordPress update and modal API hooks.
     */
    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformationModal'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'ensureCanonicalDirectory'], 10, 3);
    }

    /**
     * Inject GitHub update release into WordPress update plugins transient.
     *
     * @param object|mixed $transient
     * @return object|mixed
     */
    public function checkUpdate($transient)
    {
        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        $release = $this->getLatestRelease();
        if (!$release || empty($release['tag_name'])) {
            return $transient;
        }

        $remoteVersion = ltrim((string) $release['tag_name'], 'v');
        $basename      = plugin_basename($this->pluginFile);

        if (version_compare($remoteVersion, $this->currentVersion, '>')) {
            $packageUrl = $this->getPackageDownloadUrl($release);

            $item = (object) [
                'id'            => 'wpcalibrate-loyalty-rewards',
                'slug'          => $this->slug,
                'plugin'        => $basename,
                'new_version'   => $remoteVersion,
                'url'           => $release['html_url'] ?? ('https://github.com/' . self::GITHUB_REPO),
                'package'       => $packageUrl,
                'icons'         => [
                    'default' => WPCLR_PLUGIN_URL . 'branding/icon-dark.png',
                ],
                'banners'       => [],
                'banners_rtl'   => [],
                'tested'        => '7.1.3',
                'requires_php'  => '8.2',
                'compatibility' => new \stdClass(),
            ];

            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            $transient->response[$basename] = $item;

            if (isset($transient->no_update[$basename])) {
                unset($transient->no_update[$basename]);
            }
        } else {
            $item = (object) [
                'id'            => 'wpcalibrate-loyalty-rewards',
                'slug'          => $this->slug,
                'plugin'        => $basename,
                'new_version'   => $this->currentVersion,
                'url'           => 'https://github.com/' . self::GITHUB_REPO,
                'package'       => '',
            ];

            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = [];
            }
            $transient->no_update[$basename] = $item;

            if (isset($transient->response[$basename])) {
                unset($transient->response[$basename]);
            }
        }

        return $transient;
    }

    /**
     * Provide rich modal details on "View version details" in WordPress dashboard.
     *
     * @param false|object|array $res
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function pluginInformationModal($res, string $action, $args)
    {
        if ('plugin_information' !== $action || !isset($args->slug) || $args->slug !== $this->slug) {
            return $res;
        }

        $release = $this->getLatestRelease();
        if (!$release) {
            return $res;
        }

        $remoteVersion = ltrim((string) ($release['tag_name'] ?? $this->currentVersion), 'v');
        $bodyMarkdown  = (string) ($release['body'] ?? '');

        return (object) [
            'name'          => 'WPCalibrate Loyalty & Rewards',
            'slug'          => $this->slug,
            'version'       => $remoteVersion,
            'author'        => '<a href="https://wpcalibrate.com" target="_blank" rel="noopener noreferrer">WPCalibrate</a>',
            'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
            'download_link' => $this->getPackageDownloadUrl($release),
            'requires'      => '6.7',
            'tested'        => '7.1.3',
            'requires_php'  => '8.2',
            'last_updated'  => $release['published_at'] ?? current_time('mysql'),
            'sections'      => [
                'description' => esc_html__(
                    'Enterprise-grade loyalty and rewards engine for WooCommerce stores with financial-grade ledger auditing, promotional multipliers, and block checkout support.',
                    'wpcalibrate-loyalty-rewards'
                ),
                'changelog'   => nl2br(esc_html($bodyMarkdown)),
            ],
            'banners'       => [],
        ];
    }

    /**
     * Ensure the extracted plugin folder always matches canonical plugin slug.
     *
     * @param bool|mixed   $response
     * @param array<string, mixed> $hookExtra
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function ensureCanonicalDirectory($response, array $hookExtra, array $result): array
    {
        global $wp_filesystem;

        $basename = plugin_basename($this->pluginFile);
        if (!isset($hookExtra['plugin']) || $hookExtra['plugin'] !== $basename) {
            return $result;
        }

        $properDestination = WP_PLUGIN_DIR . '/' . $this->slug;
        if (isset($result['destination']) && $result['destination'] !== $properDestination && is_object($wp_filesystem)) {
            $wp_filesystem->move($result['destination'], $properDestination);
            $result['destination'] = $properDestination;
        }

        return $result;
    }

    /**
     * Fetch latest release from GitHub API with transient caching.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestRelease(): ?array
    {
        $cached = get_site_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && !empty($cached['tag_name'])) {
            return $cached;
        }

        $apiUrl = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get(
            $apiUrl,
            [
                'headers' => [
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . (isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '6.7') . '; ' . home_url(),
                ],
                'timeout' => 10,
            ]
        );

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return null;
        }

        set_site_transient(self::TRANSIENT_KEY, $body, self::CACHE_TTL);
        return $body;
    }

    /**
     * Resolve ZIP download URL from release assets or zipball fallback.
     *
     * @param array<string, mixed> $release
     * @return string
     */
    private function getPackageDownloadUrl(array $release): string
    {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['name']) && str_ends_with((string) $asset['name'], '.zip')) {
                    return (string) ($asset['browser_download_url'] ?? '');
                }
            }
        }

        return (string) ($release['zipball_url'] ?? '');
    }
}
