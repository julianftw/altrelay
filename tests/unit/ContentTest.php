<?php
/**
 * Unit tests for AltRelay_Content.
 *
 * Runs against WordPress' real WP_HTML_Tag_Processor (tests/fixtures).
 *
 * @package AltRelay
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Class ContentTest
 */
class ContentTest extends TestCase
{

    /**
     * Reset state.
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
            'user_caps' => array(),
            'post_fields' => array(),
            'is_admin' => false,
        );
    }

    /**
     * Test marker detection on class strings.
     */
    public function test_has_marker(): void
    {
        $this->assertTrue(AltRelay_Content::has_marker('wp-block-image altrelay-decorative'));
        $this->assertTrue(AltRelay_Content::has_marker("  altrelay-decorative\tsize-large "));
        $this->assertFalse(AltRelay_Content::has_marker('altrelay-decorative-not'));
        $this->assertFalse(AltRelay_Content::has_marker(''));
        $this->assertFalse(AltRelay_Content::has_marker(null));
    }

    /**
     * Test that attachment IDs are read on whole class names only.
     */
    public function test_attachment_id_from_class(): void
    {
        $this->assertSame(42, AltRelay_Content::attachment_id_from_class('size-full wp-image-42'));
        $this->assertSame(0, AltRelay_Content::attachment_id_from_class('wp-image-42x'));
        $this->assertSame(0, AltRelay_Content::attachment_id_from_class('my-wp-image-42'));
        $this->assertSame(0, AltRelay_Content::attachment_id_from_class(null));
    }

    /**
     * Test that find_images reports alt state and decorative status.
     */
    public function test_find_images(): void
    {
        $html = '<img src="a.jpg" class="wp-image-1" />'
            . '<img src="b.jpg" alt="" class="wp-image-2" />'
            . '<figure class="altrelay-decorative"><img src="c.jpg" alt="" class="wp-image-3" /></figure>'
            . '<img src="d.jpg" alt="Dog" class="wp-image-4 altrelay-decorative" />'
            . '<img src="e.jpg" alt="no id" />';

        $images = AltRelay_Content::find_images($html);

        $this->assertSame(
            array(
                array('attachment_id' => 1, 'alt' => null, 'decorative' => false, 'src' => 'a.jpg', 'linked' => false),
                array('attachment_id' => 2, 'alt' => '', 'decorative' => false, 'src' => 'b.jpg', 'linked' => false),
                array('attachment_id' => 3, 'alt' => '', 'decorative' => true, 'src' => 'c.jpg', 'linked' => false),
                array('attachment_id' => 4, 'alt' => 'Dog', 'decorative' => true, 'src' => 'd.jpg', 'linked' => false),
            ),
            $images
        );
    }

    /**
     * Test that the marker only applies inside the element that carries it.
     */
    public function test_marker_scope_ends_with_its_element(): void
    {
        $html = '<div class="altrelay-decorative"><p>Text</p></div><img src="a.jpg" alt="" class="wp-image-1" />';

        $images = AltRelay_Content::find_images($html);

        $this->assertFalse($images[0]['decorative']);
    }

    /**
     * Test that a stray closing tag cannot cancel an enclosing marker.
     */
    public function test_stray_closer_keeps_marker(): void
    {
        $html = '<figure class="altrelay-decorative"></span><img src="a.jpg" alt="" class="wp-image-1" /></figure>';

        $images = AltRelay_Content::find_images($html);

        $this->assertTrue($images[0]['decorative']);
    }

    /**
     * Test that rewriting fills empty and missing alts but skips decorative ones.
     */
    public function test_rewrite_applies_the_rule(): void
    {
        $html = '<img src="a.jpg" alt="" class="wp-image-42" />'
            . '<img src="b.jpg" class="wp-image-42" />'
            . '<figure class="wp-block-image altrelay-decorative"><img src="c.jpg" alt="" class="wp-image-42" /></figure>'
            . '<img src="d.jpg" alt="" class="wp-image-7" />';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, 'Dog');

        $this->assertSame(
            '<img src="a.jpg" alt="Dog" class="wp-image-42" />'
            . '<img alt="Dog" src="b.jpg" class="wp-image-42" />'
            . '<figure class="wp-block-image altrelay-decorative"><img src="c.jpg" alt="" class="wp-image-42" /></figure>'
            . '<img src="d.jpg" alt="" class="wp-image-7" />',
            $result
        );
    }

    /**
     * Test that the Cover block's comment attribute is updated with its HTML.
     *
     * The Cover block stores alt in the comment, not in the img, so updating
     * only the HTML would make the block fail validation in the editor.
     */
    public function test_rewrite_updates_cover_comment_attribute(): void
    {
        $html = '<!-- wp:cover {"url":"https://example.com/a.jpg","id":42,"alt":"Old","dimRatio":50} -->'
            . '<div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="Old" src="https://example.com/a.jpg" data-object-fit="cover"/>'
            . '<div class="wp-block-cover__inner-container"></div></div>'
            . '<!-- /wp:cover -->';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, 'New');

        $this->assertStringContainsString('<!-- wp:cover {"url":"https://example.com/a.jpg","id":42,"alt":"New","dimRatio":50} -->', $result);
        $this->assertStringContainsString('alt="New" src=', $result);
    }

    /**
     * Test that a Cover block without an alt attribute gets one.
     *
     * The editor omits attributes equal to their default, so a Cover inserted
     * without alt text has no "alt" key at all.
     */
    public function test_rewrite_adds_missing_cover_attribute(): void
    {
        $html = '<!-- wp:cover {"id":42} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="" src="a.jpg"/></div><!-- /wp:cover -->';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, 'New');

        $this->assertStringContainsString('<!-- wp:cover {"id":42,"alt":"New"} -->', $result);
    }

    /**
     * Test that clearing a Cover alt removes the attribute, as the editor would.
     */
    public function test_rewrite_clearing_removes_cover_attribute(): void
    {
        $html = '<!-- wp:cover {"id":42,"alt":"Old"} --><div class="wp-block-cover"><img class="wp-image-42" alt="Old" src="a.jpg"/></div><!-- /wp:cover -->';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, '');

        $this->assertStringContainsString('<!-- wp:cover {"id":42} -->', $result);
        $this->assertStringContainsString('alt=""', $result);
    }

    /**
     * Test that a decorative Cover block is left alone, comment and HTML.
     */
    public function test_rewrite_skips_decorative_cover(): void
    {
        $html = '<!-- wp:cover {"id":42,"className":"altrelay-decorative"} --><div class="wp-block-cover altrelay-decorative"><img class="wp-image-42" alt="" src="a.jpg"/></div><!-- /wp:cover -->';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'New'));
    }

    /**
     * Test that Cover attribute JSON is encoded exactly like core's
     * serialize_block_attributes(), so the editor sees an unchanged block.
     */
    public function test_cover_attribute_encoding_matches_core(): void
    {
        $html = '<!-- wp:cover {"id":42,"url":"https://example.com/a/b.jpg"} --><div class="wp-block-cover"><img class="wp-image-42" alt="" src="a.jpg"/></div><!-- /wp:cover -->';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, 'A "big" <dog> & a -- cat, café');

        // Built from chr(92) so the expected escapes cannot be mangled by an editor.
        $u = chr(92) . 'u';

        $this->assertStringContainsString(
            '{"id":42,"url":"https://example.com/a/b.jpg","alt":"A ' . $u . '0022big' . $u . '0022 ' . $u . '003cdog' . $u . '003e ' . $u . '0026 a ' . $u . '002d' . $u . '002d cat, café"}',
            $result
        );

        // The JSON must still decode back to the exact value.
        preg_match('/wp:cover (\{.*?\}) -->/', $result, $match);
        $decoded = json_decode($match[1], true);
        $this->assertSame('A "big" <dog> & a -- cat, café', $decoded['alt']);
    }

    /**
     * Regression (second audit, bug 6): WordPress writes a backslash in a
     * block comment as \u005c, AltRelay wrote it as \\. Both decode the
     * same, but the content was not what the editor would have saved.
     */
    public function test_cover_comment_writes_a_backslash_like_core(): void
    {
        $html = '<!-- wp:cover {"id":42} --><div class="wp-block-cover"><img class="wp-image-42" alt="" src="a.jpg"/></div><!-- /wp:cover -->';
        $alt = 'C:' . chr(92) . 'Photos' . chr(92) . '"dog"';

        $result = AltRelay_Content::rewrite_attachment_alt($html, 42, $alt);
        $u = chr(92) . 'u';

        $this->assertStringContainsString('"alt":"C:' . $u . '005cPhotos' . $u . '005c' . $u . '0022dog' . $u . '0022"', $result);

        preg_match('/wp:cover (\{.*?\}) -->/', $result, $match);
        $this->assertSame($alt, json_decode($match[1], true)['alt']);
    }

    /**
     * Test that other Cover blocks and other blocks are byte-for-byte untouched.
     */
    public function test_rewrite_leaves_unrelated_blocks_untouched(): void
    {
        $html = '<!-- wp:cover {"id":7,"alt":"Other"} --><div class="wp-block-cover"><img class="wp-image-7" alt="Other" src="b.jpg"/></div><!-- /wp:cover -->'
            . '<!-- wp:paragraph {"style":{}} --><p>Hi</p><!-- /wp:paragraph -->'
            . '<!-- wp:acme/cover {"id":42,"alt":"Third party"} /-->';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'New'));
    }

    /**
     * Test marking an Image block decorative.
     */
    public function test_mark_image_block_decorative(): void
    {
        $html = '<!-- wp:image {"id":42,"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure>'
            . '<!-- /wp:image -->';

        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertSame(
            '<!-- wp:image {"id":42,"sizeSlug":"large","className":"altrelay-decorative"} -->'
            . '<figure class="wp-block-image size-large altrelay-decorative"><img src="a.jpg" alt="" class="wp-image-42"/></figure>'
            . '<!-- /wp:image -->',
            $result
        );
    }

    /**
     * Test that an existing custom class is kept when marking.
     */
    public function test_mark_keeps_existing_class(): void
    {
        $html = '<!-- wp:image {"id":42,"className":"is-style-rounded"} --><figure class="wp-block-image is-style-rounded"><img src="a.jpg" alt="" class="wp-image-42"/></figure><!-- /wp:image -->';

        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringContainsString('"className":"is-style-rounded altrelay-decorative"', $result);
        $this->assertStringContainsString('class="wp-block-image is-style-rounded altrelay-decorative"', $result);
    }

    /**
     * Test marking a Cover block decorative removes its comment alt.
     */
    public function test_mark_cover_block_decorative(): void
    {
        $html = '<!-- wp:cover {"id":42,"alt":"Dog"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="Dog" src="a.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';

        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringContainsString('<!-- wp:cover {"id":42,"className":"altrelay-decorative"} -->', $result);
        $this->assertStringContainsString('<div class="wp-block-cover altrelay-decorative">', $result);
        $this->assertStringContainsString('alt=""', $result);
        $this->assertStringNotContainsString('Dog', $result);
    }

    /**
     * Test marking a Media & Text block decorative.
     */
    public function test_mark_media_text_block_decorative(): void
    {
        $html = '<!-- wp:media-text {"mediaId":42,"mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="a.jpg" alt="Dog" class="wp-image-42 size-full"/></figure><div class="wp-block-media-text__content"></div></div><!-- /wp:media-text -->';

        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringContainsString('{"mediaId":42,"mediaType":"image","className":"altrelay-decorative"}', $result);
        $this->assertStringContainsString('<div class="wp-block-media-text altrelay-decorative">', $result);
        $this->assertStringContainsString('alt=""', $result);
    }

    /**
     * Test marking a classic (non-block) image puts the class on the img.
     */
    public function test_mark_classic_image_decorative(): void
    {
        $html = '<p><img src="a.jpg" alt="Dog" class="alignnone wp-image-42" /></p>';

        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertSame('<p><img src="a.jpg" alt="" class="alignnone wp-image-42 altrelay-decorative" /></p>', $result);
    }

    /**
     * Test that marking twice changes nothing the second time.
     */
    public function test_mark_is_idempotent(): void
    {
        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:image -->'
            . '<p><img src="b.jpg" alt="Dog" class="wp-image-42" /></p>';

        $once = AltRelay_Content::mark_decorative($html, 42);

        $this->assertSame($once, AltRelay_Content::mark_decorative($once, 42));
        $this->assertSame(3, substr_count($once, 'altrelay-decorative'), 'Block JSON, wrapper, and the classic img; the block img itself stays unmarked.');
    }

    /**
     * Test that marking leaves other attachments alone.
     */
    public function test_mark_leaves_other_images(): void
    {
        $html = '<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="a.jpg" alt="Cat" class="wp-image-7"/></figure><!-- /wp:image -->';

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Test that a marked image survives a later sync untouched.
     */
    public function test_marked_image_survives_sync(): void
    {
        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:image -->';

        $marked = AltRelay_Content::mark_decorative($html, 42);

        $this->assertSame($marked, AltRelay_Content::rewrite_attachment_alt($marked, 42, 'Something new'));
    }

    /**
     * Test the site-wide opt-out filter.
     */
    public function test_empty_alt_filter(): void
    {
        global $altrelay_test_state;

        $this->assertFalse(AltRelay_Content::empty_alt_is_decorative(42));

        $altrelay_test_state['filters']['altrelay_treat_empty_alt_as_decorative'] = array(
            static fn(bool $value, int $id): bool => 42 === $id,
        );

        $this->assertTrue(AltRelay_Content::empty_alt_is_decorative(42));
        $this->assertFalse(AltRelay_Content::empty_alt_is_decorative(7));
    }

    /**
     * Test that content without images is returned untouched and cheaply.
     */
    public function test_content_without_images(): void
    {
        $html = '<p>No images here.</p>';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'Dog'));
        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
        $this->assertSame(array(), AltRelay_Content::find_images($html));
    }

    /**
     * Use core's Image block decorative setting, as on WordPress 7.1+.
     */
    private function use_core_decorative(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_core_decorative'] = array(
            static fn(): bool => true,
        );
    }

    /**
     * Test that images hidden from assistive technology count as decorative.
     *
     * Regression test: WordPress 7.1 saves role="none" for images marked with
     * its own decorative checkbox, and AltRelay filled them anyway.
     */
    public function test_hidden_images_are_decorative(): void
    {
        $html = '<img src="a.jpg" alt="" class="wp-image-1" role="none"/>'
            . '<img src="b.jpg" alt="" class="wp-image-2" role="Presentation"/>'
            . '<img src="c.jpg" alt="" class="wp-image-3" aria-hidden="true"/>'
            . '<img src="d.jpg" alt="" class="wp-image-4" role="img"/>';

        $flags = array_column(AltRelay_Content::find_images($html), 'decorative');

        $this->assertSame(array(true, true, true, false), $flags);
    }

    /**
     * Test that a core-decorative Image block survives a sync untouched.
     */
    public function test_rewrite_skips_core_decorative_image(): void
    {
        $html = '<!-- wp:image {"id":42,"isDecorative":true} --><figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42" role="none"/></figure><!-- /wp:image -->';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'Dog'));
    }

    /**
     * Test that marking uses core's setting for Image blocks where it exists,
     * exactly as the editor saves it.
     */
    public function test_mark_image_block_uses_core_setting(): void
    {
        $this->use_core_decorative();

        $html = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:image -->';

        $this->assertSame(
            '<!-- wp:image {"id":42,"sizeSlug":"large","isDecorative":true} --><figure class="wp-block-image size-large"><img role="none" src="a.jpg" alt="" class="wp-image-42"/></figure><!-- /wp:image -->',
            AltRelay_Content::mark_decorative($html, 42)
        );
    }

    /**
     * Test that Cover blocks keep using the class even with core's setting.
     */
    public function test_mark_cover_uses_class_with_core_setting(): void
    {
        $this->use_core_decorative();

        $html = '<!-- wp:cover {"id":42,"alt":"Dog"} --><div class="wp-block-cover"><img class="wp-image-42" alt="Dog" src="a.jpg"/></div><!-- /wp:cover -->';
        $result = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringContainsString('{"id":42,"className":"altrelay-decorative"}', $result);
        $this->assertStringNotContainsString('isDecorative', $result);
    }

    /**
     * Test that marking an already core-decorative block changes nothing.
     */
    public function test_mark_is_idempotent_with_core_setting(): void
    {
        $this->use_core_decorative();

        $html = '<!-- wp:image {"id":42,"isDecorative":true} --><figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42" role="none"/></figure><!-- /wp:image -->';

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Test that a classic image already hidden by role gets no extra class.
     */
    public function test_mark_classic_hidden_image_needs_no_class(): void
    {
        $html = '<p><img src="a.jpg" alt="Dog" class="wp-image-42" role="presentation" /></p>';

        $this->assertSame('<p><img src="a.jpg" alt="" class="wp-image-42" role="presentation" /></p>', AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Test that core's setting is not used when the Image block lacks it.
     */
    public function test_core_setting_detection_defaults_off_without_registry(): void
    {
        $this->assertFalse(AltRelay_Content::core_image_has_decorative());
    }

    /**
     * Describe an attachment's files the way WordPress stores them.
     *
     * @param int                   $id    Attachment ID.
     * @param string                $file  File relative to the uploads folder.
     * @param array<string, string> $sizes Size name => file name.
     * @param array<string, mixed>  $extra Extra metadata.
     */
    private function attachment(int $id, string $file, array $sizes = array(), array $extra = array()): void
    {
        global $altrelay_test_state;

        $altrelay_test_state['strict_image_match'] = true;
        $altrelay_test_state['post_meta'][$id]['_wp_attached_file'] = $file;
        $altrelay_test_state['post_meta'][$id]['_wp_attachment_metadata'] = array_merge(
            array(
                'file' => $file,
                'sizes' => array_map(static fn(string $name): array => array('file' => $name), $sizes),
            ),
            $extra
        );
    }

    /**
     * The image-matching cases shared with the JavaScript tests.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shared_matching_cases(): array
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/image-matching.json'), true);
        $cases = array();

        foreach ($fixture['cases'] as $case) {
            $cases[$case['label']] = array($case['url'], $case['matches']);
        }

        return $cases;
    }

    /**
     * Regression (audit bug 1): the file has to belong to the attachment,
     * and a wp-image-N class on someone else's file proves nothing.
     *
     * Regression (sixth audit): the rule exists in PHP and in the editor's
     * JavaScript. Both are tested against the same cases, from
     * tests/fixtures/image-matching.json, so they cannot drift apart.
     *
     * @param string $url     Image URL.
     * @param bool   $matches Whether it belongs to the attachment.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shared_matching_cases')]
    public function test_image_matching_follows_the_shared_cases(string $url, bool $matches): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/image-matching.json'), true);
        $this->attachment(42, $fixture['attachment']['file'], $fixture['attachment']['sizes']);

        $this->assertSame($matches, AltRelay_Content::image_matches_attachment($url, 42));
    }

    /**
     * Test the less common ways WordPress stores a file.
     */
    public function test_image_matching_handles_unusual_storage(): void
    {
        global $altrelay_test_state;

        // The original of an upload that was scaled down.
        $this->attachment(1, '2024/05/big-scaled.jpg', array(), array('original_image' => 'big.jpg'));
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2024/05/big.jpg', 1));

        // Uploads that are not organised by month.
        $this->attachment(2, 'flat.png', array('thumbnail' => 'flat-150x150.png'));
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/flat-150x150.png', 2));
        $this->assertTrue(AltRelay_Content::image_matches_attachment('flat.png', 2));
        $this->assertFalse(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/not-flat.png', 2));

        // No generated metadata, as for an SVG.
        $altrelay_test_state['post_meta'][3]['_wp_attached_file'] = '2024/05/logo.svg';
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2024/05/logo.svg', 3));

        // Metadata without a file of its own falls back to the attached file.
        $altrelay_test_state['post_meta'][4]['_wp_attached_file'] = '2024/05/odd.jpg';
        $altrelay_test_state['post_meta'][4]['_wp_attachment_metadata'] = array('sizes' => array('thumb' => array('file' => 'odd-thumb.jpg'), 'broken' => 'not an array'));
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2024/05/odd-thumb.jpg', 4));

        // Absolute paths from old installs, on either kind of system.
        $altrelay_test_state['post_meta'][5]['_wp_attached_file'] = '/var/www/html/wp-content/uploads/2011/02/old.jpg';
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2011/02/old.jpg', 5));
        $altrelay_test_state['post_meta'][6]['_wp_attached_file'] = 'C:\\inetpub\\uploads\\legacy.jpg';
        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://example.com/files/legacy.jpg', 6));

        // Something that is not an attachment at all.
        $this->assertFalse(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2024/05/photo.jpg', 7));
        $this->assertFalse(AltRelay_Content::image_matches_attachment('https://example.com/wp-content/uploads/2024/05/photo.jpg', 0));
    }

    /**
     * Test that the match can be overridden by a filter.
     */
    public function test_image_matching_can_be_filtered(): void
    {
        global $altrelay_test_state;

        $this->attachment(42, '2024/05/photo.jpg');

        $seen = array();
        $altrelay_test_state['filters']['altrelay_image_matches_attachment'] = array(
            static function (bool $matches, string $src, int $id) use (&$seen): bool {
                $seen[] = array($matches, $src, $id);
                return true;
            },
        );

        $this->assertTrue(AltRelay_Content::image_matches_attachment('https://offload.example/a8f3.jpg', 42));
        $this->assertSame(array(array(false, 'https://offload.example/a8f3.jpg', 42)), $seen);
    }

    /**
     * Regression (audit bug 1): pasted markup with a foreign file is invisible
     * to every operation, while the attachment's own copies still work.
     */
    public function test_foreign_images_are_never_visited(): void
    {
        $this->attachment(42, '2024/05/photo.jpg');

        $foreign = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="https://other.example/wp-content/uploads/2019/03/stranger.jpg" alt="" class="wp-image-42"/></figure><!-- /wp:image -->';
        $own = '<p><img src="https://example.com/wp-content/uploads/2024/05/photo-300x200.jpg" alt="" class="wp-image-42" /></p>';

        $this->assertSame(array(), AltRelay_Content::find_images($foreign));
        $this->assertSame($foreign, AltRelay_Content::rewrite_attachment_alt($foreign, 42, 'A local dog'));
        $this->assertSame($foreign, AltRelay_Content::mark_decorative($foreign, 42));

        $this->assertSame(
            $foreign . str_replace('alt=""', 'alt="A local dog"', $own),
            AltRelay_Content::rewrite_attachment_alt($foreign . $own, 42, 'A local dog')
        );
    }

    /**
     * Test that the URL is found where lazy-loading scripts put it.
     */
    public function test_image_url_is_read_from_lazy_loading_attributes(): void
    {
        $this->attachment(42, '2024/05/photo.jpg');

        $url = 'https://example.com/wp-content/uploads/2024/05/photo.jpg';

        $lazy = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" data-src="' . $url . '" alt="" class="wp-image-42" />'
            . '<img data-lazy-src="' . $url . '" alt="" class="wp-image-42" />'
            . '<img alt="" class="wp-image-42" />';

        $images = AltRelay_Content::find_images($lazy);

        $this->assertCount(2, $images, 'The image without any URL cannot be matched.');
        $this->assertSame($url, $images[0]['src']);
        $this->assertSame($url, $images[1]['src']);
    }

    /**
     * Markup around an image, and whether the image is the link's only content.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function linked_markup(): array
    {
        $img = '<img src="a.jpg" alt="" class="wp-image-42" />';

        return array(
            'no link' => array('<p>' . $img . '</p>', false),
            'a link around the image only' => array('<a href="/x">' . $img . '</a>', true),
            'a link with whitespace only' => array("<a href=\"/x\">\n\t" . $img . " </a>", true),
            'a link through nested elements' => array('<a href="/x"><span><picture>' . $img . '</picture></span></a>', true),
            'an image block link' => array('<figure class="wp-block-image"><a href="/x">' . $img . '</a><figcaption>Caption outside the link</figcaption></figure>', true),
            'a link with text' => array('<a href="/x">' . $img . ' Read more</a>', false),
            'a link with text before the image' => array('<a href="/x">Read more ' . $img . '</a>', false),
            'a link with nested text' => array('<a href="/x">' . $img . '<span>Read more</span></a>', false),
            'a link with aria-label' => array('<a href="/x" aria-label="Read more">' . $img . '</a>', false),
            'a link with aria-labelledby' => array('<a href="/x" aria-labelledby="t">' . $img . '</a>', false),
            'a link with a title' => array('<a href="/x" title="Read more">' . $img . '</a>', false),
            'a link with an empty label' => array('<a href="/x" aria-label=" ">' . $img . '</a>', true),
            'a link with another described image' => array('<a href="/x">' . $img . '<img src="logo.png" alt="Acme" /></a>', false),
            'a link with another undescribed image' => array('<a href="/x">' . $img . '<img src="spacer.gif" alt="" /></a>', true),
            'a link with a hidden described image' => array('<a href="/x">' . $img . '<img src="logo.png" alt="Acme" aria-hidden="true" /></a>', true),
            'an anchor without href' => array('<a name="top">' . $img . '</a>', false),
            'text after the link' => array('<a href="/x">' . $img . '</a> Read more', true),
            'an image after the link' => array('<a href="/x">Text</a>' . $img, false),
        );
    }

    /**
     * Test that find_images() knows when an image is all a link contains.
     *
     * @param string $html   Markup.
     * @param bool   $linked Whether the image is the link's only content.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('linked_markup')]
    public function test_find_images_reports_linked_images(string $html, bool $linked): void
    {
        $images = AltRelay_Content::find_images($html);

        $this->assertSame($linked, $images[0]['linked']);
    }

    /**
     * Regression (fifth audit): the scan closed an element only at its end
     * tag, so a paragraph or link left open reached every image after it.
     * A browser ends them where the next block, or the next link, starts.
     */
    public function test_elements_left_open_end_where_a_browser_ends_them(): void
    {
        $img = static fn(int $id): string => '<img src="a.jpg" alt="" class="wp-image-' . $id . '" />';

        // A marked paragraph left open ends at the next block.
        $images = AltRelay_Content::find_images('<p class="altrelay-decorative">' . $img(1) . '<figure>' . $img(2) . '</figure><p>' . $img(3));
        $this->assertSame(array(true, false, false), array_column($images, 'decorative'));

        // Not past a button: the paragraph around it is still open inside.
        $images = AltRelay_Content::find_images('<p class="altrelay-decorative"><button>' . $img(1) . '<div>' . $img(2) . '</div></button>');
        $this->assertSame(array(true, true), array_column($images, 'decorative'));

        // A link left open ends where the next link starts.
        $images = AltRelay_Content::find_images('<a href="/one">' . $img(1) . '<a href="/two">Two</a>' . $img(3));
        $this->assertSame(array(true, false), array_column($images, 'linked'));

        // Inline elements do not end a paragraph.
        $images = AltRelay_Content::find_images('<p class="altrelay-decorative"><span>' . $img(1) . '</span><em>' . $img(2) . '</em>');
        $this->assertSame(array(true, true), array_column($images, 'decorative'));
    }

    /**
     * Test the keys that tell image tags apart: the attributes that matter,
     * not those another pass may add, nor the alt text.
     */
    public function test_image_tag_keys(): void
    {
        $tag = '<img src="a.jpg" alt="" class="wp-image-42" style="border:0"/>';
        $key = AltRelay_Content::image_tag_key($tag);

        $this->assertSame("class=wp-image-42\nsrc=a.jpg\nstyle=border:0", $key);
        $this->assertSame($key, AltRelay_Content::image_tag_key('<img loading="lazy" decoding="async" width="10" height="10" style="border:0" src="a.jpg" alt="Filled" class="wp-image-42" srcset="a.jpg 1x" sizes="10px"/>'));
        $this->assertNotSame($key, AltRelay_Content::image_tag_key('<img src="a-300x200.jpg" alt="" class="wp-image-42" style="border:0"/>'));
        $this->assertSame('', AltRelay_Content::image_tag_key('<p>No image</p>'));

        $html = '<figure class="altrelay-decorative">' . $tag . '<img src="b.jpg" alt="" class="wp-image-43"/></figure><img src="c.jpg" alt="" class="wp-image-42"/><figure class="altrelay-decorative"><img src="d.jpg" alt="Kept" class="wp-image-42"/></figure>';

        $this->assertSame(array($key => true), AltRelay_Content::decorative_image_keys($html, array(42 => true)), 'Only the decorative, empty copy of 42.');
        $this->assertSame(array(), AltRelay_Content::decorative_image_keys($html, array()));
    }

    /**
     * Test that an image's own alt text does not make its link "named"
     * for the purpose of marking it decorative: that text is about to go.
     */
    public function test_own_alt_text_does_not_name_the_link(): void
    {
        $images = AltRelay_Content::find_images('<a href="/x"><img src="a.jpg" alt="Dog" class="wp-image-42" /></a>');

        $this->assertTrue($images[0]['linked']);
    }

    /**
     * Regression (audit bug 7): a linked image is refused, in a block and
     * outside one, and the caller learns how many were refused.
     */
    public function test_mark_refuses_images_that_are_the_only_content_of_a_link(): void
    {
        $block = '<!-- wp:image {"id":42,"linkDestination":"custom"} --><figure class="wp-block-image"><a href="https://example.com/"><img src="a.jpg" alt="Dog" class="wp-image-42"/></a><figcaption class="wp-element-caption">Rex</figcaption></figure><!-- /wp:image -->';
        $classic = '<p><a href="https://example.com/"><img src="b.jpg" alt="Dog" class="wp-image-42" /></a></p>';
        $free = '<p><img src="c.jpg" alt="Dog" class="wp-image-42" /></p>';

        $refused = -1;
        $result = AltRelay_Content::mark_decorative($block . $classic . $free, 42, $refused);

        $this->assertSame(2, $refused);
        $this->assertSame(
            $block . $classic . '<p><img src="c.jpg" alt="" class="wp-image-42 altrelay-decorative" /></p>',
            $result
        );
    }

    /**
     * Test that the refusal applies to core's decorative setting as well.
     */
    public function test_mark_refuses_linked_images_with_core_setting(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_core_decorative'] = array(static fn(): bool => true);

        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><a href="https://example.com/"><img src="a.jpg" alt="Dog" class="wp-image-42"/></a></figure><!-- /wp:image -->';

        $refused = 0;

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42, $refused));
        $this->assertSame(1, $refused);
    }

    /**
     * Test that a linked image that is already decorative is not counted as
     * refused: there is nothing left to ask for.
     */
    public function test_already_decorative_linked_image_is_not_refused(): void
    {
        $html = '<a href="/x"><img src="a.jpg" alt="" class="wp-image-42 altrelay-decorative" /></a>';

        $refused = 0;

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42, $refused));
        $this->assertSame(0, $refused);
    }

    /**
     * Test that an image in a link with other content can be marked, and
     * that a caption is kept.
     */
    public function test_mark_keeps_captions_and_allows_links_with_text(): void
    {
        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><a href="/x"><img src="a.jpg" alt="Dog" class="wp-image-42"/> More about Rex</a><figcaption class="wp-element-caption">Rex</figcaption></figure><!-- /wp:image -->';

        $refused = 0;
        $result = AltRelay_Content::mark_decorative($html, 42, $refused);

        $this->assertSame(0, $refused);
        $this->assertSame(
            '<!-- wp:image {"id":42,"className":"altrelay-decorative"} --><figure class="wp-block-image altrelay-decorative"><a href="/x"><img src="a.jpg" alt="" class="wp-image-42"/> More about Rex</a><figcaption class="wp-element-caption">Rex</figcaption></figure><!-- /wp:image -->',
            $result
        );
    }

    /**
     * Regression (audit bug 8): a void block has no markup of its own, so
     * marking it must not reach into the block that follows.
     */
    public function test_mark_void_block_leaves_the_next_image_alone(): void
    {
        global $altrelay_test_state;

        $html = '<!-- wp:image {"id":42} /-->' . "\n\n"
            . '<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="b.jpg" alt="A cat" class="wp-image-7"/></figure><!-- /wp:image -->';

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));

        $altrelay_test_state['filters']['altrelay_use_core_decorative'] = array(static fn(): bool => true);

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Test that a block is only changed through its own markup: a block
     * whose image is not in its segment stays as it is.
     */
    public function test_mark_block_without_its_image_leaves_later_content_alone(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_core_decorative'] = array(static fn(): bool => true);

        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"></figure><!-- /wp:image -->'
            . '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->'
            . '<!-- wp:html --><img src="b.jpg" alt="A cat" class="wp-image-7"/><!-- /wp:html -->';

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Test that a block's ID is not trusted over its markup.
     */
    public function test_mark_block_whose_markup_shows_another_image(): void
    {
        $html = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="b.jpg" alt="A cat" class="wp-image-7"/></figure><!-- /wp:image -->';

        $this->assertSame($html, AltRelay_Content::mark_decorative($html, 42));
    }

    /**
     * Regression (audit bug 11): a Cover inside a group that carries the
     * mark is decorative through the group. Its image is left alone, so its
     * comment has to be left alone too, or the block stops validating.
     */
    public function test_cover_inside_marked_group_keeps_comment_and_image_in_step(): void
    {
        $html = '<!-- wp:group {"className":"altrelay-decorative"} --><div class="wp-block-group altrelay-decorative">'
            . '<!-- wp:cover {"url":"t.jpg","id":42,"alt":"Old"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="Old" src="t.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->'
            . '</div><!-- /wp:group -->';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'New'));
    }

    /**
     * Test that a Cover whose empty alt is respected keeps its comment.
     */
    public function test_cover_with_respected_empty_alt_keeps_comment(): void
    {
        $html = '<!-- wp:cover {"url":"t.jpg","id":42} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="" src="t.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';

        $this->assertSame($html, AltRelay_Content::rewrite_attachment_alt($html, 42, 'New', true));
    }

    /**
     * Test that a comment that disagrees with its image is brought in line
     * with the image, not the other way round.
     */
    public function test_cover_comment_follows_the_markup(): void
    {
        $html = '<!-- wp:cover {"url":"t.jpg","id":42,"alt":"Stale"} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-42" alt="New" src="t.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';

        $this->assertSame(
            str_replace('"alt":"Stale"', '"alt":"New"', $html),
            AltRelay_Content::rewrite_attachment_alt($html, 42, 'New')
        );
    }

    /**
     * Test a Cover with a fixed background. It has no <img>: the editor
     * saves a <div> with role="img" and aria-label, and neither when the
     * alt is empty.
     */
    public function test_fixed_cover_background_is_handled_like_an_image(): void
    {
        $with = '<!-- wp:cover {"url":"t.jpg","id":42,"alt":"Old","hasParallax":true} --><div class="wp-block-cover has-parallax"><div role="img" aria-label="Old" class="wp-block-cover__image-background wp-image-42 has-parallax" style="background-position:50% 50%;background-image:url(t.jpg)"></div><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
        $without = '<!-- wp:cover {"url":"t.jpg","id":42,"hasParallax":true} --><div class="wp-block-cover has-parallax"><div class="wp-block-cover__image-background wp-image-42 has-parallax" style="background-position:50% 50%;background-image:url(t.jpg)"></div><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';

        $images = AltRelay_Content::find_images($with . $without);

        $this->assertSame(array('Old', ''), array_column($images, 'alt'));
        $this->assertSame(array('t.jpg', 't.jpg'), array_column($images, 'src'));

        // Changing the description changes label and comment together.
        $this->assertSame(
            str_replace(array('"alt":"Old"', 'aria-label="Old"'), array('"alt":"New"', 'aria-label="New"'), $with),
            AltRelay_Content::rewrite_attachment_alt($with, 42, 'New')
        );

        // Clearing it removes role, label and comment attribute. The HTML
        // API leaves the spaces of removed attributes behind, which the
        // editor's block validation does not care about.
        $this->assertSame($without, preg_replace('/ {2,}/', ' ', AltRelay_Content::rewrite_attachment_alt($with, 42, '')));

        // Filling an empty one adds all three.
        $filled = AltRelay_Content::rewrite_attachment_alt($without, 42, 'New');

        $this->assertStringContainsString('"alt":"New"', $filled);
        $this->assertStringContainsString('role="img"', $filled);
        $this->assertStringContainsString('aria-label="New"', $filled);

        // Marking it decorative empties it and marks the block.
        $marked = AltRelay_Content::mark_decorative($with, 42);

        $this->assertStringContainsString('"className":"altrelay-decorative"', $marked);
        $this->assertStringNotContainsString('aria-label', $marked);
        $this->assertStringNotContainsString('"alt"', $marked);
        $this->assertSame($marked, AltRelay_Content::rewrite_attachment_alt($marked, 42, 'Ignored'));
    }

    /**
     * Test that the URL of a background is read from its style.
     */
    public function test_background_url_is_read_from_the_style(): void
    {
        $this->attachment(42, '2024/05/photo.jpg');

        $own = '<div class="wp-block-cover__image-background wp-image-42" style="background-image:url(\'https://example.com/wp-content/uploads/2024/05/photo.jpg\')"></div>';
        $foreign = '<div class="wp-block-cover__image-background wp-image-42" style="background-image:url(https://other.example/x.jpg)"></div>';
        $bare = '<div class="wp-block-cover__image-background wp-image-42"></div>';

        $this->assertCount(1, AltRelay_Content::find_images($own . $foreign . $bare));
    }

    /**
     * Test that rewrite_alts() writes several attachments in one pass and
     * reports which of them changed.
     */
    public function test_rewrite_alts_handles_several_attachments(): void
    {
        $html = '<img src="a.jpg" alt="old" class="wp-image-1" />'
            . '<img src="b.jpg" alt="Cat" class="wp-image-2" />'
            . '<img src="c.jpg" alt="untouched" class="wp-image-3" />'
            . '<img src="d.jpg" alt="" class="wp-image-4 altrelay-decorative" />';

        $alts = array(1 => 'Dog', 2 => 'Cat', 4 => 'Bird');
        $changed = array('stale');

        $result = AltRelay_Content::rewrite_alts(
            $html,
            static fn(int $id): ?string => $alts[$id] ?? null,
            null,
            $changed
        );

        $this->assertSame(str_replace('alt="old"', 'alt="Dog"', $html), $result);
        $this->assertSame(array(1), $changed);
    }

    /**
     * Test removing AltRelay's class from a block and its wrapper.
     */
    public function test_unmark_block_with_class(): void
    {
        $plain = '<!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:image -->';
        $styled = '<!-- wp:cover {"url":"t.jpg","id":42,"className":"is-style-wide"} --><div class="wp-block-cover is-style-wide"><img class="wp-block-cover__image-background wp-image-42" alt="" src="t.jpg"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';

        $marked = AltRelay_Content::mark_decorative($plain . $styled, 42);

        $this->assertSame(
            str_replace('alt="Dog"', 'alt=""', $plain) . $styled,
            AltRelay_Content::unmark_decorative($marked, 42),
            'Unmarking restores everything but the alt text, which the caller fills.'
        );
    }

    /**
     * Test removing core's decorative setting from an Image block.
     */
    public function test_unmark_block_with_core_setting(): void
    {
        global $altrelay_test_state;
        $altrelay_test_state['filters']['altrelay_use_core_decorative'] = array(static fn(): bool => true);

        $html = '<!-- wp:image {"id":42,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:image -->';

        $marked = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringContainsString('"isDecorative":true', $marked);
        $this->assertStringContainsString('role="none"', $marked);
        $this->assertSame(str_replace('alt="Dog"', 'alt=""', $html), AltRelay_Content::unmark_decorative($marked, 42));
    }

    /**
     * Test removing the class from an image outside a block.
     */
    public function test_unmark_classic_image(): void
    {
        $this->assertSame(
            '<p><img src="a.jpg" alt="" class="alignnone wp-image-42" /><img src="b.jpg" alt="" class="wp-image-7 altrelay-decorative" /></p>',
            AltRelay_Content::unmark_decorative('<p><img src="a.jpg" alt="" class="alignnone wp-image-42 altrelay-decorative" /><img src="b.jpg" alt="" class="wp-image-7 altrelay-decorative" /></p>', 42)
        );
    }

    /**
     * Test that marks AltRelay did not write are left alone.
     */
    public function test_unmark_leaves_foreign_marks_alone(): void
    {
        $group = '<!-- wp:group {"className":"altrelay-decorative"} --><div class="wp-block-group altrelay-decorative"><!-- wp:image {"id":42} --><figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-42"/></figure><!-- /wp:image --></div><!-- /wp:group -->';
        $role = '<p><img src="b.jpg" alt="" role="presentation" class="wp-image-42" /></p>';
        $other = '<!-- wp:image {"id":7,"className":"altrelay-decorative"} --><figure class="wp-block-image altrelay-decorative"><img src="c.jpg" alt="" class="wp-image-7"/></figure><!-- /wp:image -->';

        $this->assertSame($group . $role . $other, AltRelay_Content::unmark_decorative($group . $role . $other, 42));
    }

    /**
     * Test that the dashboard switch decides how an empty alt is treated,
     * and that the filter has the last word.
     */
    public function test_review_first_switch_sets_the_default(): void
    {
        global $altrelay_test_state;

        $this->assertFalse(AltRelay_Content::review_first());
        $this->assertFalse(AltRelay_Content::empty_alt_is_decorative());

        $altrelay_test_state['options']['altrelay_review_first'] = '1';

        $this->assertTrue(AltRelay_Content::review_first());
        $this->assertTrue(AltRelay_Content::empty_alt_is_decorative(42));

        $received = null;
        $altrelay_test_state['filters']['altrelay_treat_empty_alt_as_decorative'] = array(
            static function (bool $default) use (&$received): bool {
                $received = $default;
                return false;
            },
        );

        $this->assertFalse(AltRelay_Content::empty_alt_is_decorative(42));
        $this->assertTrue($received);
    }

    /**
     * Test that block comments are matched by their real boundaries.
     */
    public function test_blocks_from_other_namespaces_and_closers_are_ignored(): void
    {
        $html = '<!-- wp:acme/image {"id":42} --><figure><img src="a.jpg" alt="Dog" class="wp-image-42"/></figure><!-- /wp:acme/image -->';

        $marked = AltRelay_Content::mark_decorative($html, 42);

        $this->assertStringNotContainsString('"className"', $marked, 'A third-party block comment is not rewritten.');
        $this->assertStringContainsString('class="wp-image-42 altrelay-decorative"', $marked, 'Its image is marked like a classic image.');
    }
}
