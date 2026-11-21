<?php
/**
 * Unit tests for AltRelay_Frontend.
 *
 * Tests the frontend fallback filter that injects missing alt text
 * from the Media Library at render time.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class FrontendTest
 */
class FrontendTest extends TestCase
{

    /**
     * The frontend instance.
     *
     * @var AltRelay_Frontend
     */
    private AltRelay_Frontend $frontend;

    /**
     * Set up test fixtures.
     */
    protected function set_up(): void
    {
        parent::set_up();
        altrelay_test_reset_statics();

        global $altrelay_test_state;
        $altrelay_test_state = array(
            'post_meta' => array(),
            'options' => array(),
            'transients' => array(),
            'user_caps' => array('upload_files' => true, 'edit_post' => true),
            'post_fields' => array(),
            'is_admin' => false,
            'updated_posts' => array(),
            'cache_cleaned' => array(),
        );

        $this->frontend = new AltRelay_Frontend();
    }

    /**
     * Test that empty content is returned unchanged.
     */
    public function test_returns_empty_content_unchanged(): void
    {
        $this->assertSame('', $this->frontend->fill_missing_alt_text(''));
    }

    /**
     * Test that content without images is returned unchanged.
     */
    public function test_returns_content_without_images_unchanged(): void
    {
        $content = '<p>Hello world</p><div>No images here.</div>';
        $this->assertSame($content, $this->frontend->fill_missing_alt_text($content));
    }

    /**
     * Test that content is returned unchanged on admin pages.
     */
    public function test_returns_content_unchanged_on_admin(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['is_admin'] = true;

        $content = '<img src="photo.jpg" alt="" class="wp-image-42" />';
        $this->assertSame($content, $this->frontend->fill_missing_alt_text($content));
    }

    /**
     * Test that a non-empty alt is left untouched.
     */
    public function test_leaves_existing_alt_intact(): void
    {
        $content = '<img src="photo.jpg" alt="existing alt" class="wp-image-42" />';
        $result = $this->frontend->fill_missing_alt_text($content);
        $this->assertStringContainsString('alt="existing alt"', $result);
    }

    /**
     * Test that an unmarked empty alt is filled from the Media Library.
     *
     * The block editor saves alt="" for every image inserted without alt
     * text, so an unmarked empty alt means "not written yet".
     */
    public function test_fills_unmarked_empty_alt(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state["post_meta"][42] = array(
            "_wp_attachment_image_alt" => "Library alt text",
        );

        $content = "<img src=\"photo.jpg\" alt=\"\" class=\"wp-image-42\" />";
        $result = $this->frontend->fill_missing_alt_text($content);

        $this->assertStringContainsString("alt=\"Library alt text\"", $result);
    }

    /**
     * Test that an image marked decorative on its wrapper stays silent.
     */
    public function test_preserves_marked_decorative_image(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state["post_meta"][42] = array(
            "_wp_attachment_image_alt" => "Library alt text",
        );

        $content = "<figure class=\"wp-block-image altrelay-decorative\"><img src=\"photo.jpg\" alt=\"\" class=\"wp-image-42\" /></figure>";

        $this->assertSame($content, $this->frontend->fill_missing_alt_text($content));
    }

    /**
     * Test that sites can keep treating unmarked empty alt as decorative.
     */
    public function test_respects_empty_alt_when_site_opts_in(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state["post_meta"][42] = array(
            "_wp_attachment_image_alt" => "Library alt text",
        );
        $altrelay_test_state["filters"]["altrelay_treat_empty_alt_as_decorative"] = array(
            static fn(): bool => true,
        );

        $content = "<img src=\"photo.jpg\" alt=\"\" class=\"wp-image-42\" />";

        $this->assertSame($content, $this->frontend->fill_missing_alt_text($content));
    }

    /**
     * Test that missing alt attribute is added from Media Library.
     */
    public function test_adds_missing_alt_from_media_library(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Library alt text',
        );

        $content = '<img src="photo.jpg" class="wp-image-42" />';
        $result = $this->frontend->fill_missing_alt_text($content);

        $this->assertStringContainsString('alt="Library alt text"', $result);
    }

    /**
     * Test that images without wp-image class are skipped.
     */
    public function test_skips_images_without_wp_image_class(): void
    {
        $content = '<img src="external.jpg" alt="" />';
        $result = $this->frontend->fill_missing_alt_text($content);
        $this->assertStringContainsString('alt=""', $result);
    }

    /**
     * Test that images with no alt in Media Library are left unchanged.
     */
    public function test_skips_when_no_alt_in_media_library(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => '',
        );

        $content = '<img src="photo.jpg" alt="" class="wp-image-42" />';
        $result = $this->frontend->fill_missing_alt_text($content);

        $this->assertStringContainsString('alt=""', $result);
    }

    /**
     * Test that special characters in alt are properly escaped.
     */
    public function test_escapes_special_characters(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'A "quoted" & <special> text',
        );

        $content = '<img src="photo.jpg" alt="" class="wp-image-42" />';
        $result = $this->frontend->fill_missing_alt_text($content);

        $this->assertStringNotContainsString('<special>', $result);
        $this->assertStringContainsString('alt="', $result);
    }

    /**
     * Test multiple images in content: one with alt, one without.
     */
    public function test_fills_only_images_missing_alt(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Filled alt',
        );

        $content = '<img src="a.jpg" alt="keep me" class="wp-image-10" />'
            . '<img src="b.jpg" class="wp-image-42" />';

        $result = $this->frontend->fill_missing_alt_text($content);

        $this->assertStringContainsString('alt="keep me"', $result);
        $this->assertStringContainsString('alt="Filled alt"', $result);
    }

    /**
     * Test with attachment ID zero (invalid).
     */
    public function test_skips_zero_attachment_id(): void
    {
        $content = '<img src="photo.jpg" alt="" class="wp-image-0" />';
        $result = $this->frontend->fill_missing_alt_text($content);
        $this->assertStringContainsString('alt=""', $result);
    }

    /**
     * Test that single-quoted non-empty alt is left intact.
     */
    public function test_leaves_single_quoted_alt_intact(): void
    {
        $content = "<img src=\"photo.jpg\" alt='existing alt' class=\"wp-image-42\" />";
        $result = $this->frontend->fill_missing_alt_text($content);
        $this->assertStringContainsString("alt='existing alt'", $result);
    }

    /**
     * Test that a single-quoted empty alt is filled like any other.
     */
    public function test_fills_single_quoted_empty_alt(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state["post_meta"][42] = array(
            "_wp_attachment_image_alt" => "Library alt text",
        );

        $content = "<img src=\"photo.jpg\" alt='' class=\"wp-image-42\" />";
        $result = $this->frontend->fill_missing_alt_text($content);
        $this->assertStringContainsString("alt=\"Library alt text\"", $result);
    }

    /**
     * Test that the meta cache is primed once for every attachment on the page.
     *
     * Without this, a gallery of N images costs N separate meta queries on
     * every page view.
     */
    public function test_primes_meta_cache_for_all_attachments(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Alt 42',
        );
        $altrelay_test_state['post_meta'][43] = array(
            '_wp_attachment_image_alt' => 'Alt 43',
        );

        $content = '<img src="a.jpg" class="wp-image-42" />'
            . '<img src="b.jpg" class="wp-image-43" />';

        $this->frontend->prime_attachment_meta($content);

        $this->assertCount(1, $altrelay_test_state['meta_cache_primed']);
        $this->assertSame('post', $altrelay_test_state['meta_cache_primed'][0]['type']);
        $this->assertEqualsCanonicalizing(
            array(42, 43),
            $altrelay_test_state['meta_cache_primed'][0]['ids']
        );
    }

    /**
     * Regression (sixth audit, Performance): the images of a block theme's
     * header and footer cost a meta query each, as only the_content loaded
     * them in one go. Template parts are loaded the same way now.
     */
    public function test_template_parts_load_their_image_meta_at_once(): void
    {
        global $altrelay_test_state;

        $this->frontend->register();

        foreach (array('render_block_core_template_part_post', 'render_block_core_template_part_file') as $action) {
            $this->assertSame(4, $altrelay_test_state['hooks'][$action][0]['accepted_args'], $action);
        }

        $this->frontend->prime_template_part('theme//header', array('slug' => 'header'), null, '<img src="logo.png" alt="" class="wp-image-7" /><img src="b.jpg" class="wp-image-8" />');

        $this->assertEqualsCanonicalizing(array(7, 8), $altrelay_test_state['meta_cache_primed'][0]['ids']);
    }

    /**
     * Test that no meta cache priming happens without Media Library images.
     */
    public function test_does_not_prime_meta_cache_without_images(): void
    {
        global $altrelay_test_state;

        $content = '<p>No images.</p><img src="external.jpg" />';

        $this->frontend->prime_attachment_meta($content);

        $this->assertEmpty($altrelay_test_state['meta_cache_primed'] ?? array());
    }

    /**
     * Test that fill_missing_alt_text always returns a string,
     * even with large or complex content. The (string) cast on
     * preg_replace_callback provides null safety.
     */
    public function test_always_returns_string(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array(
            '_wp_attachment_image_alt' => 'Alt text',
        );

        $cases = array(
            '',
            '<p>No images</p>',
            '<img src="a.jpg" alt="" class="wp-image-42" />',
            '<img src="a.jpg" class="wp-image-42" />',
            str_repeat('<img src="x.jpg" alt="" class="wp-image-42" />', 50),
        );

        foreach ($cases as $content) {
            $this->assertIsString($this->frontend->fill_missing_alt_text($content));
        }
    }

    /**
     * Test that the render_block filter fills Image blocks.
     *
     * This is what covers templates, synced patterns and block widgets, not
     * only post content.
     */
    public function test_fill_block_fills_image_block(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = '<figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42"/></figure>';
        $result = $this->frontend->fill_block($html, array('blockName' => 'core/image'));

        $this->assertStringContainsString('alt="Library alt"', $result);
    }

    /**
     * Test that classic content (a freeform block with no name) is filled.
     */
    public function test_fill_block_fills_freeform_content(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = '<p><img src="a.jpg" class="wp-image-42" /></p>';
        $result = $this->frontend->fill_block($html, array('blockName' => null));

        $this->assertStringContainsString('alt="Library alt"', $result);
    }

    /**
     * Test that unrelated blocks are not parsed at all.
     */
    public function test_fill_block_ignores_unrelated_blocks(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = '<img src="a.jpg" class="wp-image-42" />';

        $this->assertSame($html, $this->frontend->fill_block($html, array('blockName' => 'acme/gallery')));
    }

    /**
     * Test that non-string block output passes through untouched.
     */
    public function test_fill_block_passes_through_non_strings(): void
    {
        $this->assertNull($this->frontend->fill_block(null, array('blockName' => 'core/image')));
    }

    /**
     * Test that the_content is not parsed a second time when do_blocks ran.
     */
    public function test_the_content_fallback_skips_when_do_blocks_is_hooked(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['registered_filters']['the_content|do_blocks'] = true;

        $html = '<img src="a.jpg" class="wp-image-42" />';

        $this->assertSame($html, $this->frontend->fill_missing_alt_text($html));
    }

    /**
     * Test that the displayed alt goes through altrelay_alt_text, like the sync.
     */
    public function test_alt_text_filter_applies_on_display(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['filters']['altrelay_alt_text'] = array(
            static fn(string $alt): string => strtoupper($alt),
        );

        $result = $this->frontend->fill_missing_alt_text('<img src="a.jpg" class="wp-image-42" />');

        $this->assertStringContainsString('alt="LIBRARY ALT"', $result);
    }

    /**
     * Test that non-string the_content values pass through untouched.
     */
    public function test_the_content_passes_through_non_strings(): void
    {
        $this->assertNull($this->frontend->fill_missing_alt_text(null));
        $this->assertNull($this->frontend->prime_attachment_meta(null));
    }

    /**
     * Test the hooks the frontend registers.
     */
    public function test_register_hooks(): void
    {
        global $altrelay_test_state;

        $this->frontend->register();

        $this->assertSame(8, $altrelay_test_state['hooks']['the_content'][0]['priority']);
        $this->assertSame(20, $altrelay_test_state['hooks']['the_content'][1]['priority']);
        $this->assertSame(2, $altrelay_test_state['hooks']['render_block'][0]['accepted_args']);
        $this->assertSame(3, $altrelay_test_state['hooks']['render_block_data'][0]['accepted_args']);
    }

    /**
     * Regression (second audit, bug 3): AltRelay's class on a Group made the
     * images in it decorative for the sync, but the frontend renders each
     * block on its own and filled them anyway.
     */
    public function test_a_marked_block_makes_the_images_inside_it_decorative(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $group = new WP_Block(array('blockName' => 'core/group', 'attrs' => array('className' => 'is-style-x altrelay-decorative')));
        $column = $this->frontend->note_marked_ancestor(array('blockName' => 'core/column', 'attrs' => array()), array(), $group);
        $image = $this->frontend->note_marked_ancestor(array('blockName' => 'core/image', 'attrs' => array('id' => 42)), array(), new WP_Block($column));

        $this->assertTrue($column['altrelayInsideDecorative']);
        $this->assertTrue($image['altrelayInsideDecorative'], 'The mark is passed down through every level.');

        $html = '<figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42"/></figure>';

        $this->assertSame($html, $this->frontend->fill_block($html, $image));
    }

    /**
     * Test that blocks outside a marked block are filled as before.
     */
    public function test_unmarked_parents_leave_blocks_alone(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $plain = array('blockName' => 'core/image', 'attrs' => array('id' => 42));

        $this->assertSame($plain, $this->frontend->note_marked_ancestor($plain), 'A top-level block has no parent.');
        $this->assertSame($plain, $this->frontend->note_marked_ancestor($plain, array(), new WP_Block(array('blockName' => 'core/group', 'attrs' => array('className' => 'other')))));
        $this->assertSame($plain, $this->frontend->note_marked_ancestor($plain, array(), new WP_Block(array('blockName' => 'core/group'))));
        $this->assertSame('not a block', $this->frontend->note_marked_ancestor('not a block'));

        $html = '<figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42"/></figure>';

        $this->assertStringContainsString('alt="Library alt"', (string) $this->frontend->fill_block($html, $plain));
    }

    /**
     * Regression (second audit, Frontend): every described image used to be
     * matched against its attachment's files before its alt was looked at.
     */
    public function test_described_and_decorative_images_skip_the_file_check(): void
    {
        global $altrelay_test_state;

        $checked = array();
        $altrelay_test_state['filters']['altrelay_image_matches_attachment'] = array(
            static function (bool $matches, string $src) use (&$checked): bool {
                $checked[] = $src;
                return true;
            },
        );
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['post_meta'][43] = array('_wp_attachment_image_alt' => '');

        $html = '<img src="described.jpg" alt="Written" class="wp-image-42" />'
            . '<img src="decorative.jpg" alt="" class="wp-image-42 altrelay-decorative" />'
            . '<img src="role.jpg" alt="" role="presentation" class="wp-image-42" />'
            . '<img src="nothing-to-fill.jpg" alt="" class="wp-image-43" />'
            . '<img src="empty.jpg" alt="" class="wp-image-42" />';

        $filled = $this->frontend->fill_html($html);

        $this->assertSame(array('empty.jpg'), $checked, 'Only the image that would be filled has its file checked.');
        $this->assertStringContainsString('src="empty.jpg" alt="Library alt"', $filled);
    }
    /**
     * Regression (audit bug 1): a foreign file with a local attachment ID
     * does not get that attachment's alt text.
     */
    public function test_does_not_fill_an_image_whose_file_belongs_elsewhere(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['strict_image_match'] = true;
        $altrelay_test_state['post_meta'][8] = array(
            '_wp_attachment_image_alt' => 'The local image number eight',
            '_wp_attached_file' => '2026/11/local.jpg',
            '_wp_attachment_metadata' => array('file' => '2026/11/local.jpg', 'sizes' => array()),
        );

        $foreign = '<img src="https://other.example/wp-content/uploads/2019/03/stranger.jpg" alt="" class="wp-image-8" />';
        $own = '<img src="https://example.com/wp-content/uploads/2026/11/local-300x200.jpg" alt="" class="wp-image-8" />';

        $this->assertSame($foreign, $this->frontend->fill_html($foreign));
        $this->assertStringContainsString('alt="The local image number eight"', $this->frontend->fill_html($own));
    }

    /**
     * Test that the fallback can be switched off.
     */
    public function test_fallback_can_be_switched_off_by_filter(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['filters']['altrelay_frontend_fallback'] = array(static fn(): bool => false);

        $html = '<img src="a.jpg" alt="" class="wp-image-42" />';

        $this->assertSame($html, $this->frontend->fill_html($html));
        $this->assertSame($html, $this->frontend->fill_block($html, array('blockName' => 'core/image')));
    }

    /**
     * Test that the block list can be extended, and is resolved once.
     */
    public function test_block_list_is_filterable(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $calls = 0;
        $altrelay_test_state['filters']['altrelay_frontend_blocks'] = array(
            static function (array $blocks) use (&$calls): array {
                ++$calls;
                return array_merge($blocks, array('acme/card', 42));
            },
        );

        $html = '<img src="a.jpg" alt="" class="wp-image-42" />';

        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_block($html, array('blockName' => 'acme/card')));
        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_block($html, array('blockName' => 'core/image')));
        $this->assertSame($html, $this->frontend->fill_block($html, array('blockName' => 'acme/other')));
        $this->assertSame(1, $calls);
    }

    /**
     * Test that galleries saved before WordPress 5.9, which hold their
     * images directly, are covered.
     */
    public function test_legacy_gallery_is_filled(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = '<figure class="wp-block-gallery"><ul><li><figure><img src="a.jpg" alt="" class="wp-image-42"/></figure></li></ul></figure>';

        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_block($html, array('blockName' => 'core/gallery')));
    }

    /**
     * Test that a fixed Cover background gets a label.
     */
    public function test_fills_fixed_cover_background(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = '<div class="wp-block-cover has-parallax"><div class="wp-block-cover__image-background wp-image-42 has-parallax" style="background-image:url(t.jpg)"></div></div>';

        $result = $this->frontend->fill_block($html, array('blockName' => 'core/cover'));

        $this->assertStringContainsString('role="img"', $result);
        $this->assertStringContainsString('aria-label="Library alt"', $result);
    }

    /**
     * Test that the "review first" switch stops the fallback from filling
     * an existing empty alt, while a missing alt is still filled.
     */
    public function test_review_first_leaves_empty_alt_alone(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['options']['altrelay_review_first'] = '1';

        $empty = '<img src="a.jpg" alt="" class="wp-image-42" />';

        $this->assertSame($empty, $this->frontend->fill_html($empty));
        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_html('<img src="a.jpg" class="wp-image-42" />'));
    }

    /**
     * Test that the render filters leave admin screens alone but treat Ajax
     * requests as the frontend, and that fill_html() works anywhere.
     */
    public function test_render_filters_skip_admin_screens_only(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['is_admin'] = true;

        $html = '<img src="a.jpg" alt="" class="wp-image-42" />';

        $this->assertSame($html, $this->frontend->fill_block($html, array('blockName' => 'core/image')));
        $this->assertSame($html, $this->frontend->fill_missing_alt_text($html));
        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_html($html), 'The helper is explicit, so it always fills.');

        $altrelay_test_state['doing_ajax'] = true;

        $this->assertStringContainsString('alt="Library alt"', $this->frontend->fill_block($html, array('blockName' => 'core/image')));
    }

    /**
     * Test that classic text widgets are filled even though do_blocks()
     * is hooked to the_content.
     */
    public function test_text_widgets_are_filled(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['registered_filters']['the_content|do_blocks'] = true;
        $altrelay_test_state['current_filter'] = 'widget_text_content';

        $this->assertStringContainsString(
            'alt="Library alt"',
            $this->frontend->fill_missing_alt_text('<img src="a.jpg" class="wp-image-42" />')
        );
    }

    /**
     * Test the public helper for content that is not rendered through blocks.
     */
    public function test_altrelay_fill_alt_text_helper(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $this->assertSame(
            '<img src="a.jpg" alt="Library alt" class="wp-image-42" />',
            altrelay_fill_alt_text('<img src="a.jpg" alt="" class="wp-image-42" />')
        );
        $this->assertSame('<p>No images</p>', altrelay_fill_alt_text('<p>No images</p>'));
    }

    /**
     * Test that the text widget filter is registered.
     */
    public function test_register_hooks_text_widgets(): void
    {
        global $altrelay_test_state;

        $this->frontend->register();

        $this->assertSame(20, $altrelay_test_state['hooks']['widget_text_content'][0]['priority']);
    }

    /**
     * Render content the way WordPress does: AltRelay's first filter, the
     * blocks, other plugins' filters, AltRelay's last pass.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $blocks Rendered blocks with their parsed form.
     * @param callable(string): string                        $others What other plugins do to the content.
     * @return string
     */
    private function render(array $blocks, callable $others): string
    {
        $this->frontend->begin_pass('');

        $html = '';
        foreach ($blocks as [$block_html, $block]) {
            $html .= $this->frontend->fill_block($block_html, $block);
        }

        return (string) $this->frontend->guard_content($others($html));
    }

    /**
     * What the alt text plugins tested do: every image with an empty or
     * missing alt gets one, here the alt of the attachment its class names.
     *
     * @param string $html Content.
     * @return string
     */
    private static function fill_like_others(string $html): string
    {
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/',
            static function (array $tag): string {
                if (preg_match('/\salt="[^"]+"/', $tag[0]) || !preg_match('/wp-image-(\d+)/', $tag[0], $id)) {
                    return $tag[0];
                }

                $alt = (string) get_post_meta((int) $id[1], '_wp_attachment_image_alt', true);

                return (string) preg_replace('/^<img/', '<img alt="' . $alt . '"', (string) preg_replace('/\salt="[^"]*"/', '', $tag[0]));
            },
            $html
        );
    }

    /**
     * Regression (compatibility testing): SEOPress, AltText.ai, Auto Alt
     * Text, Rank Math and BIALTY fill empty alt text after AltRelay and put
     * text into images marked decorative.
     */
    public function test_last_pass_undoes_alt_text_others_add_to_decorative_images(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $image = array('blockName' => 'core/image');
        $html = $this->render(
            array(
                array('<figure class="wp-block-image altrelay-decorative"><img src="marked.jpg" alt="" class="wp-image-42"/></figure>', $image),
                array('<figure class="wp-block-image"><img src="core.jpg" alt="" role="none" class="wp-image-42"/></figure>', $image),
                array('<figure class="wp-block-image"><img src="hidden.jpg" class="wp-image-42" aria-hidden="true"/></figure>', $image),
                array('<figure class="wp-block-image"><img src="plain.jpg" alt="" class="wp-image-42"/></figure>', $image),
            ),
            array(self::class, 'fill_like_others')
        );

        $this->assertStringContainsString('alt="" src="marked.jpg"', $html);
        $this->assertStringContainsString('alt="" src="core.jpg"', $html);
        $this->assertStringContainsString('alt="" src="hidden.jpg"', $html);
        $this->assertStringContainsString('src="plain.jpg" alt="Library alt"', $html, 'AltRelay fills the image nobody described.');
    }

    /**
     * Test that the last pass only touches images AltRelay saw empty, so
     * text an author wrote stays, whatever another plugin made of it.
     */
    public function test_last_pass_leaves_images_that_had_alt_text(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = $this->render(
            array(array('<img src="a.jpg" alt="Written" role="presentation" class="wp-image-42"/>', array('blockName' => 'core/html'))),
            static fn(string $html): string => str_replace('alt="Written"', 'alt="Keyword"', $html)
        );

        $this->assertStringContainsString('alt="Keyword"', $html);
    }

    /**
     * Test that an image whose file belongs elsewhere loses the alt text of
     * the attachment its class names, but keeps other text.
     */
    public function test_last_pass_undoes_the_wrong_attachments_alt_on_a_foreign_file(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['strict_image_match'] = true;
        $altrelay_test_state['post_meta'][8] = array(
            '_wp_attachment_image_alt' => 'The local image number eight',
            '_wp_attached_file' => '2026/11/local.jpg',
            '_wp_attachment_metadata' => array('file' => '2026/11/local.jpg', 'sizes' => array()),
        );

        $foreign = array('<img src="https://other.example/wp-content/uploads/2019/03/stranger.jpg" alt="" class="wp-image-8" />', array('blockName' => 'core/html'));

        $this->assertStringContainsString('alt=""', $this->render(array($foreign), array(self::class, 'fill_like_others')));
        $this->assertStringContainsString(
            'alt="stranger"',
            $this->render(array($foreign), static fn(string $html): string => str_replace('alt=""', 'alt="stranger"', $html)),
            'A file name is the other plugin\'s choice; only the wrong description is undone.'
        );
    }

    /**
     * Test that images inside a block marked decorative are guarded too,
     * although that block is not part of their own HTML.
     */
    public function test_last_pass_guards_images_inside_a_marked_block(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = $this->render(
            array(array('<figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42"/></figure>', array('blockName' => 'core/image', 'altrelayInsideDecorative' => true))),
            static fn(string $html): string => '<div class="wp-block-group altrelay-decorative">' . self::fill_like_others($html) . '</div>'
        );

        $this->assertStringContainsString('alt="" src="a.jpg"', $html);
    }

    /**
     * Test that the images of content rendered inside other content, such
     * as a Query Loop, are guarded against the outer content's filters too.
     */
    public function test_last_pass_of_inner_content_reaches_the_outer_pass(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $this->frontend->begin_pass('');
        $inner = $this->render(
            array(array('<img src="a.jpg" alt="" role="none" class="wp-image-42"/>', array('blockName' => 'core/html'))),
            static fn(string $html): string => $html
        );
        $outer = (string) $this->frontend->guard_content(self::fill_like_others('<div>' . $inner . '</div>'));

        $this->assertStringContainsString('alt=""', $outer);
        $this->assertStringNotContainsString('Library alt', $outer);
    }

    /**
     * Test that the last pass runs with the frontend fallback off, and can
     * be switched off itself.
     */
    public function test_last_pass_is_independent_of_the_fallback_and_can_be_switched_off(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');
        $altrelay_test_state['filters']['altrelay_frontend_fallback'] = array(static fn(): bool => false);

        $blocks = array(
            array('<img src="decorative.jpg" alt="" role="none" class="wp-image-42"/>', array('blockName' => 'core/html')),
            array('<img src="plain.jpg" alt="" class="wp-image-42"/>', array('blockName' => 'core/html')),
        );

        $html = $this->render($blocks, array(self::class, 'fill_like_others'));

        $this->assertStringContainsString('alt="" src="decorative.jpg"', $html);
        $this->assertStringContainsString('alt="Library alt" src="plain.jpg"', $html, 'Filling the others is the other plugin\'s business.');

        $altrelay_test_state['filters']['altrelay_guard_alt_text'] = array(static fn(): bool => false);

        $this->assertStringContainsString('alt="Library alt" src="decorative.jpg"', $this->render($blocks, array(self::class, 'fill_like_others')));
    }

    /**
     * Test that content in which AltRelay left nothing empty on purpose is
     * not parsed again.
     */
    public function test_last_pass_skips_content_without_guarded_images(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $this->frontend->begin_pass('');
        $html = '<img src="a.jpg" alt="Anything" role="none" class="wp-image-42"/>';

        $this->assertSame($html, $this->frontend->guard_content($html));
        $this->assertSame('passes', $this->frontend->guard_content('passes'), 'A last pass without a first one changes nothing.');
    }

    /**
     * Test the guard on single image tags, which covers block templates:
     * the tag is put back as it was when it is decorative by its own
     * markup, or carries the wrong attachment's alt on a foreign file.
     */
    public function test_image_tag_guard(): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['strict_image_match'] = true;
        $altrelay_test_state['post_meta'][8] = array(
            '_wp_attachment_image_alt' => 'Local eight',
            '_wp_attached_file' => '2026/11/local.jpg',
            '_wp_attachment_metadata' => array('file' => '2026/11/local.jpg', 'sizes' => array()),
        );

        $own = 'https://example.com/wp-content/uploads/2026/11/local.jpg';
        $guard = function (string $before, string $after): mixed {
            $this->frontend->note_image_tag($before);
            return $this->frontend->guard_image_tag($after);
        };

        $this->assertSame('<img src="' . $own . '" alt="" role="none" class="wp-image-8">', $guard('<img src="' . $own . '" alt="" role="none" class="wp-image-8">', '<img src="' . $own . '" alt="Local eight" role="none" class="wp-image-8">'));
        $this->assertSame('<img src="' . $own . '"  aria-hidden="true" class="wp-image-8">', $guard('<img src="' . $own . '" aria-hidden="true" class="wp-image-8">', '<img src="' . $own . '" alt="Local eight" aria-hidden="true" class="wp-image-8">'), 'A missing alt is missing again.');
        $this->assertSame('<img src="https://other.example/x.jpg" alt="" class="wp-image-8">', $guard('<img src="https://other.example/x.jpg" alt="" class="wp-image-8">', '<img src="https://other.example/x.jpg" alt="Local eight" class="wp-image-8">'));

        $kept = array(
            array('<img src="' . $own . '" alt="" class="wp-image-8">', '<img src="' . $own . '" alt="Local eight" class="wp-image-8">'),
            array('<img src="https://other.example/x.jpg" alt="" class="wp-image-8">', '<img src="https://other.example/x.jpg" alt="x" class="wp-image-8">'),
            array('<img src="' . $own . '" alt="Written" role="none" class="wp-image-8">', '<img src="' . $own . '" alt="Changed" role="none" class="wp-image-8">'),
            array('<img src="a.jpg" alt="">', '<img src="a.jpg" alt="No class">'),
        );

        foreach ($kept as [$before, $after]) {
            $this->assertSame($after, $guard($before, $after));
        }

        $this->assertSame('not noted', $this->frontend->guard_image_tag('not noted'));
    }

    /**
     * Regression (compatibility testing): a block theme runs the whole page
     * through wp_content_img_tag again after the content, where SEOPress
     * filled an image in a marked block that the content's last pass had
     * just emptied again.
     */
    public function test_image_tag_guard_remembers_what_the_content_saw(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['post_meta'][42] = array('_wp_attachment_image_alt' => 'Library alt');

        $html = $this->render(
            array(array('<figure class="wp-block-image altrelay-decorative"><img src="a.jpg" alt="" class="wp-image-42"/></figure>', array('blockName' => 'core/image'))),
            static fn(string $html): string => $html
        );

        $tag = '<img src="a.jpg" alt="" class="wp-image-42"/>';
        $this->assertStringContainsString($tag, $html);

        $this->frontend->note_image_tag($tag);
        $this->assertSame($tag, $this->frontend->guard_image_tag('<img src="a.jpg" alt="Library alt" class="wp-image-42"/>'));

        // The same tag with what wp_filter_content_tags() adds on a second pass.
        $lazy = '<img loading="lazy" decoding="async" src="a.jpg" alt="" class="wp-image-42"/>';
        $this->frontend->note_image_tag($lazy);
        $this->assertSame($lazy, $this->frontend->guard_image_tag('<img loading="lazy" decoding="async" src="a.jpg" alt="Library alt" class="wp-image-42"/>'));
    }

    /**
     * Regression (fifth audit): the memory of decorative images covered the
     * whole request by attachment, so another copy of the image on the same
     * page, in a header for example, lost the text another plugin gave it,
     * although only the copy in the content was decorative.
     */
    public function test_image_tag_guard_leaves_other_copies_of_a_decorative_image_alone(): void
    {
        $this->render(
            array(array('<figure class="wp-block-image altrelay-decorative"><img src="a.jpg" alt="" class="wp-image-42"/></figure>', array('blockName' => 'core/image'))),
            static fn(string $html): string => $html
        );

        $header = '<img src="a-150x150.jpg" alt="" class="wp-image-42 header-logo"/>';
        $filled = '<img src="a-150x150.jpg" alt="Generic text" class="wp-image-42 header-logo"/>';

        $this->frontend->note_image_tag($header);
        $this->assertSame($filled, $this->frontend->guard_image_tag($filled));
    }

    /**
     * Test that the guard hooks run first and last.
     */
    public function test_register_hooks_guard(): void
    {
        global $altrelay_test_state;

        $this->frontend->register();

        foreach (array('the_content', 'widget_text_content', 'widget_block_content') as $filter) {
            $priorities = array_column($altrelay_test_state['hooks'][$filter], 'priority');
            $this->assertContains(PHP_INT_MIN, $priorities, $filter);
            $this->assertContains(PHP_INT_MAX, $priorities, $filter);
        }

        $this->assertSame(array(PHP_INT_MIN, PHP_INT_MAX), array_column($altrelay_test_state['hooks']['wp_content_img_tag'], 'priority'));
    }
}
