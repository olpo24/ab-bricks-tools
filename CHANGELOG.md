# Changelog

All notable changes to this project will be documented in this file.
The format is loosely based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.0.3] - 2026-05-31

### Added

- **Tabbed admin shell.** The Modules page now hosts a `nav-tab-wrapper`:
  the existing enable/disable switch grid lives on the first tab, and each
  enabled `HasAdminPage` module renders inline on its own tab. Per-module
  WP submenus are gone — everything is one page. Tab state is reflected in
  the URL via `?tab=<slug>` (`history.replaceState`, no full reloads on
  tab switches).
- **Theme guard.** The plugin self-deactivates with an admin notice if
  Bricks isn't the active theme (or a Bricks child theme). The
  `Plugin activated` flash from the same request is suppressed.
- **Programmatic menu position.** `AdminPage` now hooks `admin_menu` at
  priority 11, looks up Bricks's actual position in the global `$menu`
  array (after WP's md5-derived collision shim has placed it), and slots
  us at `bricks_position + 0.0001`. Replaces the static 2.5 / 2.99 guess
  that lost the race on some installs.
- **Bricks Form Manager — Redirect URL column.** Editable inline cell
  when the form's `actions` setting contains `redirect`; renders a
  non-editable "NA" otherwise. Save endpoint accepts `redirect` as a
  whitelisted field.
- **Bricks Form Manager — edit-hint tip box** under the results table
  reminding users to double-click any value to edit.
- **Bricks Class & Variable Finder — editable Element Label.** New
  `POST /class-variable-finder/element-label` endpoint writes
  `$element['label']` (not `settings[…]`). Empty value `unset()`s the
  key, matching how Bricks treats unset labels. The "open in builder"
  link became a small `↗` icon next to the editable text so single-click
  doesn't conflict with double-click-to-edit.
- **Bricks Class & Variable Finder — Classes column.** Each result row
  shows the element's global classes as cyan chips, plus an Edit button.
- **Bricks Class & Variable Finder — Edit Classes modal.** Drag-to-
  reorder, remove, add via filterable Select2-style combobox (type to
  filter, ↑/↓ keyboard nav, Enter to pick, Esc to cancel), and
  double-click any class to rename it **globally**. Live persistence on
  every action via two new endpoints:
    - `POST /class-variable-finder/element-classes` — writes the
      reordered/filtered `_cssGlobalClasses` array; server-validates
      every class id against the live catalogue.
    - `POST /class-variable-finder/rename-class` — rewrites the entry's
      `name` in `bricks_global_classes`; regex-validated CSS-identifier
      format, collision detection (409 on duplicate name).
- **Engine failure surfacing.** Both dispatchers populate
  `lastEngineError` (`{stage, exitCode?, stderr?, stdout?, cmd?}`) when
  the WP-CLI subprocess can't complete. REST responses include it as
  `engineError`; the UIs render an amber details disclosure. `cmd` is
  only included when `WP_DEBUG` is true.
- **`bypass_shell` on Windows** for the WP-CLI subprocess. The
  multi-double-quoted Local-bundled command can confuse `cmd /S /C`;
  `CreateProcess` is now invoked directly.
- **BRXProd advert column** moved from per-module pages to wrap the
  whole tabbed UI once (Layout open/close now happen at the host
  template, not inside each module's `renderAdminPage`).

### Changed

- Modules list now lays out as a CSS grid (`repeat(auto-fill,
  minmax(300px, 1fr))`) instead of a single column.
- Per-module `renderAdminPage()` methods no longer wrap themselves in
  `Layout::open()/close()`. The single host wrapper handles it.
- `WpCli::normalizePath()` now collapses runs of forward slashes,
  eliminating cosmetic `C://Users//Alan.Blair/...` paths caused by
  PHP-FPM occasionally returning `$_SERVER['USERPROFILE']` with double
  backslashes.

### Fixed

- **WP-CLI scan dispatch under Local by Flywheel.** Both `wpcli-scan.php`
  files declared `strict_types=1` at the top. `wp eval-file` runs the
  target through PHP's `eval()`, which fatals on `declare(strict_types)`
  when anything precedes it — the docblocks above the declares pushed
  them past the first-statement check, WordPress fataled during boot,
  and the dispatcher silently fell back to PHP. Strict types stay
  enforced in the scanner classes the wrappers delegate to.

## [0.0.2] - 2026-05-30

### Added

- GitHub-based auto-update via `yahnis-elsts/plugin-update-checker` v5.7. The
  plugin queries this public repo for new releases and prefers the attached
  release-asset zip over GitHub's auto-generated source tarball.

## [0.0.1] - 2026-05-30

### Added

- Plugin scaffold with Composer PSR-4 autoloading and committed `vendor/`.
- Module Registrar: auto-discovers modules in `src/Modules/{PascalCaseFolder}/`,
  stores enable/disable state in a single `abbtl_modules` option.
- Optional `HasAdminPage` interface for modules that own a WP Admin submenu.
- Two-column admin shell with BRXProd advert panel using on-brand colors.
- WP-CLI runtime detection: probes system `wp` first, then Local-by-Flywheel's
  bundled `wp-cli.phar` + newest `lightning-services/php-*` paired with the
  current site's rendered `php.ini`.
- **Bricks Form Manager** module — finds every Bricks Core Form and BricksForge
  Pro Form across pages, posts, and Bricks templates; inline-edit From, To, CC,
  Reply-To, Subject, Success Message, and Error Message; filter by type, search
  by email address.
- **Bricks Class & Variable Finder** module — filterable picker over Bricks
  Global Classes and Variables; paginated usage table (100/page) with deep
  links into the Builder via `?bricks=run&brx_element=`.
