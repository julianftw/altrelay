# Contributing to AltRelay – Alt Text Everywhere

Thank you for your interest in contributing to AltRelay! This guide covers the local setup, the quality gates every change has to pass, and the release process.

## Prerequisites

- PHP 8.2+
- Composer 2.x
- Node.js 24.x (see `.node-version`); the linters need at least 20.19 or 22.13 (`engines` in `package.json`)
- WP-CLI, only for `bin/check-pot.sh` (or point `WP_CLI_BIN` at another `wp` command)
- A local WordPress installation (6.7+), for example [WordPress Studio](https://developer.wordpress.com/studio/) or `npm run env:start` (Docker)

## Setup

1. Clone the repository into your WordPress plugins directory:

   ```bash
   cd /path/to/wordpress/wp-content/plugins
   git clone https://github.com/julianftw/altrelay.git
   cd altrelay
   ```

2. Install dependencies:

   ```bash
   composer install
   npm ci
   ```

3. Activate the plugin:

   ```bash
   wp plugin activate altrelay
   ```

## Development Commands

| Command                     | Description                                              |
| --------------------------- | -------------------------------------------------------- |
| `composer lint`             | PHPCS (WordPress Coding Standards, PHPCompatibilityWP)   |
| `composer lint:fix`         | Auto-fix PHPCS violations with PHPCBF                    |
| `composer analyse`          | PHPStan, level 10                                        |
| `composer test`             | PHPUnit unit suite                                       |
| `composer test:coverage`    | Unit suite with coverage (needs PCOV or Xdebug)          |
| `composer check`            | lint + analyse + test                                    |
| `npm run lint:js`           | ESLint                                                   |
| `npm run lint:css`          | Stylelint                                                |
| `npm test`                  | Jest                                                     |
| `npm run test:coverage`     | Jest with enforced coverage thresholds                   |
| `npm run test:e2e`          | Playwright, against a running WordPress                  |
| `bash bin/check-pot.sh`     | Fail if the translation template is out of date          |
| `bash build-zip.sh`         | Build `dist/altrelay.zip` (`composer dist` on Windows)    |
| `bash bin/verify-dist.sh`   | Fail if the ZIP contains anything but runtime files      |

All of these pass on a clean checkout. If one fails, it is a real regression — please fix it rather than skipping the check. The integration and end-to-end suites need a running WordPress; see [Testing](#testing).

## Quality Gates

Every pull request runs, and must pass:

- **PHPCS** with the WordPress ruleset (`phpcs.xml.dist`)
- **PHPStan** at level 10 (`phpstan.neon`)
- **PHPUnit** on PHP 8.2, 8.3, 8.4 and 8.5, with line coverage of at least 85% (`bin/check-coverage.php`)
- **Integration tests** against real WordPress 6.7 and the latest release, on a single site and on multisite, each with and without Action Scheduler. A run against the WordPress nightly build reports what is coming without blocking a merge. One more run uses MySQL 8 instead of MariaDB.
- **SQLite smoke test** in WordPress Playground: every query AltRelay builds by hand, once, on the SQLite driver (`tests/sqlite/`). `wp eval-file tests/sqlite/smoke.php` runs it on a local SQLite site too, and removes what it created.
- **End-to-end tests** in a real block editor, on WordPress 6.7 and the latest release on WP-Cron with a block theme, and on the latest release with Action Scheduler and a classic theme (which has a widgets screen), and on a multisite network: on its second site as that site's administrator, followed by network deactivation and uninstall checked on both sites
- **ESLint**, **Stylelint** and **Jest**, with coverage thresholds of 95% statements, 85% branches and 100% functions
- **The translation template** matches the source (`bin/check-pot.sh`)
- **WordPress Plugin Check** on the built ZIP, plus the runtime-files allowlist

Third-party GitHub Actions are pinned to commit SHAs. Dependabot opens weekly grouped updates for actions, Composer and npm. The PHPUnit 9.6 PHAR and the Action Scheduler release the CI jobs download are pinned by version and SHA-256 in `ci.yml`; Dependabot cannot see them, so update both values by hand when a new release is out.

`package.json` pins `glob` and `minimatch` through `overrides`. Jest 30 still depends on versions with known security advisories (GHSA-3g3m-2fjr-6pw4 for `glob`, GHSA-g3hv-75wg-m249 for `minimatch`), and newer versions of both work with it. They are only used by the test tools, never shipped in the plugin. Remove the overrides once a Jest release requires the fixed versions itself; `npm audit` then still reports 0 vulnerabilities.

## Architecture

```
altrelay.php                          Bootstrap, requirements guard, lifecycle hooks, editor assets
├── includes/
│   ├── class-altrelay-plugin.php     The one instance of each component
│   ├── class-altrelay-content.php    Markup rules: file check, decorative marks, links, Cover blocks
│   ├── class-altrelay-sync.php       Sync engine: finding posts, permissions, kses-safe saves
│   ├── class-altrelay-queue.php      Background batches on Action Scheduler or WP-Cron
│   ├── class-altrelay-report.php     Coverage numbers, usage counts, review lists, their caches
│   ├── class-altrelay-frontend.php   render_block fallback
│   ├── class-altrelay-admin.php      Media → AltRelay dashboard and its form handlers
│   └── class-altrelay-cli.php        WP-CLI commands
├── assets/
│   ├── js/editor-sync.js            Block editor pull/push and decorative toggle
│   └── css/admin.css                Dashboard styles
├── languages/altrelay.pot            Translation template
└── uninstall.php                    Clean removal
```

Rules that keep the plugin consistent:

- **Every read or write of image markup goes through `AltRelay_Content`**, so the engine, the frontend, the dashboard and WP-CLI apply the same rules: whether a file belongs to an attachment, what counts as decorative, when an image is the only content of a link, and how a Cover block's comment follows its markup. `editor-sync.js` repeats the file rule (`mediaMatchesUrl`); change both together.
- **Post content is read with `AltRelay_Sync::raw_content()`**, never with a bare `get_post_field()`, whose default context runs display filters over it.
- **A background job carries no alt text.** It is `[ attachment ID, cursor, user ID ]`, and the worker reads the alt text when it runs. On WP-Cron the jobs live in the non-autoloaded `altrelay_queue` option and are only changed under the queue lock (`AltRelay_Queue::cron_change()`); never schedule a cron event per job.
- **A save leaves every save filter hooked, and hands its own fields back unchanged** (`AltRelay_Sync::save_post_content()`): each field is noted at `PHP_INT_MIN` on `pre_post_{field}` and put back at `PHP_INT_MAX` on `{field}_save_pre`, only when it is exactly the value being saved. Never unhook kses or any other filter. If WordPress starts changing another post field on save, add it to `SAVED_FIELDS`.
- **Queries behind the dashboard live in `AltRelay_Report`** and are cached in transients that are dropped by hooks, not by time alone.

## Testing

**Unit tests** (`tests/unit/`) use WordPress function stubs (`tests/stubs/`), so they run without a WordPress install. HTML rewriting is tested against WordPress's own `WP_HTML_Tag_Processor`: unmodified copies of the WordPress 6.7 HTML API live in `tests/fixtures/html-api/`. The fixtures use throwaway file names, so matching a file to its attachment is opt-in per test (`strict_image_match`).

Stubs have a structural blind spot: they encode what we *believe* WordPress does. Several real bugs (a batching off-by-one, Action Scheduler supersede never matching, an unscoped cancel, WP-Cron events that `wp_clear_scheduled_hook()` silently kept because they had arguments, `get_post_field()` filtering content for display) passed the entire stub suite and were only found against a real install. Anything that touches WordPress internals belongs in the integration suite too.

**Integration tests** (`tests/integration/`) run against a real WordPress install with real Action Scheduler and a real database. Among other things they check that every block AltRelay writes round-trips through core's `parse_blocks()` and `serialize_blocks()` byte for byte, that the kses exemption covers the synced post and nothing else, and that the review queries find what they should.

```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib \
ALTRELAY_ACTION_SCHEDULER_DIR=/path/to/action-scheduler \
  php /path/to/phpunit-9.6.phar -c phpunit-integration.xml.dist
```

The WordPress test library is not compatible with PHPUnit 10+, so this suite runs on the PHPUnit 9.6 PHAR rather than `vendor/bin/phpunit`. `ALTRELAY_ACTION_SCHEDULER_DIR` is optional; the Action Scheduler tests skip themselves when it is unset. Add `WP_MULTISITE=1` to run on a network. CI runs the suite twice per configuration, once with Action Scheduler and once on the WP-Cron fallback.

**End-to-end tests** (`tests/e2e/`) drive a real block editor with Playwright. They are the only tests that can tell whether the editor accepts the markup AltRelay writes, and whether a fill leaves a post unchanged in the editor's eyes. They create what they need through the REST API and delete it again.

```bash
npm run env:start                       # WordPress at http://localhost:8888 (Docker)
npx playwright install chromium         # once
npm run test:e2e
```

On wp-env, a test-only must-use plugin (`tests/e2e/mu-plugins/`) lets the tests run AltRelay's background queue directly instead of waiting for WP-Cron, whose loopback request does not reach the container. It is mapped by `.wp-env.json` and never shipped. To run them against another site, set `WP_BASE_URL`, `WP_USERNAME` and `WP_PASSWORD`, and `WP_LOGIN_PATH` when a security plugin has moved the login form. `tests/e2e/multisite.spec.js` runs only with `WP_MULTISITE=1`, against a site of a network that is not the main one, as its administrator, with `WP_SUPER_USERNAME` and `WP_SUPER_PASSWORD` for a Super Admin; CI shows how to set such a network up with wp-env. `WP_ENV_CORE=WordPress/WordPress#6.7 npm run env:start` starts an older WordPress. Run them on a version below 7.1 and on a current one before a release: WordPress 7.1 gave the Image block its own "Mark as decorative" setting, which AltRelay uses, while older versions get AltRelay's "Decorative image" toggle, and the suite takes whichever path the site offers.

On WordPress or PHP versions below the minimum, the plugin must stay dormant and show an admin notice instead of loading (`altrelay_meets_requirements()`). That needs a manual check on an old install when the guard changes.

**JavaScript tests** (`tests/js/`) load `editor-sync.js` into a mocked `wp.*` environment that models separate data stores with named subscriptions, the save lifecycles of the post and widgets editors, the notices store, the media REST endpoint and the `editor.BlockEdit` filter.

## Coding Standards

- Follow the WordPress PHP Coding Standards; tabs in PHP and CSS, 2 spaces in JS.
- All public functions, methods, constants and hooks have PHPDoc blocks with a `@since` tag. A unit test checks this.
- All translatable strings use the `altrelay` text domain, with `translators:` comments for placeholders. A string is a whole sentence: never assemble one from fragments, because other languages order words differently.
- CSS uses logical properties (`margin-inline-start`, `inline-size`), so the dashboard mirrors in right-to-left languages. Stylelint rejects the physical ones.
- New filters and actions are documented in README.md and readme.txt.

## Releasing

1. Set the new version in all five places the release workflow checks: the `Version:` header and `ALTRELAY_VERSION` in `altrelay.php`, `Stable tag` in `readme.txt`, `version` in `package.json`, and `ALTRELAY_VERSION` in `phpstan-bootstrap.php`. New functions, constants and hooks get the new version as their `@since`.
2. Add a `= x.y.z =` entry to the changelog in `readme.txt` (the workflow fails without one).
3. Regenerate the translation template: `wp i18n make-pot . languages/altrelay.pot --domain=altrelay --exclude=node_modules,vendor,tests,dist,build`. CI fails when it is out of date.
4. Run `composer check && npm run lint:js && npm run lint:css && npm run test:coverage`, and the end-to-end suite on both sides of WordPress 7.1.
5. Commit, then push a `vx.y.z` tag. The release workflow verifies the versions, runs every check, builds and verifies the ZIP, runs Plugin Check, publishes the GitHub release and, when `WPORG_DEPLOY_ENABLED` is set, deploys that same build to WordPress.org. Listing artwork, screenshots and the Live Preview blueprint for WordPress.org live in `.wordpress-org/`.

**Raising the minimum WordPress or PHP version** means changing it in every place that enforces it: the `Requires at least` / `Requires PHP` headers in `altrelay.php` and `readme.txt`, `ALTRELAY_MIN_WP` / `ALTRELAY_MIN_PHP` in `altrelay.php` (a unit test checks these against the headers), `minimum_wp_version` and `testVersion` in `phpcs.xml.dist`, the PHP platform in `composer.json`, `.wp-env.json`, and the oldest entries of the CI integration and end-to-end matrices.

### Deploying to WordPress.org

WordPress.org hosts plugins in Subversion. This repository stays the source of truth, and the `deploy` job in `release.yml` copies each tagged release to the plugin's SVN repository: the ZIP contents to `trunk/` and `tags/x.y.z/`, and `.wordpress-org/` to `assets/`. It is off until you set it up once:

1. In the repository settings, create an environment named `wordpress-org` (optionally with required reviewers, so every deploy waits for approval).
2. Add the secrets `SVN_USERNAME` (your WordPress.org username) and `SVN_PASSWORD` (the SVN password generated under *Account & Security* in your WordPress.org profile, not your login password).
3. Add the repository variable `WPORG_DEPLOY_ENABLED` with the value `true`.

To update only the listing (readme, screenshots, banners) without a release, edit the files and deploy them from SVN by hand, or ship them with the next tag.
