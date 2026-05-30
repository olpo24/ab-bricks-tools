<?php
declare(strict_types=1);

namespace AB\BricksTools\Admin;

use AB\BricksTools\Modules\Registrar;

final class AdminPage
{
    public const MENU_SLUG = 'abbtl-modules';

    /**
     * Fallback menu position when Bricks's actual position can't be
     * determined at registration time. We normally compute the real position
     * dynamically (see addMenu()) so we land directly below Bricks.
     */
    private const MENU_POSITION_FALLBACK = 2.99;

    private ?string $hookSuffix = null;

    public function __construct(private Registrar $registrar)
    {
    }

    public function register(): void
    {
        // Priority 11 so Bricks's default-priority (10) menu registration has
        // already populated the global $menu array by the time we run — that
        // lets us look up Bricks's *actual* position (post WP float-shim) and
        // slot ourselves directly after it.
        add_action('admin_menu', [$this, 'addMenu'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        $this->hookSuffix = add_menu_page(
            __('Bricks Tools', 'ab-bricks-tools'),
            __('Bricks Tools', 'ab-bricks-tools'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-admin-tools',
            $this->resolveMenuPosition()
        );

        // Rename the auto-created first submenu from "Bricks Tools" to "Modules".
        add_submenu_page(
            self::MENU_SLUG,
            __('Modules', 'ab-bricks-tools'),
            __('Modules', 'ab-bricks-tools'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Find Bricks's actual menu position in the global $menu array (which it
     * registered earlier at default priority 10), and return a slightly
     * higher value so we appear directly below it. WP rebuilds the menu
     * ordering with `ksort($menu, SORT_NUMERIC)` after admin_menu, so
     * fractional positions are honoured.
     */
    private function resolveMenuPosition(): float
    {
        global $menu;
        if (!is_array($menu) || empty($menu)) {
            return self::MENU_POSITION_FALLBACK;
        }

        foreach ($menu as $pos => $entry) {
            if (!is_array($entry) || ($entry[2] ?? null) !== 'bricks') {
                continue;
            }
            $candidate = (float) $pos + 0.0001;
            // Walk past any other entry that already sits in this micro-band.
            while (isset($menu[(string) $candidate])) {
                $candidate += 0.0001;
            }
            return $candidate;
        }

        return self::MENU_POSITION_FALLBACK;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== $this->hookSuffix) {
            return;
        }

        wp_enqueue_script(
            'abbtl-alpine',
            ABBTL_PLUGIN_URL . 'assets/js/alpine.min.js',
            [],
            '3.x',
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_enqueue_style(
            'abbtl-admin',
            ABBTL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ABBTL_VERSION
        );

        wp_localize_script('abbtl-alpine', 'ABBTL', [
            'restUrl' => esc_url_raw(rest_url('abbtl/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'ab-bricks-tools'));
        }

        $registrar = $this->registrar;
        require ABBTL_PLUGIN_DIR . 'templates/admin-page.php';
    }
}
