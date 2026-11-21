# Security Policy

## Supported versions

Security fixes are released for the latest version of AltRelay. Please update before reporting.

## Reporting a vulnerability

Please do not describe a security problem in a public issue.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. If that is not available to you, open an issue that only says you have a security report, without any details, and you will be contacted.

Include what you can of the following:

- The AltRelay, WordPress and PHP versions.
- What an attacker needs (no account, a Subscriber, an Author, ...).
- The steps to reproduce it, or a proof of concept.
- What an attacker gains.

You will get a reply within five working days. A confirmed problem is fixed in a new release as quickly as its severity calls for, and you are credited in the changelog unless you prefer not to be.

## What AltRelay does with your site

Knowing the boundaries helps to judge whether something is a vulnerability.

- **What it writes.** In post content: the `alt` attribute of images, `role` and `aria-label` on Cover backgrounds, the `altrelay-decorative` class, and core's `isDecorative` block attribute. In the database: the version and the "review first" switch, a short-lived welcome flag, a few transients, an `_altrelay_decorative` meta entry on images marked decorative wherever they are used and an `_altrelay_marking` entry while they are being marked, an `_altrelay_stale_alts` entry on a post that was open in an editor while an update replaced its alt text, and queued jobs, either in Action Scheduler or in the `altrelay_queue` option with its lock row `altrelay_queue_lock` and one WP-Cron event. When Action Scheduler is no longer loaded, AltRelay marks its own pending jobs in Action Scheduler's table as canceled, taking them over on WP-Cron, or on deactivation dropping them. Nothing else.
- **Who can do what.** The dashboard needs `upload_files`. Changing a post through it needs `edit_post` for that post; saving alt text needs `edit_post` for that attachment; the "review first" switch needs `manage_options`; running waiting jobs and re-syncing skipped posts need `edit_others_posts`. A background job only touches posts the user who triggered it may edit. Every form is protected by a nonce.
- **What users see.** The review lists only show posts the current user can edit.
- **Saving posts.** A synced post is saved with `wp_update_post()`, so revisions and `save_post` hooks work as usual. That call saves the title, content, excerpt and filtered content again, and WordPress's save filters change markup on the way: kses for a user without `unfiltered_html` (which includes cron and Action Scheduler jobs), the removal of block custom CSS for a user without `edit_css` (WordPress 7.0 and newer), `trim()` and entity conversion. All filters stay hooked and run. For the one save AltRelay makes, each of those four fields is handed back exactly as it entered the filters, but only when it is the exact value being saved: the post's stored field, or its stored content with only the attributes above changed. Anything else saved during the same request, including by code hooked to `save_post`, is filtered as usual.
- **No remote requests.** AltRelay sends nothing anywhere and loads nothing from anywhere.
