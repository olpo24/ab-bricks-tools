<?php
/**
 * WP-CLI scanner — invoked by FormFinder via `wp eval-file <thisfile>
 * --skip-plugins --skip-themes`. Writes a JSON-encoded form list to stdout.
 *
 * Skipping plugins/themes is the actual speed win — Bricks and BricksForge
 * never boot, so we just touch the database.
 *
 * MUST be self-contained: the plugin autoloader is NOT available when
 * --skip-plugins is in effect.
 *
 * No `declare(strict_types=1)` here on purpose — `wp eval-file` runs scripts
 * through `eval()` which requires the declare to be the very first statement
 * of the eval'd code; the docblock above is enough to disqualify it. The
 * scanner classes we delegate to still enforce strict types via method
 * signatures, so we lose nothing.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "wpcli-scan.php must be executed via `wp eval-file`.\n");
    exit(1);
}

require_once __DIR__ . '/FormScanner.php';

use AB\BricksTools\Modules\BricksFormManager\FormScanner;

global $wpdb;

try {
    $forms = FormScanner::scanFromWpdb($wpdb);
    echo json_encode($forms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    fwrite(STDERR, '[abbtl wpcli-scan] ' . $e->getMessage() . "\n");
    exit(1);
}
