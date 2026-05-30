<?php
/**
 * @var \AB\BricksTools\Modules\Registrar $registrar
 */
defined('ABSPATH') || exit;

$modules       = $registrar->getAll();
$initialState  = [];
$hasAdminPage  = [];
$tabbedModules = []; // enabled + implements HasAdminPage → gets its own tab

foreach ($modules as $slug => $module) {
    $enabled                = $registrar->isEnabled($slug);
    $isTabbable             = $module instanceof \AB\BricksTools\Modules\HasAdminPage;
    $initialState[$slug]    = $enabled;
    $hasAdminPage[$slug]    = $isTabbable;
    if ($enabled && $isTabbable) {
        $tabbedModules[$slug] = $module;
    }
}

$validTabs = array_merge(['modules'], array_keys($tabbedModules));

$alpineConfig = [
    'enabled'      => $initialState,
    'hasAdminPage' => $hasAdminPage,
    'validTabs'    => $validTabs,
];
?>
<?php \AB\BricksTools\Admin\Layout::open(); ?>
<div class="abbtl-admin" x-data='abbtlAdminApp(<?php echo wp_json_encode($alpineConfig); ?>)'>
    <h1><?php esc_html_e('Bricks Tools', 'ab-bricks-tools'); ?></h1>

    <nav class="nav-tab-wrapper abbtl-admin__tabs" aria-label="<?php echo esc_attr__('Plugin sections', 'ab-bricks-tools'); ?>">
        <a
            href="#modules"
            class="nav-tab"
            :class="{ 'nav-tab-active': activeTab === 'modules' }"
            @click.prevent="setTab('modules')"
        ><?php esc_html_e('Modules', 'ab-bricks-tools'); ?></a>
        <?php foreach ($tabbedModules as $slug => $module) : ?>
            <a
                href="#<?php echo esc_attr($slug); ?>"
                class="nav-tab"
                :class="{ 'nav-tab-active': activeTab === '<?php echo esc_attr($slug); ?>' }"
                @click.prevent="setTab('<?php echo esc_attr($slug); ?>')"
            ><?php echo esc_html($module->getName()); ?></a>
        <?php endforeach; ?>
    </nav>

    <section x-show="activeTab === 'modules'" x-cloak class="abbtl-admin__panel">
        <p class="description">
            <?php esc_html_e('Enable or disable modules. Each enabled module that has its own UI gets a tab above.', 'ab-bricks-tools'); ?>
        </p>

        <div class="abbtl-modules">
            <?php if (empty($modules)) : ?>
                <p><em><?php esc_html_e('No modules discovered.', 'ab-bricks-tools'); ?></em></p>
            <?php else : ?>
                <ul class="abbtl-modules__list">
                    <?php foreach ($modules as $slug => $module) : ?>
                        <li class="abbtl-modules__item">
                            <label class="abbtl-modules__toggle" :aria-label="enabled['<?php echo esc_attr($slug); ?>'] ? '<?php echo esc_attr__('Disable module', 'ab-bricks-tools'); ?>' : '<?php echo esc_attr__('Enable module', 'ab-bricks-tools'); ?>'">
                                <input
                                    class="abbtl-modules__switch-input"
                                    type="checkbox"
                                    :checked="enabled['<?php echo esc_attr($slug); ?>']"
                                    @change="toggle('<?php echo esc_attr($slug); ?>', $event.target.checked)"
                                    :disabled="pending['<?php echo esc_attr($slug); ?>']"
                                />
                                <span class="abbtl-modules__switch" aria-hidden="true"></span>
                            </label>
                            <div class="abbtl-modules__meta">
                                <h2 class="abbtl-modules__name">
                                    <?php echo esc_html($module->getName()); ?>
                                    <span class="abbtl-modules__version">v<?php echo esc_html($module->getVersion()); ?></span>
                                </h2>
                                <p class="abbtl-modules__description"><?php echo esc_html($module->getDescription()); ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <?php foreach ($tabbedModules as $slug => $module) : ?>
        <section
            x-show="activeTab === '<?php echo esc_attr($slug); ?>'"
            x-cloak
            class="abbtl-admin__panel"
        >
            <?php $module->renderAdminPage(); ?>
        </section>
    <?php endforeach; ?>

    <script>
        function abbtlAdminApp(config) {
            const validTabs = Array.isArray(config.validTabs) ? config.validTabs : ['modules'];

            const resolveInitialTab = () => {
                const fromQuery = new URLSearchParams(location.search).get('tab');
                const fromHash  = location.hash ? location.hash.replace(/^#/, '') : '';
                const requested = fromQuery || fromHash || 'modules';
                return validTabs.includes(requested) ? requested : 'modules';
            };

            return {
                // Tab navigation
                activeTab: resolveInitialTab(),
                setTab(tab) {
                    if (!validTabs.includes(tab)) tab = 'modules';
                    this.activeTab = tab;
                    const url = new URL(location.href);
                    url.searchParams.set('tab', tab);
                    history.replaceState({}, '', url);
                },

                // Module-switches state (carried over from the previous app)
                enabled: config.enabled,
                hasAdminPage: config.hasAdminPage,
                pending: {},
                async toggle(slug, value) {
                    this.pending[slug] = true;
                    try {
                        const response = await fetch(
                            ABBTL.restUrl + '/modules/' + encodeURIComponent(slug) + '/enabled',
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': ABBTL.nonce,
                                },
                                body: JSON.stringify({ enabled: value }),
                            }
                        );
                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.error || 'Request failed');
                        }
                        this.enabled[slug] = value;
                        // Reload so tabs reflect the new state (a tab appears/disappears).
                        if (this.hasAdminPage[slug]) {
                            window.location.reload();
                            return;
                        }
                    } catch (e) {
                        console.error('[ABBTL] Toggle failed:', e);
                        this.enabled[slug] = !value;
                    } finally {
                        this.pending[slug] = false;
                    }
                },
            };
        }
    </script>
</div>
<?php \AB\BricksTools\Admin\Layout::close(); ?>
