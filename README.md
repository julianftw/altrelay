# AltRelay – Alt Text Everywhere

Change image alt text once and have it update everywhere on your site.

WordPress stores alt text in two independent places: the Media Library, and a copy inside every post that embeds the image. Editing one does not update the other. AltRelay connects them, and keeps decorative images silent.

## What it does

- **Media Library → posts.** Changing alt text on an attachment rewrites the `alt` in every post, page, custom post type, synced pattern and template that uses it, in background batches.
- **Block editor → Media Library.** Editing alt text on an image block sends the new value to the Media Library when the post is saved, and from there sitewide. The editor confirms it. This works with the post editor showing the template too, and in synced patterns and template parts, whose changes are sent when the pattern or part is saved. Autosaves send nothing, as the text may be half typed; a draft that was only autosaved sends its alt text on the next manual save, if the Media Library still has none for that image.
- **Frontend fallback.** Images with missing or empty alt get it filled at render time from the Media Library, without saving anything. Runs on `render_block`, so templates, patterns and block widgets are covered, and on text widgets.
- **Guard against other fillers.** SEO and alt text plugins that fill empty alt on display (SEOPress by default; AltText.ai, Auto Alt Text, Rank Math and BIALTY when switched on) run after AltRelay and cannot tell a decorative image from an undescribed one, or a pasted image from the attachment its class names. A last pass, at the end of `the_content`, `widget_text_content` and `widget_block_content` and around each `wp_content_img_tag`, gives back the empty alt of images AltRelay left empty on purpose: decorative images whatever was written into them, and foreign files that were given the named attachment's alt text. Text an author wrote is never touched. `altrelay_guard_alt_text` switches it off.

An image is only tied to a Media Library item when both its `wp-image-N` class and its file say so. Blocks pasted from another site keep that site's IDs; AltRelay leaves those images alone.

## Decorative images

An image is decorative when it is explicitly marked. AltRelay never fills, pulls or pushes alt text for such an image. It recognizes:

- WordPress 7.1+'s own Image block setting (`"isDecorative":true`, saved as `role="none"`),
- any image hidden from assistive technology (`role="none"`, `role="presentation"`, `aria-hidden="true"`),
- the `altrelay-decorative` class, on the image or on any block or element around it (a Group, Columns, a `<div>` in classic content). The sync, the frontend, the editor and the review lists all follow this. It reaches into synced patterns inside the block, but not into posts a Query Loop inside it shows: those are other posts' content. AltRelay then leaves the images alone; an image there that already has alt text keeps it and is still announced.

An unmarked empty alt means "not written yet", because the block editor saves `alt=""` for every image inserted without alt text. AltRelay fills those. Leaving an informative image silent is the worse accessibility failure, so marking is explicit:

- **Block editor:** core's "Mark as decorative" checkbox on Image blocks (WordPress 7.1+); AltRelay's "Decorative image" toggle on Cover and Media & Text blocks, and on Image blocks in older WordPress. Typing a description into a block marked with AltRelay's class removes the mark.
- **Dashboard:** Media → AltRelay has a tab for each list, and reads only the open one: images without alt text in the Media Library, images without alt text in content with *Fill* and *Mark decorative*, per row and in bulk, and marked images with *Remove decorative mark*. An image whose Media Library alt text is empty on purpose, as WordPress suggests for decorative images, gets *Mark decorative* in the list of images without alt text. After a confirmation, every use in a post is marked by the background queue, batch by batch like a sync, with the progress kept in the `_altrelay_marking` attachment meta. Once every use is marked, the image is recorded (`_altrelay_decorative` attachment meta) so it is no longer listed as missing. The record is removed when the image gets alt text, when a post is saved with a copy of it that has neither the mark nor alt text, or with *List as missing again*.
- **WP-CLI:** `wp altrelay mark-decorative` and `wp altrelay unmark-decorative`.

An image that is the only content of a link is refused everywhere: its alt text is the link's name. Captions are kept.

Sites that already use empty alt on purpose can turn on **Review first** on the dashboard. While it is on, every existing empty alt is left alone (frontend, editor and sync) until it is filled or marked.

Clearing an alt in a post only affects that post; clearing it sitewide is a Media Library action.

## What it deliberately does not do

- **It does not create alt text.** No AI generation, no guessing.
- **It does not support per-context alt text.** One image has one description, everywhere.
- **It does not touch posts the acting user cannot edit.** Background jobs carry the user who made the change. The dashboard says how many posts were skipped, and someone who can edit other people's posts can update them with one click.

## Limits

- **Only `post_content`.** Layouts a page builder keeps in post meta, and image references in meta or options, are neither synced nor filled. Code that renders such content can call `altrelay_fill_alt_text( $html )`. The excerpt is not synced either, so a WooCommerce product's short description keeps the alt text it was written with; the product description is synced.
- **The guard sees what passes through the content filters.** An image marked decorative with AltRelay's class on a block around it is guarded in post content and widgets, also when a block theme runs the finished page through `wp_content_img_tag` again. An image in a template part, such as a header, is guarded when it is decorative by its own markup (core's setting, `role`, `aria-hidden`, or the class on the image), not by a marked Group around it. A plugin that fills alt text in an output buffer, after WordPress has rendered the page, is not undone.
- **Only images with a `wp-image-N` class whose file belongs to attachment N**: the original, a registered size, or a resized, scaled, rotated or edited variant in the same folder. Only the URL path is compared, so CDNs work. Media offloaded under different file names needs the `altrelay_image_matches_attachment` filter.
- **Third-party blocks.** The sync covers any image in the content. The frontend fallback covers core blocks and whatever is added through `altrelay_frontend_blocks`.
- **Block bindings and pattern overrides** are left to their source.
- **A synced post gets a new modified date**, like any saved post. `altrelay_update_post_modified` turns that off.
- **Editors without a save step.** The post, site and widgets editors and the Customizer push on save. Any other block editor keeps changes in the content it edits.
- **Post statuses.** Published, draft, pending, private and scheduled posts are synced. Custom statuses can be added with `altrelay_post_statuses`.

## Requirements

- WordPress 6.7 or newer
- PHP 8.2 or newer
- Optional: Action Scheduler (bundled with WooCommerce). Without it, WP-Cron is used: one cron event runs a worker that takes the jobs from a list of its own, which is not autoloaded, so a bulk change does not weigh down every page load. When the plugin that brought Action Scheduler is deactivated, the jobs it still holds for AltRelay are taken over by WP-Cron: on the next worker run, which the deactivation schedules, or when the dashboard or `wp altrelay status` is opened. A network-wide deactivation schedules that run on each of the first 100 sites whose Action Scheduler table holds an AltRelay job.

WordPress refuses to activate the plugin on older versions. If it is active on one anyway (a database moved to an older site, a PHP downgrade), it loads nothing and shows administrators a notice instead. A second copy of the plugin, active at the same time, does the same: `altrelay.php` holds only the header, the constants and that check, and loads the rest from `includes/altrelay-functions.php`.

CI runs the integration suite on WordPress 6.7, the current release (single site and multisite) and the nightly build, each with Action Scheduler and on WP-Cron, on MariaDB and once on MySQL 8, whose regular expressions work differently. A smoke test runs every query AltRelay builds by hand on SQLite, inside WordPress Playground (`tests/sqlite/`); it also runs with `wp eval-file` on a local SQLite site such as WordPress Studio's. The end-to-end suite runs in a real block editor on 6.7 and the current release, on WP-Cron with a block theme, on Action Scheduler with a classic theme and its widgets screen, and on a multisite network as a site administrator, followed by network deactivation and uninstall. Decorative marking follows the WordPress version: 7.1+ uses the Image block's own setting, 6.7–7.0 uses AltRelay's toggle. Marks from either are respected on every version.



## Installation

1. Back up your database. AltRelay modifies post content when it syncs.
2. Upload the `altrelay` folder to `/wp-content/plugins/`, or install the ZIP from a [release](https://github.com/julianftw/altrelay/releases).
3. Activate the plugin.
4. Open **Media → AltRelay**. Turn on Review first if your site uses empty alt on purpose, mark decorative images and describe the ones missing alt text.

## WP-CLI

```bash
wp altrelay audit                          # coverage summary
wp altrelay audit --ids                    # attachments missing alt text (nothing when there are none)
wp altrelay sync 42                        # push one image's alt text everywhere
wp altrelay sync --all --dry-run           # list every post a full repair would change
wp altrelay sync --all --post-type=page    # repair pages only, one save per post
wp altrelay sync --all --user=admin        # repair, with admin's permissions
wp altrelay empty-alt --format=csv         # images without alt in content, for review
wp altrelay empty-alt --post-type=page     # the same, for pages only
wp altrelay decorative                     # images marked decorative
wp altrelay fill 12                        # fill empty alts in post 12
wp altrelay fill 12 --dry-run              # only say whether it would change
wp altrelay mark-decorative 12 42          # mark image 42 in post 12 decorative
wp altrelay unmark-decorative 12 42        # and remove the mark again
wp altrelay mark-image-decorative 42       # mark image 42 decorative wherever it is used
wp altrelay list-image-again 42            # list it as missing alt text again
wp altrelay status                         # queue, last sync, skipped posts, last error, images to try again
wp altrelay run-queue                      # run waiting WP-Cron jobs now (sites without working WP-Cron)
```

`run-queue` runs the WP-Cron queue. On a site where Action Scheduler runs AltRelay's updates it says so and names the command that runs them: `wp action-scheduler run --hooks=altrelay_process_sync`. The coverage that `audit` reports counts images marked decorative wherever they are used as covered.

Every command that changes posts honours `--user`: with it, only posts that user may edit are touched. `fill`, `mark-decorative` and `unmark-decorative` take `--dry-run` like `sync`. The commands are explicit requests, so `altrelay_should_sync` does not apply to them. A command that could not save a post ends with `WP_CLI::error()`, so it exits with status 1. `sync --all` that reached every post clears the list of images to try again.

## Hooks

| Hook | Type | Arguments | Purpose |
| --- | --- | --- | --- |
| `altrelay_should_sync` | filter | `bool $should_sync, int $attachment_id, string $new_alt` | Veto the automatic propagation of one change. Explicit requests (WP-CLI, the dashboard's buttons) are not affected. |
| `altrelay_post_types` | filter | `string[] $post_types` | Post types searched and written. |
| `altrelay_post_statuses` | filter | `string[] $statuses` | Post statuses searched and written. Trash and auto-drafts are always left out. |
| `altrelay_alt_text` | filter | `string $alt, int $attachment_id` | Rewrite the value written to posts and shown on the frontend. |
| `altrelay_treat_empty_alt_as_decorative` | filter | `bool $is_decorative, int $attachment_id` | Leave unmarked empty alts alone (`0` = sitewide). Default: the Review first switch. |
| `altrelay_image_matches_attachment` | filter | `bool $matches, string $src, int $attachment_id` | Whether an image file belongs to an attachment. |
| `altrelay_update_post_modified` | filter | `bool $update, int $post_id` | Return `false` to keep a synced post's modified date. |
| `altrelay_frontend_fallback` | filter | `bool $enabled` | Return `false` to switch the frontend fallback off. |
| `altrelay_frontend_blocks` | filter | `string[] $blocks` | Blocks whose rendered output is checked. `''` is classic content. |
| `altrelay_guard_alt_text` | filter | `bool $enabled` | Return `false` to let other plugins' display-time alt text stand on decorative and foreign images. |
| `altrelay_use_core_decorative` | filter | `bool $use` | Whether Image blocks use core's `isDecorative` setting. Default: detected. |
| `altrelay_use_action_scheduler` | filter | `bool $use` | Return `false` to force the WP-Cron fallback. |
| `altrelay_cron_time_limit` | filter | `int $seconds` | How long one WP-Cron run works through the queue. Default 20; at least one job always runs. |
| `altrelay_post_synced` | action | `int $post_id, int $attachment_id, string $alt` | After a post was rewritten. Fires once per image that changed. |
| `altrelay_sync_failed` | action | `int $post_id, string $message` | A post could not be saved (`0`: a query failed). |
| `altrelay_upgrade` | action | `mixed $previous, string $current` | When the stored version changes. |

Functions:

- `altrelay_fill_alt_text( string $html ): string` fills missing alt text in any HTML. Nothing is saved.
- `altrelay( string $component ): ?object` returns the `'sync'`, `'queue'`, `'report'`, `'frontend'` or `'admin'` instance, so a hook can be removed: `remove_filter( 'render_block', array( altrelay( 'frontend' ), 'fill_block' ), 10 );`

## Architecture

```
altrelay.php                          Header, constants, and the check for a second active copy
├── includes/
│   ├── altrelay-functions.php        Requirements guard, lifecycle hooks, editor assets, public functions
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
│   ├── js/admin.js                  Dashboard: confirmation before marking, focus on the result
│   └── css/admin.css                Dashboard styles
├── languages/altrelay.pot            Translation template
└── uninstall.php                    Clean removal
```

A background job is `[ attachment ID, resume cursor, acting user ID ]`. The alt text is read when the job runs, so a job never writes an outdated value, repeated changes to one image collapse into one job, and the length of the text does not matter. On WP-Cron the jobs live in the `altrelay_queue` option (not autoloaded), changed under a row lock in the options table, and one worker event works through them. The lock row holds a value unique to the request that took it: an expired lock is removed only while it still holds the value found expired, and a request only releases its own, so two requests never both hold it. Jobs queued during a request are written once, when it ends, to the queue of the site they were queued on. Marking a job done and taking the next is one write. A job whose request died is queued again after ten minutes, at most three times in a row; then it is given up and listed on the dashboard. A post that throws while it is saved fails on its own: the image's other posts are still updated, and the image is listed to try again. Right before a post is saved, its content is read again, so a change another job saved in the meantime is kept. With Action Scheduler, a batch does not schedule the image's next one while a newer change to the image waits, which covers every post anyway.

A post that is open in an editor (a fresh `_edit_lock`) when a job rewrites it is a special case: the editor still shows the old text, and its own script sees nothing to push. The job notes the alt text it replaced in the `_altrelay_stale_alts` post meta. When the post is saved next, `wp_insert_post_data` gives every copy that still holds one of those texts the current one; a copy the author changed to something else is left as typed. The note goes once a save holds none of the old texts, which is when the editor was reloaded, or after a day.

A sync saves posts with `wp_update_post()`, so revisions, `save_post` and caches work as usual. That call saves every field again, through save filters that change markup: kses for a user without `unfiltered_html` (cron, Action Scheduler, and WP-CLI run as such a `--user`; WP-CLI without `--user` switches kses off), the removal of block custom CSS for a user without `edit_css` on WordPress 7.0 and newer, `trim()` on titles and `convert_invalid_entities()` on content. Every filter stays hooked and runs. AltRelay notes each field at the lowest priority of `pre_post_{field}` and puts it back at the highest priority of `{field}_save_pre`, but only when it is exactly the value being saved, which is the post's own. Code that saves something during the same request is filtered as usual.

## Development

```bash
composer install
npm ci

composer lint          # PHPCS (WordPress Coding Standards + PHPCompatibility)
composer analyse       # PHPStan level 10
composer test          # PHPUnit unit suite
composer check         # all three
npm run lint:js        # ESLint
npm run lint:css       # Stylelint
npm run test:coverage  # Jest, with coverage thresholds
npm run test:e2e       # Playwright, against a running WordPress (npm run env:start)
bash bin/check-pot.sh  # fail if languages/altrelay.pot is out of date (needs WP-CLI)
bash build-zip.sh      # dist/altrelay.zip (or composer dist on Windows)
bash bin/verify-dist.sh  # fail if the ZIP holds anything but runtime files
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the test suites and the release process, and [SECURITY.md](SECURITY.md) for reporting a vulnerability.

## Support

Bug reports and feature requests: [GitHub issues](https://github.com/julianftw/altrelay/issues). Plugin page: [codequelle.dev/altrelay](https://codequelle.dev/altrelay).

## License

GPL-2.0-or-later.
