=== AltRelay – Alt Text Everywhere ===
Contributors: codequelle
Tags: alt text, accessibility, images, media library, sync
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Change image alt text once and have it update everywhere on your site — automatically.

== Description ==

**The problem AltRelay solves**

When you add an image to WordPress, you give it a description (called "alt text") that helps screen readers and search engines understand what the image shows. WordPress stores this description in two separate places:

1. **The Media Library** — the central place where all your uploaded images live.
2. **Each post or page** — every time you insert an image, a copy of the alt text is saved inside that post's content.

These two copies are independent. If you update the alt text in the Media Library, your posts keep the old version. If you fix it inside a post, the Media Library still has the original. And if an image had no alt text when you inserted it, every post keeps that empty copy, even after you describe the image later.

**What AltRelay does**

AltRelay connects these two places:

* Change alt text in the **Media Library** → every post using that image is updated in the background.
* Change alt text on an image in the **block editor** → when you save the post, the Media Library is updated, and from there every other post. The editor confirms it.
* A visitor views a page where an image has no alt text → AltRelay fills it in from the Media Library on the fly, without saving anything. This covers posts, pages, synced patterns, templates, block widgets and text widgets.

There is nothing to configure. Activate the plugin and it works.

**Decorative images**

Some images only decorate and should be skipped by screen readers. Their alt text must stay empty. AltRelay never touches an image you mark as decorative:

* In the block editor on WordPress 7.1 or newer, tick WordPress's own **Mark as decorative** checkbox in the Image block settings. On older versions, and for Cover and Media & Text blocks, AltRelay adds a **Decorative image** toggle to the block settings.
* Images hidden from screen readers in any other way (`role="none"`, `role="presentation"` or `aria-hidden="true"`) are treated as decorative too.
* To make AltRelay leave every image in a section alone, give the block around them, for example a Group, the CSS class `altrelay-decorative` (Block settings → Advanced → Additional CSS class). AltRelay then fills and syncs none of their alt text, including in synced patterns inside the block. Images there that already have alt text keep it; empty them to make them silent.
* On the **Media → AltRelay** dashboard, the "Empty alt text in your content" list shows every image that has no alt text in a post but has one in the Media Library. Mark the decorative ones with one click, or fill the rest. The "Images marked decorative" list shows what is marked and lets you remove a mark again. Both lists can be narrowed to one content type.
* From the command line: `wp altrelay mark-decorative` and `wp altrelay unmark-decorative`.

An image that is the only content of a link cannot be marked decorative. Its alt text is what names the link, so without it a screen reader user hears a link with no name. AltRelay refuses and says why; remove the link first, or give the image alt text.

Why does marking matter? The block editor saves an empty alt for every image inserted without alt text, so an empty alt on its own almost always means "not written yet", not "decorative". AltRelay therefore fills unmarked empty alts. Leaving an informative image silent is the worse failure for a screen reader user, so the explicit mark is the safe default.

**Review first**

If your site already uses empty alt text on purpose, turn on **Review first** on the dashboard before anything is filled. While it is on, AltRelay leaves every existing empty alt alone: on your site, in the editor and when a description changes. Go through the review list, fill what should be described, mark what is decorative, then turn it off.

**Who this plugin is for**

* Site owners who reuse images across multiple posts and want consistent alt text.
* Content teams who want alt text changes to take effect sitewide without editing every post manually.
* Anyone who wants every informative image on the site to have a description.

**Who should NOT use this plugin**

* If you intentionally use *different* alt text for the same image depending on context (e.g. "Team photo" on one page, "Our staff at the retreat" on another) — AltRelay will overwrite those differences. It enforces one description per image, everywhere.
* If you need AI-generated alt text. AltRelay only syncs what you write — it does not create descriptions for you.

**Important: read before activating**

AltRelay changes the content of your posts when it syncs alt text. Please understand:

* **Back up your database first.** This is your safety net if you want to undo everything at once.
* **Decide about decorative images first.** After activating, open **Media → AltRelay**. Review the "Empty alt text in your content" list, or turn on **Review first** if there is a lot to go through.
* **One alt per image.** If the same image has different alt text in different posts, AltRelay will replace them all with whatever you set next. The old per-post values are lost.
* **Nothing is saved on activation alone.** AltRelay only writes to posts when alt text changes.
* **WordPress keeps revisions.** Every post AltRelay updates goes through the normal WordPress save routine, so a new revision is created and you can restore any individual post from the post editor.
* **Synced posts get a new "last modified" date,** like after any other edit. A developer can switch that off with the `altrelay_update_post_modified` filter.

**How changes happen — a quick guide**

= A visitor views a page =
AltRelay fills in missing or empty alt text on the fly. Nothing is saved. Undo by deactivating the plugin.

= You open a post in the editor =
Empty alt text on images that are not marked decorative is filled from the Media Library, and the editor says how many images it filled. This does not count as a change: the post is not marked as edited, and nothing is written anywhere until you change something yourself and save. It works the same while the editor shows the template around the post.

= You change alt text on an image block and save =
The new value is sent to the Media Library, and from there to every post using that image. Discarding your edits sends nothing. Undo with post revisions or a backup.

An autosave sends nothing, because the text may still be half typed. When a draft was only autosaved, its alt text is sent the next time you save the draft yourself, if the Media Library still has no description for that image. Alt text inside a template part or synced pattern is sent when that part or pattern is saved.

= You clear alt text on an image block =
Only that post changes. The Media Library keeps its description, and the editor offers to mark the image decorative.

= You change alt text in the Media Library =
Every post containing that image is updated in the background. A post that someone has open in the editor meanwhile keeps the new text when they save it. Undo with post revisions or a backup.

= You clear alt text in the Media Library =
The empty value is synced to all posts containing that image. When you describe the image again later, every post gets the new text.

= You deactivate or delete AltRelay =
Changes already made stay in the database, and so do decorative marks. Undo with a backup only.

**Supported blocks:** core/image, core/cover (including fixed and repeated backgrounds), core/media-text, galleries, images inside synced patterns, and images in classic content.

**Where AltRelay looks:** posts, pages, custom post types, synced patterns, templates and template parts. Only posts that the person who made the change is allowed to edit are updated.

**Syncing runs in the background.** Changing alt text queues the work instead of doing it inside the request, and each run handles one batch of posts, so a site with thousands of posts never times out. Action Scheduler is used when it is available; otherwise WP-Cron. The dashboard shows updates that are still waiting, and any that failed. A post that cannot be saved does not stop the image's other posts; once the cause is fixed, an editor or administrator can try those images again from the dashboard. If WP-Cron does not run on your site, they can also run the waiting updates from there.

**Posts someone cannot edit are left alone.** When an author changes an image's description, posts by other people keep the old one. The dashboard says how many were skipped, and an editor or administrator can update them with one click.

**What AltRelay does not cover**

* **Images it cannot tie to the Media Library.** AltRelay recognises an image by the `wp-image-123` class WordPress gives it, and only when the image file really belongs to that Media Library item. A block copied from another website keeps that site's IDs; AltRelay leaves such images alone, and the editor tells you when it does. Replace the image from your own Media Library to connect it.
* **Content outside the post content.** Page builders that keep their layout in custom fields, and image URLs stored in post meta or options, are not synced. Their output is not filled on the frontend either, unless the builder renders it through WordPress blocks. Developers can call `altrelay_fill_alt_text( $html )` on such output. Excerpts are not synced either: a WooCommerce product's short description keeps the alt text it was written with, while the product description is synced.
* **Markup that blocks from other plugins print themselves.** Syncing works for any image in the post content. Filling on the frontend covers core blocks, including core Image blocks nested inside another plugin's block; an image that such a block outputs on its own is not filled unless a developer adds the block's name with the `altrelay_frontend_blocks` filter.
* **Block bindings and pattern overrides.** When an image or its alt text comes from a binding, that source decides, and AltRelay leaves the block alone.
* **Featured images, galleries made with the `[gallery]` shortcode, and site logos** always read their alt text straight from the Media Library, so they need no syncing.

**Command line:** on sites with WP-CLI, `wp altrelay` audits and repairs from the terminal: a coverage summary, a full repair with a dry run, the review lists, filling and marking in one post or wherever an image is used, the state of the queue, and running it. `wp help altrelay` lists every command and option. Run commands with `--user=<login>` to apply that user's editing permissions; a command that could not save a post exits with status 1.

**For developers:** filters, actions and functions let you change what is synced and how, or switch parts off. They are documented in the plugin's README, together with every WP-CLI command.

**Privacy:** AltRelay makes no external requests, uses no cookies, and collects no data. It stores a plugin version, the Review first switch, a welcome-notice flag, a few short-lived cache entries, the list of waiting background updates when WP-Cron runs them, on images marked decorative wherever they are used a note saying so, or how far the marking has got, and on a post that was open in the editor during an update, the alt text the update replaced, for a day. All of it is removed on uninstall.

== Installation ==

1. **Back up your database.** AltRelay modifies post content when syncing — a backup lets you fully undo any changes.
2. Install AltRelay from **Plugins → Add New Plugin**, or upload the `altrelay` folder to `/wp-content/plugins/`.
3. Activate through the **Plugins** menu.
4. Open **Media → AltRelay**. If your site uses empty alt text on purpose, turn on **Review first**. Mark any decorative images in the "Empty alt text in your content" list, and describe the images listed under "Images without alt text in the Media Library".

No configuration needed.

== Frequently Asked Questions ==

= Should I back up before activating? =

Yes. AltRelay writes to post content when syncing alt text. A database backup is the safest way to undo bulk changes.

= Does anything happen right after activation? =

Nothing is saved. Visitors start seeing missing alt text filled in from the Media Library, and the dashboard lists what to review. If you would rather review before anything is filled, turn on Review first on the dashboard.

= Will AltRelay overwrite alt text I already have? =

When you change an image's alt text, the new value is written to *all* posts containing that image, except copies marked decorative. The frontend fallback never overwrites alt text that is already there.

= How do I keep a decorative image silent? =

Mark it decorative: tick **Mark as decorative** in the Image block settings (WordPress 7.1+) or turn on AltRelay's **Decorative image** toggle (older versions, Cover and Media & Text blocks), use **Mark decorative** on the dashboard, or run `wp altrelay mark-decorative`. Marked images are never filled or synced. If you later type a description into a block marked with AltRelay's toggle, the mark is removed and the description is synced like any other edit.

= I emptied an image's alt text in the Media Library because it is decorative. Why is it still listed? =

WordPress suggests leaving the Media Library alt text empty for a decorative image, but an empty field can also mean "not described yet", and AltRelay cannot tell which one you meant. Use **Mark decorative** next to the image in the "Images without alt text in the Media Library" list. AltRelay asks first, then marks the image decorative in every post that uses it, in the background like a sync, and the image leaves the list. If some uses cannot be marked (a post you may not edit, or an image that is the only content of a link and needs a description), the image stays listed and the dashboard says why. If the image is later added to a post without the mark, it is listed again, so the new copy can be marked too. **List as missing again** undoes it, and giving the image alt text later lists it with the described images.

= I marked an image decorative by mistake. How do I undo it? =

Open **Media → AltRelay** and use **Remove decorative mark** in the "Images marked decorative" list, or run `wp altrelay unmark-decorative`. The image gets its Media Library alt text back. In the editor, untick the checkbox or toggle.

= Why can't I mark a linked image as decorative? =

When an image is the only thing inside a link, its alt text is the name of the link. Without it, a screen reader announces a link and nothing else. AltRelay refuses in the editor, on the dashboard and on the command line. Remove the link, add text to it, or give the image alt text.

= I left alt text empty on purpose before installing AltRelay. What happens? =

Unmarked empty alts are treated as "not written yet", because that is what the block editor saves for images inserted without a description. Turn on **Review first** on the dashboard: AltRelay then leaves every empty alt alone while you go through the "Empty alt text in your content" list and mark the decorative ones. Turn it off when you are done.

= I use different alt text for the same image in different posts. Is that a problem? =

Yes. AltRelay enforces one alt per image. If you rely on per-context alt text, this plugin is not for you.

= Can I undo changes? =

Individual posts can be restored from WordPress revisions, because AltRelay saves through the normal WordPress post update routine. For a full rollback across many posts, you need a database backup taken before the change.

= What happens if I clear alt text? =

In a post, only that post changes and the editor offers to mark the image decorative. In the Media Library, the empty value is synced to all posts using the image; describing the image again later updates them all.

= Does it work with the Classic Editor? =

Yes. The sync engine, the frontend fallback, the dashboard and WP-CLI work regardless of editor. The editor pull, push and decorative toggle are block editor features. In classic content, mark an image decorative from the dashboard or WP-CLI.

= Does it work with SEO, image and alt text plugins? =

Yes. Some SEO and alt text plugins fill empty alt text when a page is displayed: SEOPress does so by default, AltText.ai, Auto Alt Text, Rank Math and BIALTY when the option is switched on. They run after AltRelay and cannot see that an image was marked decorative, or that an image pasted from another site only carries the number of one of your Media Library items. AltRelay gives those images their empty alt back once every other plugin is done. Images AltRelay fills, and alt text an author wrote, are left to them. Image optimisers, lazy loading, caching, security, translation and media folder plugins need nothing.

Only the alt text of what WordPress renders is checked. A plugin that changes the page after WordPress has finished it, in an output buffer, is not undone. In a template part such as the header, an image counts as decorative by its own setting, not by a decorative Group around it.

= What if the plugin that brought Action Scheduler is deactivated? =

Action Scheduler comes with plugins such as WooCommerce or an SEO plugin. When it goes away, the updates it was still holding for AltRelay are taken over by WP-Cron shortly after, or when the AltRelay dashboard is opened. On a network, a plugin deactivated for the whole network has this done on each of the first 100 sites; further sites take their updates over when their AltRelay dashboard is opened or `wp altrelay status` runs there.

= Does it work with page builders? =

It works wherever the builder saves normal image markup into the post content. Builders that keep their layout in custom fields are outside what AltRelay reads and writes. See "What AltRelay does not cover" above.

= What happens when I deactivate or delete AltRelay? =

All changes to post content are permanent, including decorative marks. The frontend fallback stops immediately, and queued updates are cancelled. Deactivation and deletion do not modify any posts.

= Which WordPress versions are supported? =

WordPress 6.7 or newer, on PHP 8.2 or newer. On WordPress 7.1 and newer, AltRelay uses the Image block's own **Mark as decorative** setting; on 6.7 to 7.0 it adds its own **Decorative image** toggle instead. Both are respected on every version, so nothing changes for images you already marked when you update WordPress.

WordPress does not let you activate AltRelay on older versions. If it ends up active on one anyway, for example after moving a database to an older site, it stays inactive and shows administrators a notice until WordPress is updated.

= Does it work on multisite? =

AltRelay works per site: alt text changed while code is switched to another site of the network is synced on that site, if AltRelay is active there. A network-wide deactivation clears the queued work of every site. On multisite only Super Admins have the `unfiltered_html` capability. AltRelay saves the post's existing content without filtering it again, so a change made by another user never strips embeds or other markup from the rest of the post. Anything else that is saved during the same request is filtered as usual.

= Why did my alt text not change everywhere? =

The usual reasons: the sync runs in the background, so give it a moment and check the dashboard, which also shows updates that failed. Posts the person who made the change cannot edit are skipped on purpose; the dashboard counts them and lets an editor or administrator update them. Images marked decorative are never changed. And an image whose file does not belong to the Media Library item named in its markup is left alone.

= Does AltRelay change the rest of my post content? =

No. It only rewrites the `alt` attribute of images that show the changed image, the matching label of a Cover background, and the Cover block's own alt setting. Embeds, forms and other markup are preserved, in the content and in the title and excerpt, also when the update runs in the background without a logged-in user.

== Screenshots ==

1. The AltRelay dashboard under Media: alt text coverage, the Review first switch, and inline editing for images that have no alt text yet.
2. Empty alt text in your content, with Fill and Mark decorative per image or in bulk. The images marked decorative have a tab of their own.
3. The Decorative image toggle in the block editor's settings sidebar.

== Changelog ==

= 1.0.0 =
* Initial release: alt text synced both ways between the Media Library and your content, a decorative mark that AltRelay respects everywhere, a dashboard to review what is empty or decorative, a frontend fallback, and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
Initial release of AltRelay. Back up your database and review decorative images after activating.
