<?php
/**
 * AltRelay Frontend Fallback.
 *
 * Fills missing alt text from the Media Library at render time, without
 * saving anything. This catches images inserted before the plugin was active
 * and images whose sync has not run yet.
 *
 * It hooks render_block, so it covers post content, synced patterns,
 * templates, template parts and block widgets alike. Classic content is
 * rendered through do_blocks() as a freeform block, so it passes through the
 * same filter. Classic text widgets are covered through their own filter.
 *
 * Content that is not rendered through blocks at all, such as the output of
 * a page builder that keeps its layout in post meta, is not covered. Such
 * code can call altrelay_fill_alt_text() on its HTML.
 *
 * Other plugins fill empty alt text on display too, and run after AltRelay.
 * They cannot tell an image marked decorative from one nobody described, or
 * an image that only carries a local attachment's class from that
 * attachment. A last pass over the same content undoes what they wrote
 * into images AltRelay left empty on purpose.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Frontend
 *
 * @since 1.0.0
 */
class AltRelay_Frontend {


	/**
	 * Blocks whose output can contain a Media Library image of their own.
	 *
	 * The empty string is the freeform (classic) content between blocks.
	 * Container blocks are not listed: their inner blocks pass through the
	 * filter one by one. core/gallery is listed for galleries saved before
	 * WordPress 5.9, which hold their images directly.
	 *
	 * @var string[]
	 */
	private const BLOCKS = array( '', 'core/image', 'core/cover', 'core/media-text', 'core/gallery', 'core/html', 'core/freeform' );

	/**
	 * The filtered block list, resolved on first use.
	 *
	 * @var string[]|null
	 */
	private ?array $blocks = null;

	/**
	 * Key AltRelay adds to a parsed block that sits inside a block carrying
	 * the decorative class.
	 *
	 * @var string
	 */
	private const INSIDE_MARKED = 'altrelayInsideDecorative';

	/**
	 * Filters whose content AltRelay guards with a last pass.
	 *
	 * @var string[]
	 */
	private const GUARDED_FILTERS = array( 'the_content', 'widget_text_content', 'widget_block_content' );

	/**
	 * For each content pass in progress, innermost last, the images AltRelay
	 * left empty on purpose: decorative ones, and ones whose file belongs to
	 * another attachment than their class names. By attachment ID.
	 *
	 * @var list<array{decorative: array<int, true>, foreign: array<int, true>}>
	 */
	private array $passes = array();

	/**
	 * The tags of the images a finished content pass left decorative and
	 * empty, by AltRelay_Content::image_tag_key(). Block themes run the whole
	 * page through wp_content_img_tag again once the content is done, where
	 * the mark on a block around an image is no longer known. Kept by tag,
	 * not by attachment, so a copy of the same image elsewhere on the page
	 * is not taken for decorative.
	 *
	 * @var array<string, true>
	 */
	private array $kept_decorative = array();

	/**
	 * The image tag as it entered wp_content_img_tag, before other plugins.
	 *
	 * @var string|null
	 */
	private ?string $tag_before = null;

	/**
	 * Register the render filters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'prime_attachment_meta' ), 8 );
		add_action( 'render_block_core_template_part_post', array( $this, 'prime_template_part' ), 10, 4 );
		add_action( 'render_block_core_template_part_file', array( $this, 'prime_template_part' ), 10, 4 );
		add_filter( 'render_block_data', array( $this, 'note_marked_ancestor' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'fill_block' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'fill_missing_alt_text' ), 20 );
		add_filter( 'widget_text_content', array( $this, 'fill_missing_alt_text' ), 20 );

		foreach ( self::GUARDED_FILTERS as $filter ) {
			add_filter( $filter, array( $this, 'begin_pass' ), PHP_INT_MIN );
			add_filter( $filter, array( $this, 'guard_content' ), PHP_INT_MAX );
		}

		add_filter( 'wp_content_img_tag', array( $this, 'note_image_tag' ), PHP_INT_MIN );
		add_filter( 'wp_content_img_tag', array( $this, 'guard_image_tag' ), PHP_INT_MAX );
	}

	/**
	 * Start remembering the images AltRelay leaves empty in this content.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content The content.
	 * @return mixed The content, unchanged.
	 */
	public function begin_pass( mixed $content ): mixed {
		$this->passes[] = array(
			'decorative' => array(),
			'foreign'    => array(),
		);

		return $content;
	}

	/**
	 * Undo alt text that other plugins wrote into images AltRelay left empty
	 * on purpose, once every filter has run on the content.
	 *
	 * Only images AltRelay saw empty in this content are touched, so alt text
	 * an author wrote stays. An image marked decorative gets its empty alt
	 * back, whatever was written into it. An image whose file is not the
	 * attachment's gets it back when it was given that attachment's alt
	 * text, which describes a different image; other text is left alone.
	 *
	 * Content rendered inside this content, such as the posts of a Query
	 * Loop, has its own pass. What it saw is passed on to this one, because
	 * a filter of this content may write into those images too.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content The content, after every other filter.
	 * @return mixed The content.
	 */
	public function guard_content( mixed $content ): mixed {
		$seen = array_pop( $this->passes );

		if ( null === $seen ) {
			return $content;
		}

		$outer = array_key_last( $this->passes );

		if ( null !== $outer ) {
			$this->passes[ $outer ]['decorative'] += $seen['decorative'];
			$this->passes[ $outer ]['foreign']    += $seen['foreign'];
		}

		if ( ( empty( $seen['decorative'] ) && empty( $seen['foreign'] ) ) || ! is_string( $content ) || self::is_admin_screen() || ! self::guard_enabled() ) {
			return $content;
		}

		$content = AltRelay_Content::walk_all_images(
			$content,
			static function ( array $image ) use ( $seen ): ?string {
				$id = $image['attachment_id'];

				if ( $image['background'] ) {
					return null;
				}

				if ( $image['decorative'] && isset( $seen['decorative'][ $id ] ) ) {
					return '';
				}

				return $image['foreign'] && isset( $seen['foreign'][ $id ] ) && self::is_library_alt( (string) $image['alt'], $id ) ? '' : null;
			},
			static function ( array $image ) use ( $seen ): bool {
				$id = $image['attachment_id'];

				return ! self::is_empty_alt( $image['alt'] ) && ( isset( $seen['foreign'][ $id ] ) || ( $image['decorative'] && isset( $seen['decorative'][ $id ] ) ) );
			}
		);

		$this->kept_decorative += AltRelay_Content::decorative_image_keys( $content, $seen['decorative'] );

		return $content;
	}

	/**
	 * Remember an image tag before other plugins change it.
	 *
	 * Core runs every image in content, widgets and block templates through
	 * wp_content_img_tag, one at a time, and several plugins fill alt text
	 * there. This runs first and guard_image_tag() last, for the same image.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $filtered_image The image tag.
	 * @return mixed The image tag, unchanged.
	 */
	public function note_image_tag( mixed $filtered_image ): mixed {
		$this->tag_before = is_string( $filtered_image ) ? $filtered_image : null;

		return $filtered_image;
	}

	/**
	 * Undo alt text that other plugins wrote into one image tag.
	 *
	 * Covers images outside post content, such as those in block templates,
	 * which the last pass over content does not see. An image counts as
	 * decorative here by its own markup (the decorative class on the image,
	 * a role of "none" or "presentation", or aria-hidden), or when a content
	 * pass already saw it decorative and empty: a block theme runs the whole
	 * page through this filter again after the content.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $filtered_image The image tag, after every other filter.
	 * @return mixed The image tag.
	 */
	public function guard_image_tag( mixed $filtered_image ): mixed {
		$before           = $this->tag_before;
		$this->tag_before = null;

		if ( null === $before || ! is_string( $filtered_image ) || $before === $filtered_image || self::is_admin_screen() ) {
			return $filtered_image;
		}

		$original = AltRelay_Content::read_image_tag( $before );

		if ( null === $original || ! self::is_empty_alt( $original['alt'] ) ) {
			return $filtered_image;
		}

		$now = AltRelay_Content::read_image_tag( $filtered_image );

		if ( null === $now || self::is_empty_alt( $now['alt'] ) || ! self::guard_enabled() ) {
			return $filtered_image;
		}

		$id      = $original['attachment_id'];
		$restore = $original['decorative'] || ( ! empty( $this->kept_decorative ) && isset( $this->kept_decorative[ AltRelay_Content::image_tag_key( $before ) ] ) ) || (
			self::is_library_alt( (string) $now['alt'], $id ) && ! AltRelay_Content::image_matches_attachment( $original['src'], $id )
		);

		return $restore ? AltRelay_Content::set_image_tag_alt( $filtered_image, $original['alt'] ) : $filtered_image;
	}

	/**
	 * Whether AltRelay undoes alt text that other plugins write into images
	 * it leaves empty on purpose.
	 *
	 * @return bool
	 */
	private static function guard_enabled(): bool {
		/**
		 * Filters whether AltRelay undoes alt text that other plugins add on
		 * display to images it leaves empty on purpose: images marked
		 * decorative, and images whose file belongs to another attachment
		 * than their wp-image-N class names.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether the guard runs. Default true.
		 */
		return (bool) apply_filters( 'altrelay_guard_alt_text', true );
	}

	/**
	 * Whether an alt is missing or empty.
	 *
	 * @param string|null $alt The alt text.
	 * @return bool
	 */
	private static function is_empty_alt( ?string $alt ): bool {
		return null === $alt || '' === $alt;
	}

	/**
	 * Whether alt text is an attachment's Media Library alt text, as stored
	 * or as AltRelay writes it.
	 *
	 * @param string $alt           The alt text found.
	 * @param int    $attachment_id The attachment ID.
	 * @return bool
	 */
	private static function is_library_alt( string $alt, int $attachment_id ): bool {
		$alt = trim( $alt );

		if ( '' === $alt ) {
			return false;
		}

		$stored = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return ( is_string( $stored ) && trim( $stored ) === $alt ) || AltRelay_Sync::library_alt( $attachment_id ) === $alt;
	}

	/**
	 * Remember an image AltRelay left empty on purpose, for the last pass
	 * over the content being rendered.
	 *
	 * @param 'decorative'|'foreign' $why           Why it was left empty.
	 * @param int                    $attachment_id The attachment ID from its class.
	 * @return void
	 */
	private function remember( string $why, int $attachment_id ): void {
		$pass = array_key_last( $this->passes );

		if ( null !== $pass ) {
			$this->passes[ $pass ][ $why ][ $attachment_id ] = true;
		}
	}

	/**
	 * Load the meta of every image in the post with one query.
	 *
	 * Runs before do_blocks() (priority 9), so the per-block filter below
	 * reads alt text and file names from the cache instead of querying once
	 * per image.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content The post content.
	 * @return mixed The content, unchanged.
	 */
	public function prime_attachment_meta( mixed $content ): mixed {
		if ( ! is_string( $content ) || false === strpos( $content, 'wp-image-' ) ) {
			return $content;
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $matches[1] ) ) ) );

			if ( ! empty( $ids ) ) {
				update_meta_cache( 'post', $ids );
			}
		}

		return $content;
	}

	/**
	 * Load the meta of every image in a template part with one query.
	 *
	 * A header or footer of a block theme is rendered outside the_content,
	 * so without this each image in it with an empty alt would cost a query
	 * of its own on a site without a persistent object cache. WordPress
	 * fires these actions with the part's content right before rendering it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $template_part_id The template part's ID. Unused.
	 * @param mixed $attributes       The block's attributes. Unused.
	 * @param mixed $source           The template part post or file. Unused.
	 * @param mixed $content          The template part's content.
	 * @return void
	 */
	public function prime_template_part( mixed $template_part_id, mixed $attributes, mixed $source, mixed $content ): void {
		unset( $template_part_id, $attributes, $source );

		$this->prime_attachment_meta( $content );
	}

	/**
	 * Remember that a block sits inside a block marked decorative.
	 *
	 * Blocks are rendered one at a time, innermost first, so by the time an
	 * Image block's HTML reaches render_block the Group around it is not
	 * part of that HTML. render_block_data runs before each block renders
	 * and knows its parent, so the mark is passed down from block to block
	 * here and read again in fill_block().
	 *
	 * The chain follows the blocks of the content, synced patterns included.
	 * A block that renders other posts' content, such as a Query Loop, starts
	 * a new chain: a class around it does not reach into those posts.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $parsed_block The block about to be rendered.
	 * @param mixed $source_block The block as it was parsed.
	 * @param mixed $parent_block The block it sits in, if any.
	 * @return mixed The block, with AltRelay's key added when it is inside a marked block.
	 */
	public function note_marked_ancestor( mixed $parsed_block, mixed $source_block = null, mixed $parent_block = null ): mixed {
		unset( $source_block );

		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		$parent_marked = $parent_block instanceof WP_Block
			&& ( ! empty( $parent_block->parsed_block[ self::INSIDE_MARKED ] ) || self::carries_marker( $parent_block->parsed_block ) );

		if ( $parent_marked ) {
			$parsed_block[ self::INSIDE_MARKED ] = true;
		}

		return $parsed_block;
	}

	/**
	 * Whether a parsed block carries AltRelay's class itself.
	 *
	 * @param array<mixed> $block The parsed block.
	 * @return bool
	 */
	private static function carries_marker( array $block ): bool {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		return AltRelay_Content::has_marker( $attrs['className'] ?? '' );
	}

	/**
	 * Fill missing alt text in one rendered block.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                $block_content The rendered block.
	 * @param array<string, mixed> $block         The parsed block.
	 * @return mixed
	 */
	public function fill_block( mixed $block_content, array $block = array() ): mixed {
		if ( ! is_string( $block_content ) || self::is_admin_screen() ) {
			return $block_content;
		}

		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';

		if ( ! in_array( $name, $this->blocks(), true ) ) {
			return $block_content;
		}

		// Every image inside a block marked decorative is decorative too.
		if ( ! empty( $block[ self::INSIDE_MARKED ] ) ) {
			// Remembered for the last pass. No file needs checking for that.
			if ( ! empty( $this->passes ) && false !== strpos( $block_content, 'wp-image-' ) ) {
				AltRelay_Content::walk_all_images(
					$block_content,
					static fn(): ?string => null,
					function ( array $image ): bool {
						if ( self::is_empty_alt( $image['alt'] ) ) {
							$this->remember( 'decorative', $image['attachment_id'] );
						}

						return false;
					}
				);
			}

			return $block_content;
		}

		return $this->fill_html( $block_content );
	}

	/**
	 * The blocks whose output is checked for images.
	 *
	 * @return string[]
	 */
	private function blocks(): array {
		if ( null === $this->blocks ) {
			/**
			 * Filters the blocks whose rendered output AltRelay checks for
			 * images with missing alt text.
			 *
			 * Add the name of a third-party block that outputs Media Library
			 * images with a wp-image-N class. Container blocks do not need
			 * to be listed; their inner blocks are checked one by one.
			 *
			 * @since 1.0.0
			 *
			 * @param string[] $blocks Block names. The empty string is classic content between blocks.
			 */
			$blocks = apply_filters( 'altrelay_frontend_blocks', self::BLOCKS );

			$this->blocks = array_values( array_filter( (array) $blocks, 'is_string' ) );
		}

		return $this->blocks;
	}

	/**
	 * Fallback for content that is rendered without do_blocks().
	 *
	 * When do_blocks() is hooked to the_content, every image has already
	 * passed through fill_block(), so the content is not parsed a second
	 * time. Classic text widgets never go through blocks and are always
	 * filled here.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content The post or widget content.
	 * @return mixed The content with alt text filled in where possible.
	 */
	public function fill_missing_alt_text( mixed $content ): mixed {
		if ( ! is_string( $content ) || self::is_admin_screen() ) {
			return $content;
		}

		if ( 'the_content' === current_filter() && false !== has_filter( 'the_content', 'do_blocks' ) ) {
			return $content;
		}

		return $this->fill_html( $content );
	}

	/**
	 * Whether this request renders an admin screen.
	 *
	 * The render filters leave admin screens alone. Ajax requests count as
	 * the frontend: themes use them to load more posts.
	 *
	 * @return bool
	 */
	private static function is_admin_screen(): bool {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Fill every image that has no usable alt text.
	 *
	 * An image is filled when its alt is missing or empty, unless it is marked
	 * decorative. An unmarked empty alt is what the block editor saves for an
	 * image inserted without alt text, so it means "not written yet", not
	 * "decorative".
	 *
	 * Works in any context, which is what altrelay_fill_alt_text() relies on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public function fill_html( string $html ): string {
		// Every image AltRelay handles carries a wp-image-N class.
		if ( false === strpos( $html, 'wp-image-' ) ) {
			return $html;
		}

		/**
		 * Filters whether AltRelay fills missing alt text when a page is rendered.
		 *
		 * Return false to switch the frontend fallback off. Alt text is then
		 * only ever written into post content by the sync itself.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether the fallback runs. Default true.
		 */
		$fill = (bool) apply_filters( 'altrelay_frontend_fallback', true );

		// While content is rendered, the images left empty on purpose are
		// remembered for the last pass, even with the fallback off.
		$remember = ! empty( $this->passes );

		if ( ! $fill && ! $remember ) {
			return $html;
		}

		// Everything that rules an image out is checked before its file is
		// matched to the attachment: most images on a page are described.
		return AltRelay_Content::walk_all_images(
			$html,
			function ( array $image ) use ( $fill ): ?string {
				if ( $image['foreign'] ) {
					$this->remember( 'foreign', $image['attachment_id'] );
					return null;
				}

				if ( ! $fill || ( '' === $image['alt'] && AltRelay_Content::empty_alt_is_decorative( $image['attachment_id'] ) ) ) {
					return null;
				}

				$alt = AltRelay_Sync::library_alt( $image['attachment_id'] );

				return '' === $alt ? null : $alt;
			},
			function ( array $image ) use ( $remember ): bool {
				if ( ! self::is_empty_alt( $image['alt'] ) ) {
					return false;
				}

				if ( $image['decorative'] ) {
					$this->remember( 'decorative', $image['attachment_id'] );
					return false;
				}

				// Nothing to fill from: not for AltRelay, and not for another
				// plugin that would put the wrong attachment's text in.
				if ( '' === AltRelay_Sync::library_alt( $image['attachment_id'] ) ) {
					return false;
				}

				// The file is only checked for an image AltRelay fills, or
				// for one whose file decides what the last pass does.
				return $remember || ! ( '' === $image['alt'] && AltRelay_Content::empty_alt_is_decorative( $image['attachment_id'] ) );
			}
		);
	}
}
