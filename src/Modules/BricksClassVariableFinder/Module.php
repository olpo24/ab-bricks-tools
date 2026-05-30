<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

use AB\BricksTools\Modules\HasAdminPage;
use AB\BricksTools\Modules\ModuleInterface;
use AB\BricksTools\System\WpCli;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Module implements ModuleInterface, HasAdminPage
{
    public const REST_ROUTE_TARGETS          = '/class-variable-finder/targets';
    public const REST_ROUTE_SCAN             = '/class-variable-finder/scan';
    public const REST_ROUTE_SAVE_LABEL       = '/class-variable-finder/element-label';
    public const REST_ROUTE_SAVE_ELEMENT_CLS = '/class-variable-finder/element-classes';
    public const REST_ROUTE_RENAME_CLASS     = '/class-variable-finder/rename-class';

    public function getSlug(): string
    {
        return 'bricks-class-variable-finder';
    }

    public function getName(): string
    {
        return __('Bricks Class & Variable Finder', 'ab-bricks-tools');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return __(
            'Find which pages and elements use a given Bricks Global Class or Global Variable.',
            'ab-bricks-tools'
        );
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('abbtl/v1', self::REST_ROUTE_TARGETS, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'restListTargets'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SCAN, [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'restScan'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'kind' => ['required' => true, 'type' => 'string'],
                'id'   => ['required' => true, 'type' => 'string'],
                'name' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SAVE_LABEL, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restSaveLabel'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'postId'    => ['required' => true, 'type' => 'integer'],
                'metaKey'   => ['required' => true, 'type' => 'string'],
                'elementId' => ['required' => true, 'type' => 'string'],
                'label'     => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_SAVE_ELEMENT_CLS, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restSaveElementClasses'],
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'postId'    => ['required' => true, 'type' => 'integer'],
                'metaKey'   => ['required' => true, 'type' => 'string'],
                'elementId' => ['required' => true, 'type' => 'string'],
                'classIds'  => ['required' => true, 'type' => 'array'],
            ],
        ]);

        register_rest_route('abbtl/v1', self::REST_ROUTE_RENAME_CLASS, [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'restRenameClass'],
            // Global rename mutates wp_options site-wide — site-admin only.
            'permission_callback' => static fn () => current_user_can('manage_options'),
            'args'                => [
                'classId' => ['required' => true, 'type' => 'string'],
                'name'    => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * Replace the `settings._cssGlobalClasses` array on a specific element.
     * Order matters — the array order is preserved on save.
     */
    public function restSaveElementClasses(WP_REST_Request $request): WP_REST_Response
    {
        $postId    = (int) $request->get_param('postId');
        $metaKey   = (string) $request->get_param('metaKey');
        $elementId = (string) $request->get_param('elementId');
        $classIds  = $request->get_param('classIds');

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid post'], 400);
        }
        if (!current_user_can('edit_post', $postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_\d+)?$/', $metaKey)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid meta key'], 400);
        }
        if (!is_array($classIds)) {
            return new WP_REST_Response(['success' => false, 'error' => 'classIds must be an array'], 400);
        }

        // Only allow string IDs that exist in the global classes catalogue —
        // arbitrary strings would corrupt the element. Build a known-good set.
        $known       = [];
        $allClasses  = get_option('bricks_global_classes', []);
        if (is_array($allClasses)) {
            foreach ($allClasses as $c) {
                if (is_array($c) && isset($c['id']) && is_string($c['id'])) {
                    $known[$c['id']] = true;
                }
            }
        }

        $sanitized = [];
        foreach ($classIds as $cid) {
            if (is_string($cid) && $cid !== '' && isset($known[$cid])) {
                $sanitized[] = $cid;
            }
        }

        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        if ($raw === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element storage not found'], 404);
        }

        $wasJsonString = self::looksLikeJsonContainer($raw);
        $elements      = maybe_unserialize($raw);
        if (is_string($elements)) {
            $decoded = json_decode($elements, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        }
        if (!is_array($elements)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unable to decode element storage'], 500);
        }

        $found = false;
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) !== $elementId) {
                continue;
            }
            $element['settings'] = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            if ($sanitized === []) {
                unset($element['settings']['_cssGlobalClasses']);
            } else {
                $element['settings']['_cssGlobalClasses'] = array_values($sanitized);
            }
            $found = true;
            break;
        }
        unset($element);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element not found in post'], 404);
        }

        if ($wasJsonString) {
            $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return new WP_REST_Response(['success' => false, 'error' => 'JSON encode failed'], 500);
            }
            update_post_meta($postId, $metaKey, wp_slash($encoded));
        } else {
            update_post_meta($postId, $metaKey, wp_slash($elements));
        }

        return new WP_REST_Response([
            'success'  => true,
            'classIds' => $sanitized,
        ]);
    }

    /**
     * Rename a global class in the `bricks_global_classes` option. Affects
     * every element on the site that references this class id — site-admin
     * capability is required.
     */
    public function restRenameClass(WP_REST_Request $request): WP_REST_Response
    {
        $classId = (string) $request->get_param('classId');
        $rawName = (string) $request->get_param('name');

        $cleaned = sanitize_text_field($rawName);
        if ($cleaned === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Class name cannot be empty'], 400);
        }
        // Valid CSS-identifier-ish: must start with letter or underscore,
        // continue with letters, digits, hyphen, or underscore.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $cleaned)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid class name format'], 400);
        }

        $classes = get_option('bricks_global_classes', []);
        if (!is_array($classes)) {
            return new WP_REST_Response(['success' => false, 'error' => 'No global classes found'], 404);
        }

        // Refuse if the new name collides with another existing class.
        foreach ($classes as $c) {
            if (is_array($c) && ($c['name'] ?? null) === $cleaned && ($c['id'] ?? null) !== $classId) {
                return new WP_REST_Response(['success' => false, 'error' => 'A class with that name already exists'], 409);
            }
        }

        $found = false;
        foreach ($classes as &$class) {
            if (!is_array($class)) {
                continue;
            }
            if (($class['id'] ?? null) !== $classId) {
                continue;
            }
            $class['name']     = $cleaned;
            $class['modified'] = time();
            $found             = true;
            break;
        }
        unset($class);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Class not found'], 404);
        }

        update_option('bricks_global_classes', $classes);

        return new WP_REST_Response([
            'success' => true,
            'classId' => $classId,
            'name'    => $cleaned,
        ]);
    }

    /**
     * Save the top-level `label` on a Bricks element (NOT a `settings[...]`
     * field) — that's where the user-editable label lives in the meta tree.
     * Empty label removes the key, matching how Bricks omits empty labels.
     */
    public function restSaveLabel(WP_REST_Request $request): WP_REST_Response
    {
        $postId    = (int) $request->get_param('postId');
        $metaKey   = (string) $request->get_param('metaKey');
        $elementId = (string) $request->get_param('elementId');
        $rawLabel  = (string) $request->get_param('label');

        if ($postId <= 0 || !get_post($postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid post'], 400);
        }
        if (!current_user_can('edit_post', $postId)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!preg_match('/^_bricks_page_(content|header|footer)(?:_\d+)?$/', $metaKey)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid meta key'], 400);
        }

        $cleaned = sanitize_text_field($rawLabel);

        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $metaKey
        ));

        if ($raw === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element storage not found'], 404);
        }

        $wasJsonString = self::looksLikeJsonContainer($raw);

        $elements = maybe_unserialize($raw);
        if (is_string($elements)) {
            $decoded = json_decode($elements, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        }
        if (!is_array($elements)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unable to decode element storage'], 500);
        }

        $found = false;
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) !== $elementId) {
                continue;
            }
            if ($cleaned === '') {
                unset($element['label']);
            } else {
                $element['label'] = $cleaned;
            }
            $found = true;
            break;
        }
        unset($element);

        if (!$found) {
            return new WP_REST_Response(['success' => false, 'error' => 'Element not found in post'], 404);
        }

        if ($wasJsonString) {
            $encoded = wp_json_encode($elements, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return new WP_REST_Response(['success' => false, 'error' => 'JSON encode failed'], 500);
            }
            update_post_meta($postId, $metaKey, wp_slash($encoded));
        } else {
            update_post_meta($postId, $metaKey, wp_slash($elements));
        }

        return new WP_REST_Response([
            'success' => true,
            'label'   => $cleaned,
        ]);
    }

    /** Duplicated intentionally from BricksFormManager\Module — keep modules self-contained. */
    private static function looksLikeJsonContainer(?string $raw): bool
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $first = ltrim($raw)[0] ?? '';
        return $first === '{' || $first === '[';
    }

    public function restListTargets(): WP_REST_Response
    {
        return new WP_REST_Response([
            'targets' => TargetCatalog::all(),
        ]);
    }

    public function restScan(WP_REST_Request $request): WP_REST_Response
    {
        $kind = (string) $request->get_param('kind');
        $id   = (string) $request->get_param('id');
        $name = (string) $request->get_param('name');

        if ($kind !== TargetCatalog::KIND_CLASS && $kind !== TargetCatalog::KIND_VARIABLE) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid kind'], 400);
        }
        if ($id === '' || $name === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'Missing id or name'], 400);
        }

        $finder = new UsageFinder();
        $usages = $finder->find(['kind' => $kind, 'id' => $id, 'name' => $name]);

        // Per-post permalink cache to avoid N lookups on the same page.
        $permalinkCache = [];
        $data = array_map(static function (Usage $u) use (&$permalinkCache): array {
            if (!array_key_exists($u->postId, $permalinkCache)) {
                $perma = get_permalink($u->postId);
                $permalinkCache[$u->postId] = is_string($perma) ? $perma : '';
            }
            $perma = $permalinkCache[$u->postId];
            $builderUrl = $perma !== ''
                ? add_query_arg(['bricks' => 'run', 'brx_element' => $u->elementId], $perma)
                : (string) (get_edit_post_link($u->postId, 'raw') ?: '');

            return [
                'postId'       => $u->postId,
                'postTitle'    => $u->postTitle,
                'postType'     => $u->postType,
                'postStatus'   => $u->postStatus,
                'metaKey'      => $u->metaKey,
                'elementId'    => $u->elementId,
                'elementName'  => $u->elementName,
                'elementLabel' => $u->elementLabel,
                'classIds'     => $u->classIds,
                'builderUrl'   => $builderUrl,
            ];
        }, $usages);

        return new WP_REST_Response([
            'usages'      => $data,
            'engine'      => $finder->lastEngine,
            'engineError' => $finder->lastEngineError,
        ]);
    }

    public function renderAdminPage(): void
    {
        $wpcli = WpCli::status();
        ?>
        <div class="abbtl-cvf">
            <h1>
                <?php echo esc_html($this->getName()); ?>
                <span style="font-size:13px;color:#646970;font-weight:normal;margin-left:8px;">
                    v<?php echo esc_html($this->getVersion()); ?>
                </span>
            </h1>
            <p class="description"><?php echo esc_html($this->getDescription()); ?></p>

            <?php $this->renderWpCliNotice($wpcli); ?>

            <div x-data="abbtlCvfApp()" x-init="loadTargets()" style="margin-top:24px;">

                <div class="abbtl-cvf__picker">
                    <div class="abbtl-cvf__picker-header">
                        <div class="abbtl-cvf__kind-filter" role="group" aria-label="<?php echo esc_attr__('Filter by kind', 'ab-bricks-tools'); ?>">
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'all' }"
                                @click="targetKindFilter = 'all'"
                            ><?php esc_html_e('All', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'class' }"
                                @click="targetKindFilter = 'class'"
                            ><?php esc_html_e('Classes', 'ab-bricks-tools'); ?></button>
                            <button
                                type="button"
                                :class="{ 'is-active': targetKindFilter === 'variable' }"
                                @click="targetKindFilter = 'variable'"
                            ><?php esc_html_e('Variables', 'ab-bricks-tools'); ?></button>
                        </div>
                        <label class="abbtl-cvf__picker-search">
                            <span><?php esc_html_e('Filter:', 'ab-bricks-tools'); ?></span>
                            <input
                                type="search"
                                x-model.debounce.150ms="targetFilter"
                                placeholder="<?php echo esc_attr__('Class or variable name…', 'ab-bricks-tools'); ?>"
                            />
                        </label>
                        <span class="abbtl-cvf__picker-count" x-text="filteredTargets.length + ' / ' + targets.length"></span>
                    </div>

                    <div class="abbtl-cvf__target-list" x-show="!loading" x-cloak>
                        <template x-for="target in filteredTargets" :key="target.kind + ':' + target.id">
                            <button
                                type="button"
                                class="abbtl-cvf__target"
                                :class="{ 'is-selected': isSelected(target) }"
                                @click="selectTarget(target)"
                            >
                                <span class="abbtl-cvf__badge" :class="'abbtl-cvf__badge--' + target.kind" x-text="target.kind === 'class' ? 'Class' : 'Variable'"></span>
                                <code x-text="target.kind === 'class' ? ('.' + target.name) : ('--' + target.name)"></code>
                            </button>
                        </template>
                        <p
                            x-show="filteredTargets.length === 0"
                            x-cloak
                            class="abbtl-cvf__picker-empty"
                        >
                            <em><?php esc_html_e('No matches.', 'ab-bricks-tools'); ?></em>
                        </p>
                    </div>

                    <p x-show="loading" x-cloak><em><?php esc_html_e('Loading classes and variables…', 'ab-bricks-tools'); ?></em></p>
                </div>

                <div class="abbtl-cvf__selection" x-show="selectedTarget" x-cloak>
                    <span class="abbtl-cvf__selection-label"><?php esc_html_e('Scanning for:', 'ab-bricks-tools'); ?></span>
                    <code class="abbtl-cvf__selection-token" x-text="selectionToken()"></code>
                    <button
                        type="button"
                        class="button"
                        @click="scan()"
                        :disabled="scanning"
                    >
                        <span x-show="!scanning"><?php esc_html_e('Re-scan', 'ab-bricks-tools'); ?></span>
                        <span x-show="scanning" x-cloak><?php esc_html_e('Scanning…', 'ab-bricks-tools'); ?></span>
                    </button>
                    <span x-show="scanned && !error" x-cloak class="abbtl-cvf__count">
                        <span x-text="usages.length + ' <?php echo esc_attr__('usages', 'ab-bricks-tools'); ?>'"></span>
                        <small style="margin-left:8px;color:#646970;">
                            <?php esc_html_e('engine:', 'ab-bricks-tools'); ?>
                            <code x-text="engine || 'unknown'"></code>
                        </small>
                    </span>
                </div>

                <details
                    x-show="scanned && engineError"
                    x-cloak
                    style="margin:8px 0;padding:8px 12px;background:#fdf6e3;border-left:3px solid #dba617;border-radius:3px;font-size:12px;"
                >
                    <summary style="cursor:pointer;color:#a86b00;">
                        <?php esc_html_e('WP-CLI was available but the scan fell back to PHP — click to see why', 'ab-bricks-tools'); ?>
                    </summary>
                    <pre x-text="JSON.stringify(engineError, null, 2)" style="margin:8px 0 0;white-space:pre-wrap;word-break:break-word;color:#5a4500;"></pre>
                </details>

                <div x-show="error" x-cloak class="notice notice-error inline">
                    <p x-text="error"></p>
                </div>

                <div x-show="scanned && !error && usages.length > 0" x-cloak>
                    <table class="wp-list-table widefat striped abbtl-cvf__table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Page', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element Label', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element Type', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Element ID', 'ab-bricks-tools'); ?></th>
                                <th scope="col"><?php esc_html_e('Classes', 'ab-bricks-tools'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="usage in pagedUsages" :key="usage.postId + '|' + usage.metaKey + '|' + usage.elementId">
                                <tr>
                                    <td>
                                        <strong x-text="usage.postTitle"></strong>
                                        <span class="abbtl-cvf__status" x-show="usage.postStatus !== 'publish'" x-cloak x-text="'(' + usage.postStatus + ')'"></span>
                                    </td>
                                    <td class="abbtl-cvf__label-cell">
                                        <span x-show="!isEditing(usage, 'elementLabel')" class="abbtl-cvf__label-display">
                                            <span
                                                class="abbtl-cvf__label-text"
                                                @dblclick="startEdit(usage, 'elementLabel')"
                                                x-text="usage.elementLabel || usage.elementName || '—'"
                                                title="<?php echo esc_attr__('Double-click to edit', 'ab-bricks-tools'); ?>"
                                            ></span>
                                            <a
                                                :href="usage.builderUrl"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="abbtl-cvf__open"
                                                title="<?php echo esc_attr__('Open element in Bricks Builder', 'ab-bricks-tools'); ?>"
                                                aria-label="<?php echo esc_attr__('Open element in Bricks Builder', 'ab-bricks-tools'); ?>"
                                            >↗</a>
                                        </span>
                                        <template x-if="isEditing(usage, 'elementLabel')">
                                            <span class="abbtl-cvf__cell-edit" :class="{ 'is-saving': editing.saving, 'is-error': editing.error }">
                                                <input
                                                    class="abbtl-cvf__edit-input"
                                                    type="text"
                                                    x-init="$el.focus(); $el.select();"
                                                    x-model="editing.value"
                                                    @blur="commitEdit()"
                                                    @keydown.enter.prevent="commitEdit()"
                                                    @keydown.escape.prevent="cancelEdit()"
                                                />
                                                <small class="abbtl-cvf__cell-status" x-show="editing.saving" x-cloak><?php esc_html_e('Saving…', 'ab-bricks-tools'); ?></small>
                                                <small class="abbtl-cvf__cell-status abbtl-cvf__cell-status--error" x-show="editing.error" x-cloak x-text="editing.error"></small>
                                            </span>
                                        </template>
                                    </td>
                                    <td><code x-text="usage.elementName || '—'"></code></td>
                                    <td><code x-text="usage.elementId"></code></td>
                                    <td class="abbtl-cvf__classes-cell">
                                        <ul class="abbtl-cvf__class-chips">
                                            <template x-for="cid in (usage.classIds || [])" :key="cid">
                                                <li class="abbtl-cvf__class-chip" x-text="'.' + (classNameById(cid) || cid)"></li>
                                            </template>
                                            <li x-show="!usage.classIds || usage.classIds.length === 0" class="abbtl-cvf__class-empty">—</li>
                                        </ul>
                                        <button
                                            type="button"
                                            class="button button-small abbtl-cvf__classes-edit"
                                            @click="openClassesModal(usage)"
                                        ><?php esc_html_e('Edit', 'ab-bricks-tools'); ?></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <div class="abbtl-cvf__pagination" x-show="totalPages > 1" x-cloak>
                        <button
                            type="button"
                            class="button"
                            @click="prevPage()"
                            :disabled="page === 1"
                        >&larr; <?php esc_html_e('Previous', 'ab-bricks-tools'); ?></button>
                        <span class="abbtl-cvf__pagination-info">
                            <?php
                            /* translators: 1: current page, 2: total pages, 3: total usages */
                            printf(
                                esc_html__('Page %1$s of %2$s — showing %3$s of', 'ab-bricks-tools'),
                                '<span x-text="page"></span>',
                                '<span x-text="totalPages"></span>',
                                '<span x-text="pagedUsages.length"></span>'
                            );
                            ?>
                            <span x-text="usages.length"></span>
                            <?php esc_html_e('total', 'ab-bricks-tools'); ?>
                        </span>
                        <button
                            type="button"
                            class="button"
                            @click="nextPage()"
                            :disabled="page === totalPages"
                        ><?php esc_html_e('Next', 'ab-bricks-tools'); ?> &rarr;</button>
                    </div>
                </div>

                <p x-show="scanned && !error && usages.length === 0" x-cloak style="margin-top:12px;">
                    <em><?php esc_html_e('No usages found for this target.', 'ab-bricks-tools'); ?></em>
                </p>

                <div
                    x-show="classesModal.open"
                    x-cloak
                    class="abbtl-cvf__modal-overlay"
                    @click.self="closeClassesModal()"
                    @keydown.escape.window="if (classesModal.open) closeClassesModal()"
                >
                    <div class="abbtl-cvf__modal" role="dialog" aria-modal="true" aria-labelledby="abbtl-cvf-modal-title">
                        <header class="abbtl-cvf__modal-header">
                            <div>
                                <h2 id="abbtl-cvf-modal-title"><?php esc_html_e('Edit Classes', 'ab-bricks-tools'); ?></h2>
                                <p class="abbtl-cvf__modal-subtitle" x-text="classesModal.usage ? (classesModal.usage.postTitle + ' · ' + (classesModal.usage.elementLabel || classesModal.usage.elementName)) : ''"></p>
                            </div>
                            <button type="button" class="abbtl-cvf__modal-close" @click="closeClassesModal()" aria-label="<?php echo esc_attr__('Close', 'ab-bricks-tools'); ?>">&times;</button>
                        </header>

                        <section class="abbtl-cvf__modal-body">
                            <p class="abbtl-cvf__modal-warning">
                                <strong><?php esc_html_e('Heads up:', 'ab-bricks-tools'); ?></strong>
                                <?php esc_html_e('Renaming a class applies globally — every element using that class will be updated.', 'ab-bricks-tools'); ?>
                            </p>

                            <h3 class="abbtl-cvf__modal-section-heading"><?php esc_html_e('Classes on this element', 'ab-bricks-tools'); ?></h3>
                            <ol class="abbtl-cvf__modal-class-list">
                                <template x-for="(cls, idx) in classesModal.classes" :key="cls.id">
                                    <li
                                        class="abbtl-cvf__modal-class-row"
                                        :class="{ 'is-dragging': classesModal.dragIndex === idx }"
                                        draggable="true"
                                        @dragstart="onClassDragStart($event, idx)"
                                        @dragover.prevent
                                        @drop.prevent="onClassDrop($event, idx)"
                                        @dragend="onClassDragEnd"
                                    >
                                        <span class="abbtl-cvf__modal-drag-handle" aria-hidden="true" title="<?php echo esc_attr__('Drag to reorder', 'ab-bricks-tools'); ?>">⋮⋮</span>
                                        <span
                                            class="abbtl-cvf__modal-class-name"
                                            x-show="!isClassEditing(cls.id)"
                                            @dblclick="startClassRename(cls)"
                                            x-text="'.' + cls.name"
                                            title="<?php echo esc_attr__('Double-click to rename (global)', 'ab-bricks-tools'); ?>"
                                        ></span>
                                        <template x-if="isClassEditing(cls.id)">
                                            <input
                                                class="abbtl-cvf__modal-class-input"
                                                type="text"
                                                x-init="$el.focus(); $el.select();"
                                                x-model="classesModal.renameValue"
                                                @blur="commitClassRename()"
                                                @keydown.enter.prevent="commitClassRename()"
                                                @keydown.escape.prevent="cancelClassRename()"
                                            />
                                        </template>
                                        <button
                                            type="button"
                                            class="abbtl-cvf__modal-remove"
                                            @click="removeClassFromElement(idx)"
                                            aria-label="<?php echo esc_attr__('Remove class', 'ab-bricks-tools'); ?>"
                                            title="<?php echo esc_attr__('Remove from this element', 'ab-bricks-tools'); ?>"
                                        >&times;</button>
                                    </li>
                                </template>
                                <li x-show="classesModal.classes.length === 0" x-cloak class="abbtl-cvf__modal-empty">
                                    <em><?php esc_html_e('No classes on this element.', 'ab-bricks-tools'); ?></em>
                                </li>
                            </ol>

                            <h3 class="abbtl-cvf__modal-section-heading"><?php esc_html_e('Add a class', 'ab-bricks-tools'); ?></h3>
                            <div
                                class="abbtl-cvf__combobox"
                                @click.outside="classesModal.addOpen = false"
                            >
                                <input
                                    type="text"
                                    class="abbtl-cvf__combobox-input"
                                    x-model="classesModal.addFilter"
                                    @focus="onAddComboFocus()"
                                    @keydown="onAddComboKeydown($event)"
                                    placeholder="<?php echo esc_attr__('Type to filter classes…', 'ab-bricks-tools'); ?>"
                                    autocomplete="off"
                                    aria-autocomplete="list"
                                    role="combobox"
                                    :aria-expanded="classesModal.addOpen.toString()"
                                />
                                <ul
                                    x-show="classesModal.addOpen && filteredAvailableClasses.length > 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-list"
                                    role="listbox"
                                >
                                    <template x-for="(t, idx) in filteredAvailableClasses" :key="t.id">
                                        <li
                                            class="abbtl-cvf__combobox-option"
                                            :class="{ 'is-highlighted': idx === classesModal.addHighlight }"
                                            @mousedown.prevent="pickClassFromCombobox(t.id)"
                                            @mouseenter="classesModal.addHighlight = idx"
                                            role="option"
                                            :aria-selected="(idx === classesModal.addHighlight).toString()"
                                            x-text="'.' + t.name"
                                        ></li>
                                    </template>
                                </ul>
                                <p
                                    x-show="classesModal.addOpen && filteredAvailableClasses.length === 0 && availableClassesToAdd.length > 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-empty"
                                ><em><?php esc_html_e('No matching classes.', 'ab-bricks-tools'); ?></em></p>
                                <p
                                    x-show="availableClassesToAdd.length === 0"
                                    x-cloak
                                    class="abbtl-cvf__combobox-empty"
                                ><em><?php esc_html_e('All global classes are already on this element.', 'ab-bricks-tools'); ?></em></p>
                            </div>

                            <p x-show="classesModal.error" x-cloak class="abbtl-cvf__modal-error" x-text="classesModal.error"></p>
                        </section>

                        <footer class="abbtl-cvf__modal-footer">
                            <button type="button" class="button button-primary" @click="closeClassesModal()"><?php esc_html_e('Done', 'ab-bricks-tools'); ?></button>
                        </footer>
                    </div>
                </div>
            </div>

            <script>
                function abbtlCvfApp() {
                    return {
                        targets: [],
                        targetFilter: '',
                        targetKindFilter: 'all',
                        selectedTarget: null,
                        loading: false,

                        usages: [],
                        engine: '',
                        engineError: null,
                        scanning: false,
                        scanned: false,
                        error: '',

                        page: 1,
                        perPage: 100,

                        editing: null,

                        classesModal: {
                            open: false,
                            usage: null,
                            classes: [],
                            dragIndex: -1,
                            // Filterable combobox state (the old <select> replacement)
                            addFilter: '',
                            addOpen: false,
                            addHighlight: 0,
                            renameClassId: null,
                            renameValue: '',
                            error: '',
                        },

                        classNameById(id) {
                            const t = (this.targets || []).find(x => x.kind === 'class' && x.id === id);
                            return t ? t.name : null;
                        },

                        classById(id) {
                            return (this.targets || []).find(x => x.kind === 'class' && x.id === id) || null;
                        },

                        get availableClassesToAdd() {
                            const onElement = new Set((this.classesModal.classes || []).map(c => c.id));
                            return (this.targets || []).filter(t => t.kind === 'class' && !onElement.has(t.id));
                        },

                        openClassesModal(usage) {
                            this.classesModal.usage = usage;
                            this.classesModal.classes = (usage.classIds || [])
                                .map(id => this.classById(id))
                                .filter(Boolean)
                                .map(c => ({ id: c.id, name: c.name }));
                            this.classesModal.dragIndex = -1;
                            this.classesModal.addFilter = '';
                            this.classesModal.addOpen = false;
                            this.classesModal.addHighlight = 0;
                            this.classesModal.renameClassId = null;
                            this.classesModal.renameValue = '';
                            this.classesModal.error = '';
                            this.classesModal.open = true;
                        },

                        get filteredAvailableClasses() {
                            const list = this.availableClassesToAdd;
                            const needle = (this.classesModal.addFilter || '').trim().toLowerCase();
                            if (!needle) return list;
                            return list.filter(t => t.name.toLowerCase().includes(needle));
                        },

                        onAddComboFocus() {
                            this.classesModal.addOpen = true;
                            // Reset highlight to first match every time the dropdown opens.
                            this.classesModal.addHighlight = 0;
                        },

                        onAddComboKeydown(event) {
                            const list = this.filteredAvailableClasses;
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                this.classesModal.addOpen = true;
                                if (list.length > 0) {
                                    this.classesModal.addHighlight = Math.min(
                                        this.classesModal.addHighlight + 1,
                                        list.length - 1
                                    );
                                }
                            } else if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                this.classesModal.addHighlight = Math.max(this.classesModal.addHighlight - 1, 0);
                            } else if (event.key === 'Enter') {
                                event.preventDefault();
                                const sel = list[this.classesModal.addHighlight];
                                if (sel) this.pickClassFromCombobox(sel.id);
                            } else if (event.key === 'Escape') {
                                event.preventDefault();
                                this.classesModal.addOpen = false;
                                this.classesModal.addFilter = '';
                                this.classesModal.addHighlight = 0;
                            } else {
                                // Any printable key keeps the dropdown open and resets highlight to top.
                                this.classesModal.addOpen = true;
                                this.classesModal.addHighlight = 0;
                            }
                        },

                        async pickClassFromCombobox(classId) {
                            this.classesModal.addFilter = '';
                            this.classesModal.addOpen = false;
                            this.classesModal.addHighlight = 0;
                            await this.addClassToElement(classId);
                        },

                        closeClassesModal() {
                            this.classesModal.open = false;
                            this.classesModal.usage = null;
                        },

                        async persistElementClasses() {
                            const usage = this.classesModal.usage;
                            if (!usage) return;
                            const classIds = this.classesModal.classes.map(c => c.id);
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SAVE_ELEMENT_CLS); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({
                                            postId: usage.postId,
                                            metaKey: usage.metaKey,
                                            elementId: usage.elementId,
                                            classIds: classIds,
                                        }),
                                    }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success) throw new Error(data.error || 'Save failed');
                                // Mutate the original usage object so table chips re-render.
                                usage.classIds = Array.isArray(data.classIds) ? data.classIds : classIds;
                                this.classesModal.error = '';
                            } catch (e) {
                                console.error('[ABBTL CVF] persistElementClasses failed:', e);
                                this.classesModal.error = e.message || 'Save failed';
                            }
                        },

                        async addClassToElement(classId) {
                            if (!classId) return;
                            const cls = this.classById(classId);
                            if (!cls) return;
                            if (this.classesModal.classes.some(c => c.id === cls.id)) return;
                            this.classesModal.classes.push({ id: cls.id, name: cls.name });
                            await this.persistElementClasses();
                        },

                        async removeClassFromElement(idx) {
                            this.classesModal.classes.splice(idx, 1);
                            await this.persistElementClasses();
                        },

                        onClassDragStart(event, idx) {
                            this.classesModal.dragIndex = idx;
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(idx));
                        },

                        async onClassDrop(event, dropIdx) {
                            const from = this.classesModal.dragIndex;
                            this.classesModal.dragIndex = -1;
                            if (from < 0 || from === dropIdx) return;
                            const items = [...this.classesModal.classes];
                            const [moved] = items.splice(from, 1);
                            items.splice(dropIdx, 0, moved);
                            this.classesModal.classes = items;
                            await this.persistElementClasses();
                        },

                        onClassDragEnd() {
                            this.classesModal.dragIndex = -1;
                        },

                        isClassEditing(classId) {
                            return this.classesModal.renameClassId === classId;
                        },

                        startClassRename(cls) {
                            this.classesModal.renameClassId = cls.id;
                            this.classesModal.renameValue = cls.name;
                            this.classesModal.error = '';
                        },

                        cancelClassRename() {
                            this.classesModal.renameClassId = null;
                            this.classesModal.renameValue = '';
                        },

                        async commitClassRename() {
                            const id = this.classesModal.renameClassId;
                            if (!id) return;
                            const newName = (this.classesModal.renameValue || '').trim();
                            const current = this.classesModal.classes.find(c => c.id === id);
                            if (!current || newName === '' || newName === current.name) {
                                this.cancelClassRename();
                                return;
                            }
                            try {
                                const r = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_RENAME_CLASS); ?>',
                                    {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ABBTL.nonce },
                                        body: JSON.stringify({ classId: id, name: newName }),
                                    }
                                );
                                const data = await r.json();
                                if (!r.ok || !data.success) throw new Error(data.error || 'Rename failed');
                                const saved = data.name;
                                current.name = saved;
                                // Update the catalogue entry so every row's chips re-render.
                                const catalogEntry = this.targets.find(t => t.kind === 'class' && t.id === id);
                                if (catalogEntry) catalogEntry.name = saved;
                                this.classesModal.error = '';
                            } catch (e) {
                                console.error('[ABBTL CVF] rename failed:', e);
                                this.classesModal.error = e.message || 'Rename failed';
                            }
                            this.cancelClassRename();
                        },

                        usageKey(usage) {
                            return usage.postId + '|' + usage.metaKey + '|' + usage.elementId;
                        },

                        isEditing(usage, field) {
                            return this.editing
                                && this.editing.key === this.usageKey(usage)
                                && this.editing.field === field;
                        },

                        startEdit(usage, field) {
                            if (this.editing) return;
                            this.editing = {
                                key: this.usageKey(usage),
                                usage: usage,
                                field: field,
                                value: usage[field] == null ? '' : String(usage[field]),
                                saving: false,
                                error: '',
                            };
                        },

                        cancelEdit() {
                            this.editing = null;
                        },

                        async commitEdit() {
                            if (!this.editing) return;
                            const ctx = this.editing;
                            const original = ctx.usage[ctx.field] == null ? '' : String(ctx.usage[ctx.field]);
                            if (ctx.value === original) {
                                this.editing = null;
                                return;
                            }
                            ctx.saving = true;
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SAVE_LABEL); ?>',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-WP-Nonce': ABBTL.nonce,
                                        },
                                        body: JSON.stringify({
                                            postId: ctx.usage.postId,
                                            metaKey: ctx.usage.metaKey,
                                            elementId: ctx.usage.elementId,
                                            label: ctx.value,
                                        }),
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok || !data.success) {
                                    throw new Error(data.error || 'Save failed');
                                }
                                // Empty label → null so the display falls back to elementName.
                                ctx.usage.elementLabel = (data.label === '' || data.label == null) ? null : data.label;
                                this.editing = null;
                            } catch (e) {
                                console.error('[ABBTL CVF] Save failed:', e);
                                ctx.error = e.message || 'Unknown error';
                                ctx.saving = false;
                            }
                        },

                        async loadTargets() {
                            this.loading = true;
                            this.error = '';
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_TARGETS); ?>',
                                    { method: 'GET', headers: { 'X-WP-Nonce': ABBTL.nonce } }
                                );
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || 'Failed to load targets');
                                this.targets = Array.isArray(data.targets) ? data.targets : [];
                            } catch (e) {
                                console.error('[ABBTL CVF] loadTargets failed:', e);
                                this.error = e.message || 'Unknown error';
                            } finally {
                                this.loading = false;
                            }
                        },

                        get filteredTargets() {
                            const needle = (this.targetFilter || '').trim().toLowerCase();
                            return this.targets.filter(t => {
                                if (this.targetKindFilter !== 'all' && t.kind !== this.targetKindFilter) return false;
                                if (!needle) return true;
                                return t.name.toLowerCase().includes(needle)
                                    || (t.value || '').toLowerCase().includes(needle);
                            });
                        },

                        isSelected(target) {
                            return this.selectedTarget
                                && this.selectedTarget.kind === target.kind
                                && this.selectedTarget.id === target.id;
                        },

                        selectionToken() {
                            if (!this.selectedTarget) return '';
                            return this.selectedTarget.kind === 'class'
                                ? '.' + this.selectedTarget.name
                                : 'var(--' + this.selectedTarget.name + ')';
                        },

                        selectTarget(target) {
                            this.selectedTarget = target;
                            this.scan();
                        },

                        async scan() {
                            if (!this.selectedTarget) return;
                            this.scanning = true;
                            this.error = '';
                            this.page = 1;
                            try {
                                const response = await fetch(
                                    ABBTL.restUrl + '<?php echo esc_js(self::REST_ROUTE_SCAN); ?>',
                                    {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-WP-Nonce': ABBTL.nonce,
                                        },
                                        body: JSON.stringify({
                                            kind: this.selectedTarget.kind,
                                            id: this.selectedTarget.id,
                                            name: this.selectedTarget.name,
                                        }),
                                    }
                                );
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || data.error || 'Scan failed');
                                this.usages = Array.isArray(data.usages) ? data.usages : [];
                                this.engine = data.engine || '';
                                this.engineError = data.engineError || null;
                                this.scanned = true;
                            } catch (e) {
                                console.error('[ABBTL CVF] Scan failed:', e);
                                this.error = e.message || 'Unknown error';
                            } finally {
                                this.scanning = false;
                            }
                        },

                        get totalPages() {
                            return Math.max(1, Math.ceil(this.usages.length / this.perPage));
                        },

                        get pagedUsages() {
                            const start = (this.page - 1) * this.perPage;
                            return this.usages.slice(start, start + this.perPage);
                        },

                        nextPage() { if (this.page < this.totalPages) this.page++; },
                        prevPage() { if (this.page > 1) this.page--; },
                    };
                }
            </script>
        </div>
        <?php
    }

    /**
     * @param array{available: bool, version: ?string, reason: ?string} $wpcli
     */
    private function renderWpCliNotice(array $wpcli): void
    {
        if ($wpcli['available']) {
            $versionSuffix = !empty($wpcli['version']) ? ' (' . $wpcli['version'] . ')' : '';
            ?>
            <div class="notice notice-success inline" style="margin-top:16px;">
                <p>
                    <strong><?php esc_html_e('WP-CLI access confirmed', 'ab-bricks-tools'); ?><?php echo esc_html($versionSuffix); ?></strong>
                    — <?php esc_html_e('where possible this will be used (Fastest).', 'ab-bricks-tools'); ?>
                </p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-warning inline" style="margin-top:16px;">
            <p>
                <strong><?php esc_html_e('WP-CLI is not available', 'ab-bricks-tools'); ?></strong>
                — <?php esc_html_e('all operations will be performed with PHP (Slower).', 'ab-bricks-tools'); ?>
                <?php if (!empty($wpcli['reason'])) : ?>
                    <br>
                    <small style="color:#646970;"><?php echo esc_html($wpcli['reason']); ?></small>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
