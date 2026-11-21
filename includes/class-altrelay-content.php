<?php
/**
 * AltRelay Content helpers.
 *
 * Every place AltRelay reads or writes image markup goes through this class,
 * so the sync engine, the frontend fallback, the dashboard and WP-CLI all
 * apply the same rules:
 *
 * - An image belongs to a Media Library item only when its wp-image-N class
 *   and its file agree. Markup pasted from another site keeps that site's
 *   IDs, and those must never pick up a local image's description.
 * - An image is decorative only when it is explicitly marked: by AltRelay's
 *   class on the image or on an element around it, by core's own decorative
 *   setting, or by a role that hides it from assistive technology.
 * - An empty alt without a mark means "not written yet", because the block
 *   editor saves alt="" for every image inserted without alt text.
 *
 * @package AltRelay
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AltRelay_Content
 *
 * @since 1.0.0
 */
class AltRelay_Content {


	/**
	 * Class that marks an image, or the block around it, as decorative.
	 *
	 * @since 1.0.0
	 */
	public const MARKER = 'altrelay-decorative';

	/**
	 * Image block attribute WordPress 7.1+ uses for its own decorative setting.
	 *
	 * @since 1.0.0
	 */
	public const CORE_DECORATIVE_ATTRIBUTE = 'isDecorative';

	/**
	 * Option that stores the "review first" switch.
	 *
	 * @since 1.0.0
	 */
	public const REVIEW_FIRST_OPTION = 'altrelay_review_first';

	/**
	 * Block attribute that holds custom classes.
	 */
	private const CLASS_ATTRIBUTE = 'className';

	/**
	 * Class of the element a Cover block uses for a fixed or repeated
	 * background. It is a <div> with role="img" and aria-label instead of an
	 * <img> with alt.
	 */
	private const BACKGROUND_CLASS = 'wp-block-cover__image-background';

	/**
	 * ARIA roles that remove an image from the accessibility tree.
	 *
	 * @var string[]
	 */
	private const HIDDEN_ROLES = array( 'none', 'presentation' );

	/**
	 * Attributes that hold the image URL, in the order they are trusted.
	 *
	 * Lazy-loading scripts move the URL out of src, so their attributes are
	 * read when src is empty or a placeholder.
	 *
	 * @var string[]
	 */
	private const SOURCE_ATTRIBUTES = array( 'src', 'data-src', 'data-lazy-src', 'data-orig-file' );

	/**
	 * Block comment delimiter pattern, identical to the one in WP_Block_Parser.
	 */
	private const DELIMITER_PATTERN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Supported media blocks, mapped to the attribute that holds the attachment ID.
	 *
	 * @var array<string, string>
	 */
	private const MEDIA_BLOCKS = array(
		'image'      => 'id',
		'cover'      => 'id',
		'media-text' => 'mediaId',
	);

	/**
	 * Elements that never have a closing tag.
	 *
	 * @var string[]
	 */
	private const VOID_TAGS = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Elements whose start tag ends a paragraph left open, as browsers read
	 * HTML: <p class="altrelay-decorative"> without </p> covers the images up
	 * to the next of these, not the rest of the content.
	 *
	 * @var string[]
	 */
	private const CLOSES_P = array( 'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'CENTER', 'DD', 'DETAILS', 'DIALOG', 'DIR', 'DIV', 'DL', 'DT', 'FIELDSET', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'FORM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'HGROUP', 'HR', 'LI', 'LISTING', 'MAIN', 'MENU', 'NAV', 'OL', 'P', 'PRE', 'SEARCH', 'SECTION', 'SUMMARY', 'TABLE', 'UL', 'XMP' );

	/**
	 * Elements an implied end of a paragraph or link does not reach past.
	 *
	 * @var string[]
	 */
	private const SCOPE_BOUNDARIES = array( 'APPLET', 'BUTTON', 'CAPTION', 'MARQUEE', 'OBJECT', 'TABLE', 'TD', 'TEMPLATE', 'TH' );

	/**
	 * Attributes left out when image tags are compared: the alt text, which
	 * other plugins fill, and what wp_filter_content_tags() adds where it is
	 * missing, which a second pass over the same tag may add.
	 *
	 * @var string[]
	 */
	private const TAG_KEY_IGNORED = array( 'alt', 'decoding', 'fetchpriority', 'height', 'loading', 'sizes', 'srcset', 'width' );

	/**
	 * Whether the Image block has core's own decorative setting.
	 *
	 * WordPress 7.1 added a "Mark as decorative" checkbox to the Image block.
	 * It stores isDecorative in the block and saves role="none" on the image.
	 * Where it exists, AltRelay uses it for Image blocks instead of its own
	 * class, so there is only one decorative setting for editors to learn.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function core_image_has_decorative(): bool {
		$supported = false;

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$type      = WP_Block_Type_Registry::get_instance()->get_registered( 'core/image' );
			$supported = null !== $type && is_array( $type->attributes ) && isset( $type->attributes[ self::CORE_DECORATIVE_ATTRIBUTE ] );
		}

		/**
		 * Filters whether AltRelay uses the Image block's own decorative setting.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $use Whether core's isDecorative is available and used. Default: detected.
		 */
		return (bool) apply_filters( 'altrelay_use_core_decorative', $supported );
	}

	/**
	 * Whether the "review first" switch on the dashboard is on.
	 *
	 * While it is on, an existing empty alt is left alone everywhere, so a
	 * site can mark its deliberate empty alts before AltRelay fills the rest.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function review_first(): bool {
		return '1' === get_option( self::REVIEW_FIRST_OPTION, '0' );
	}

	/**
	 * Whether an empty alt should be treated as decorative even without a mark.
	 *
	 * Off by default: an unmarked empty alt is almost always an image nobody
	 * described yet, and leaving an informative image silent is the worse
	 * accessibility failure of the two.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id The attachment ID, or 0 for a sitewide answer.
	 * @return bool
	 */
	public static function empty_alt_is_decorative( int $attachment_id = 0 ): bool {
		/**
		 * Filters whether an empty alt without the decorative mark is left alone.
		 *
		 * The default follows the "review first" switch on the dashboard.
		 * Return true to leave every unmarked empty alt alone permanently,
		 * or decide per attachment.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $is_decorative Whether to treat empty alt as decorative. Default: the review-first switch.
		 * @param int  $attachment_id The attachment ID, or 0 for a sitewide answer.
		 */
		return (bool) apply_filters( 'altrelay_treat_empty_alt_as_decorative', self::review_first(), $attachment_id );
	}

	/**
	 * Whether a class attribute value contains the decorative marker.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $class_names Class attribute value.
	 * @return bool
	 */
	public static function has_marker( mixed $class_names ): bool {
		return in_array( self::MARKER, self::class_list( $class_names ), true );
	}

	/**
	 * Read the attachment ID from an image's class attribute.
	 *
	 * Matches on whole class names, so wp-image-42 never matches wp-image-420.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $class_names Class attribute value.
	 * @return int Attachment ID, or 0.
	 */
	public static function attachment_id_from_class( mixed $class_names ): int {
		if ( ! is_string( $class_names ) || ! preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class_names, $found ) ) {
			return 0;
		}

		return absint( $found[1] );
	}

	/**
	 * Whether an image URL points at a file of the given attachment.
	 *
	 * The wp-image-N class alone proves nothing: blocks pasted from another
	 * site and imports without ID remapping keep the IDs of the site they
	 * came from. The file has to belong to the attachment as well: its
	 * original, one of its registered sizes, or a resized, scaled, rotated or
	 * edited variant of the same file in the same folder. Only the path is
	 * compared, so images served through a CDN still match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src           The image URL.
	 * @param int    $attachment_id The attachment ID from the wp-image-N class.
	 * @return bool
	 */
	public static function image_matches_attachment( string $src, int $attachment_id ): bool {
		/**
		 * Filters whether an image in content belongs to an attachment.
		 *
		 * Return true for setups AltRelay cannot recognise by file name, for
		 * example media offloaded under different file names.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $matches       Whether the URL points at a file of the attachment.
		 * @param string $src           The image URL.
		 * @param int    $attachment_id The attachment ID.
		 */
		return (bool) apply_filters( 'altrelay_image_matches_attachment', self::file_matches( $src, $attachment_id ), $src, $attachment_id );
	}

	/**
	 * Compare an image URL against the files an attachment owns.
	 *
	 * @param string $src           The image URL.
	 * @param int    $attachment_id The attachment ID.
	 * @return bool
	 */
	private static function file_matches( string $src, int $attachment_id ): bool {
		if ( '' === $src || $attachment_id <= 0 ) {
			return false;
		}

		$path = wp_parse_url( $src, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$path  = rawurldecode( $path );
		$files = self::attachment_files( $attachment_id );

		foreach ( $files['all'] as $file ) {
			if ( self::path_ends_with( $path, $file ) ) {
				return true;
			}
		}

		// A size that is no longer registered (thumbnails regenerated, image
		// edited) or a converted format: same folder, same base name.
		$base = self::base_name( $path );

		foreach ( $files['originals'] as $file ) {
			if ( self::path_ends_with( $base, self::base_name( $file ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The files an attachment owns, relative to the uploads folder.
	 *
	 * Read straight from post meta, which the callers prime in bulk, so no
	 * attachment post has to be loaded.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array{all: list<string>, originals: list<string>}
	 */
	private static function attachment_files( int $attachment_id ): array {
		$originals = array();
		$sizes     = array();

		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( is_string( $attached ) && '' !== $attached ) {
			$originals[] = self::relative_file( $attached );
		}

		$meta = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );

		if ( is_array( $meta ) ) {
			$main = isset( $meta['file'] ) && is_string( $meta['file'] ) && '' !== $meta['file'] ? self::relative_file( $meta['file'] ) : ( $originals[0] ?? '' );

			if ( '' !== $main ) {
				$originals[] = $main;
			}

			$folder = dirname( $main );
			$folder = '.' === $folder || '' === $main ? '' : $folder . '/';

			if ( isset( $meta['original_image'] ) && is_string( $meta['original_image'] ) && '' !== $meta['original_image'] ) {
				$originals[] = $folder . $meta['original_image'];
			}

			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] ) {
					$sizes[] = $folder . $size['file'];
				}
			}
		}

		$originals = array_values( array_unique( $originals ) );

		return array(
			'all'       => array_values( array_unique( array_merge( $originals, $sizes ) ) ),
			'originals' => $originals,
		);
	}

	/**
	 * Reduce a stored file path to the part that also appears in its URL.
	 *
	 * Old installs stored absolute paths; only the year/month folder and the
	 * file name of those are part of the URL.
	 *
	 * @param string $file Stored file path.
	 * @return string
	 */
	private static function relative_file( string $file ): string {
		$file = str_replace( '\\', '/', $file );

		if ( 1 === preg_match( '#^(?:/|[A-Za-z]:/)#', $file ) ) {
			return 1 === preg_match( '#(\d{4}/\d{2}/[^/]+)$#', $file, $found ) ? $found[1] : basename( $file );
		}

		return ltrim( $file, '/' );
	}

	/**
	 * Whether a URL path ends with a file path, on a folder boundary.
	 *
	 * @param string $path URL path.
	 * @param string $file File path relative to the uploads folder.
	 * @return bool
	 */
	private static function path_ends_with( string $path, string $file ): bool {
		if ( '' === $file ) {
			return false;
		}

		return $path === $file || str_ends_with( $path, '/' . $file );
	}

	/**
	 * A file path without extension and without the suffixes WordPress adds
	 * to resized, scaled, rotated and edited copies.
	 *
	 * @param string $path File or URL path.
	 * @return string
	 */
	private static function base_name( string $path ): string {
		$folder = dirname( $path );
		$name   = basename( $path );

		// The extension, and a second one from converters (photo.jpg.webp).
		$name = (string) preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', $name );
		$name = (string) preg_replace( '/\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?|heic)$/i', '', $name );
		$name = (string) preg_replace( '/(?:-scaled|-rotated|-e\d{10,}|-\d+x\d+)+$/', '', $name );

		return ( in_array( $folder, array( '.', '/', '\\', '' ), true ) ? '' : rtrim( $folder, '/' ) . '/' ) . $name;
	}

	/**
	 * Visit every Media Library image in a piece of HTML.
	 *
	 * The callback receives the image and returns the alt text to write, or
	 * null to leave the image alone. Images whose file does not belong to
	 * the attachment named in their class are not visited.
	 *
	 * @since 1.0.0
	 *
	 * The optional $wanted callback decides, before an image's file is
	 * checked, whether the main callback needs to see the image at all.
	 * Matching the file costs more than reading the alt, and most images on
	 * a page need nothing.
	 *
	 * @param string        $html     HTML to scan.
	 * @param callable      $callback Decides each image's alt.
	 * @param callable|null $wanted   Optional. Rules images out before their file is checked.
	 *
	 * @phpstan-param callable(array{attachment_id: int, alt: string|null, decorative: bool, src: string}): ?string $callback
	 * @phpstan-param (callable(array{attachment_id: int, alt: string|null, decorative: bool}): bool)|null          $wanted
	 *
	 * @return string The HTML, updated only if an alt actually changed.
	 */
	public static function walk_images( string $html, callable $callback, ?callable $wanted = null ): string {
		$result = self::scan(
			$html,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( $callback ): bool {
				$new_alt = $callback(
					array(
						'attachment_id' => $image['attachment_id'],
						'alt'           => $image['alt'],
						'decorative'    => $image['decorative'],
						'src'           => $image['src'],
					)
				);

				if ( ! is_string( $new_alt ) || $new_alt === $image['alt'] ) {
					return false;
				}

				self::write_alt( $processor, $image['background'], $new_alt );

				return true;
			},
			$wanted
		);

		return $result['html'];
	}

	/**
	 * Visit every image with a wp-image-N class, including those whose file
	 * belongs elsewhere.
	 *
	 * Works like walk_images(), except that images whose file is not one of
	 * the attachment's are visited too, with "foreign" set.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $html     HTML to scan.
	 * @param callable      $callback Decides each image's alt.
	 * @param callable|null $wanted   Optional. Rules images out before their file is checked.
	 *
	 * @phpstan-param callable(array{attachment_id: int, alt: string|null, decorative: bool, background: bool, foreign: bool}): ?string $callback
	 * @phpstan-param (callable(array{attachment_id: int, alt: string|null, decorative: bool}): bool)|null                              $wanted
	 *
	 * @return string The HTML, updated only if an alt actually changed.
	 */
	public static function walk_all_images( string $html, callable $callback, ?callable $wanted = null ): string {
		$result = self::scan(
			$html,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( $callback ): bool {
				$new_alt = $callback(
					array(
						'attachment_id' => $image['attachment_id'],
						'alt'           => $image['alt'],
						'decorative'    => $image['decorative'],
						'background'    => $image['background'],
						'foreign'       => $image['foreign'],
					)
				);

				if ( ! is_string( $new_alt ) || $new_alt === $image['alt'] ) {
					return false;
				}

				self::write_alt( $processor, $image['background'], $new_alt );

				return true;
			},
			$wanted,
			true
		);

		return $result['html'];
	}

	/**
	 * Identify the images that are decorative and without alt text in a
	 * piece of HTML, for a set of attachments, by their tags.
	 *
	 * A block theme passes the finished page through wp_content_img_tag once
	 * more, one tag at a time, where a mark on a block around an image can
	 * no longer be seen. The tag itself can: an image the content left
	 * decorative comes back with the same tag, while a copy of the image
	 * elsewhere on the page, in a header for example, usually has another
	 * size, class or style. See image_tag_key().
	 *
	 * @since 1.0.0
	 *
	 * @param string           $html           The HTML.
	 * @param array<int, true> $attachment_ids The attachments to look for.
	 * @return array<string, true> The keys of their tags, as keys.
	 */
	public static function decorative_image_keys( string $html, array $attachment_ids ): array {
		$keys = array();

		if ( empty( $attachment_ids ) ) {
			return $keys;
		}

		self::scan(
			$html,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( &$keys ): bool {
				if ( ! $image['background'] ) {
					$keys[ self::tag_key( $processor ) ] = true;
				}

				return false;
			},
			static function ( array $image ) use ( $attachment_ids ): bool {
				return $image['decorative'] && isset( $attachment_ids[ $image['attachment_id'] ] ) && ( null === $image['alt'] || '' === $image['alt'] );
			}
		);

		return $keys;
	}

	/**
	 * The key of a single <img> tag: its attributes and their values, apart
	 * from those in TAG_KEY_IGNORED, in a fixed order.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag An <img> tag.
	 * @return string The key, or '' when it is not an <img> tag.
	 */
	public static function image_tag_key( string $tag ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return '';
		}

		$processor = new WP_HTML_Tag_Processor( $tag );

		return $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ? self::tag_key( $processor ) : '';
	}

	/**
	 * The key of the tag a processor stands on.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor positioned on an <img>.
	 * @return string
	 */
	private static function tag_key( WP_HTML_Tag_Processor $processor ): string {
		$names = array();

		foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
			if ( is_string( $name ) && ! in_array( $name, self::TAG_KEY_IGNORED, true ) ) {
				$names[] = $name;
			}
		}

		$parts = array();

		sort( $names );

		foreach ( $names as $name ) {
			$value   = $processor->get_attribute( $name );
			$parts[] = $name . '=' . ( is_string( $value ) ? $value : '' );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Read a single <img> tag the way the scan reads images.
	 *
	 * Only what the tag itself says counts as decorative here: AltRelay's
	 * class on the image, a role of "none" or "presentation", or
	 * aria-hidden. A mark on an element around it is not part of the tag.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag An <img> tag.
	 * @return array{attachment_id: int, alt: string|null, decorative: bool, src: string}|null Null when it is not an image with a wp-image-N class.
	 */
	public static function read_image_tag( string $tag ): ?array {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === strpos( $tag, 'wp-image-' ) ) {
			return null;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return null;
		}

		$attachment_id = self::attachment_id_from_class( $processor->get_attribute( 'class' ) );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		return array(
			'attachment_id' => $attachment_id,
			'alt'           => self::read_alt( $processor, false ),
			'decorative'    => true === $processor->has_class( self::MARKER ) || self::image_is_hidden( $processor ),
			'src'           => self::read_source( $processor, false ),
		);
	}

	/**
	 * Set the alt attribute of a single <img> tag.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $tag An <img> tag.
	 * @param string|null $alt The alt text, or null to remove the attribute.
	 * @return string
	 */
	public static function set_image_tag_alt( string $tag, ?string $alt ): string {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}

		$processor = new WP_HTML_Tag_Processor( $tag );

		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $tag;
		}

		if ( null === $alt ) {
			$processor->remove_attribute( 'alt' );
		} else {
			$processor->set_attribute( 'alt', $alt );
		}

		return $processor->get_updated_html();
	}

	/**
	 * List every Media Library image in a piece of HTML.
	 *
	 * "linked" is true when the image is the only content of a link, so its
	 * alt text is what names the link.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html HTML to scan.
	 * @return list<array{attachment_id: int, alt: string|null, decorative: bool, src: string, linked: bool}>
	 */
	public static function find_images( string $html ): array {
		$images = array();

		foreach ( self::scan( $html, null )['images'] as $image ) {
			$images[] = array(
				'attachment_id' => $image['attachment_id'],
				'alt'           => $image['alt'],
				'decorative'    => $image['decorative'],
				'src'           => $image['src'],
				'linked'        => $image['linked'],
			);
		}

		return $images;
	}

	/**
	 * Write alt text into every non-decorative image the resolver knows.
	 *
	 * Updates the HTML and, for Cover blocks, the alt stored in the block
	 * comment. The Cover block keeps its alt there, so rewriting only the
	 * HTML would make the block fail validation in the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param string                 $content             Post content.
	 * @param callable(int): ?string $resolver            Returns the alt text for an attachment ID, or null to skip it.
	 * @param bool|null              $empty_is_decorative Override the filter; null uses it.
	 * @param int[]                  $changed_ids         Receives the attachment IDs whose markup changed.
	 * @return string
	 */
	public static function rewrite_alts( string $content, callable $resolver, ?bool $empty_is_decorative = null, array &$changed_ids = array() ): string {
		$targets = array();
		$changed = array();

		$content = self::walk_images(
			$content,
			static function ( array $image ) use ( $resolver, $empty_is_decorative, &$targets, &$changed ): ?string {
				$attachment_id = $image['attachment_id'];
				$alt           = $resolver( $attachment_id );

				if ( null === $alt ) {
					return null;
				}

				$targets[ $attachment_id ] = true;

				if ( $image['decorative'] ) {
					return null;
				}

				if ( '' === $image['alt'] && ( $empty_is_decorative ?? self::empty_alt_is_decorative( $attachment_id ) ) ) {
					return null;
				}

				if ( $alt !== $image['alt'] ) {
					$changed[ $attachment_id ] = true;
				}

				return $alt;
			}
		);

		$content     = self::sync_cover_comments( $content, array_keys( $targets ) );
		$changed_ids = array_keys( $changed );

		return $content;
	}

	/**
	 * Write an attachment's alt text into every non-decorative copy of it.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $content             Post content.
	 * @param int       $attachment_id       The attachment ID.
	 * @param string    $alt                 The alt text to write.
	 * @param bool|null $empty_is_decorative Override the filter; null uses it.
	 * @return string
	 */
	public static function rewrite_attachment_alt( string $content, int $attachment_id, string $alt, ?bool $empty_is_decorative = null ): string {
		return self::rewrite_alts(
			$content,
			static fn( int $id ): ?string => $id === $attachment_id ? $alt : null,
			$empty_is_decorative
		);
	}

	/**
	 * Give the copies of an attachment that still hold an outdated alt text
	 * the current one.
	 *
	 * Only copies whose alt text is one of the outdated ones change; a copy
	 * with any other text, and a decorative one, is left as it is. Cover
	 * blocks get the text in their block comment as well.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $content       Post content.
	 * @param int      $attachment_id The attachment ID.
	 * @param string   $alt           The current alt text.
	 * @param string[] $outdated      Alt texts that were replaced.
	 * @return string
	 */
	public static function refresh_stale_alt( string $content, int $attachment_id, string $alt, array $outdated ): string {
		$updated = self::walk_images(
			$content,
			static function ( array $image ) use ( $attachment_id, $alt, $outdated ): ?string {
				if ( $image['attachment_id'] !== $attachment_id || $image['decorative'] || ! in_array( $image['alt'], $outdated, true ) ) {
					return null;
				}

				return $alt;
			}
		);

		return $updated === $content ? $content : self::sync_cover_comments( $updated, array( $attachment_id ) );
	}

	/**
	 * Mark every copy of an attachment in a post as decorative.
	 *
	 * Image blocks use core's own decorative setting where it exists
	 * (isDecorative plus role="none", exactly what the editor saves). Other
	 * blocks get the marker class on the block (the className attribute and
	 * the wrapper element, where the editor puts custom classes). Images
	 * outside blocks get the class on the image itself. Either way the alt is
	 * emptied.
	 *
	 * An image that is the only content of a link is refused: without alt
	 * text the link would have no name for screen reader users.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content       Post content.
	 * @param int    $attachment_id The attachment ID.
	 * @param int    $refused       Receives the number of linked copies that were left alone.
	 * @return string
	 */
	public static function mark_decorative( string $content, int $attachment_id, int &$refused = 0 ): string {
		$refused  = 0;
		$use_core = self::core_image_has_decorative();

		$content = self::edit_media_blocks(
			$content,
			static function ( string $name, stdClass $attrs, string $segment ) use ( $attachment_id, $use_core ): ?array {
				if ( self::block_media_id( $name, $attrs ) !== $attachment_id || self::block_is_decorative( $attrs ) ) {
					return null;
				}

				$image = self::first_image( $segment, $attachment_id );

				// No such image in the block's markup, or a linked one: the
				// block stays as it is.
				if ( null === $image || $image['linked'] ) {
					return null;
				}

				if ( $use_core && 'image' === $name ) {
					$attrs->{self::CORE_DECORATIVE_ATTRIBUTE} = true;

					$segment = self::edit_first_image( $segment, $attachment_id, true );
				} else {
					$existing = isset( $attrs->{self::CLASS_ATTRIBUTE} ) && is_string( $attrs->{self::CLASS_ATTRIBUTE} ) ? $attrs->{self::CLASS_ATTRIBUTE} : '';

					$attrs->{self::CLASS_ATTRIBUTE} = trim( $existing . ' ' . self::MARKER );

					$segment = self::edit_first_element( $segment, true );
				}

				// The Cover block stores its alt in the comment; a decorative image has none.
				unset( $attrs->alt );

				return array( $attrs, $segment );
			}
		);

		// The link an image sits in is only fully known once its closing tag
		// has been read, so the images are listed first and changed second.
		$known = self::scan( $content, null )['images'];

		$result = self::scan(
			$content,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( $attachment_id, $known, &$refused ): bool {
				if ( $image['attachment_id'] !== $attachment_id ) {
					return false;
				}

				$changed = false;

				if ( ! $image['decorative'] ) {
					if ( $known[ $image['ordinal'] ]['linked'] ?? false ) {
						++$refused;
						return false;
					}

					$processor->add_class( self::MARKER );
					$changed = true;
				}

				if ( '' !== $image['alt'] ) {
					self::write_alt( $processor, $image['background'], '' );
					$changed = true;
				}

				return $changed;
			}
		);

		return $result['html'];
	}

	/**
	 * Remove the decorative mark from every copy of an attachment in a post.
	 *
	 * Undoes what mark_decorative() and the editor toggle write: the marker
	 * class on a media block or on the image itself, and core's isDecorative
	 * with its role="none". Marks AltRelay did not write are left alone: a
	 * class on a surrounding group, or a role typed into custom HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content       Post content.
	 * @param int    $attachment_id The attachment ID.
	 * @return string
	 */
	public static function unmark_decorative( string $content, int $attachment_id ): string {
		$content = self::edit_media_blocks(
			$content,
			static function ( string $name, stdClass $attrs, string $segment ) use ( $attachment_id ): ?array {
				if ( self::block_media_id( $name, $attrs ) !== $attachment_id ) {
					return null;
				}

				$changed = false;
				$classes = $attrs->{self::CLASS_ATTRIBUTE} ?? '';

				if ( self::has_marker( $classes ) ) {
					$rest = implode( ' ', array_diff( self::class_list( $classes ), array( self::MARKER ) ) );

					if ( '' === $rest ) {
						unset( $attrs->{self::CLASS_ATTRIBUTE} );
					} else {
						$attrs->{self::CLASS_ATTRIBUTE} = $rest;
					}

					$segment = self::edit_first_element( $segment, false );
					$changed = true;
				}

				if ( true === ( $attrs->{self::CORE_DECORATIVE_ATTRIBUTE} ?? false ) ) {
					unset( $attrs->{self::CORE_DECORATIVE_ATTRIBUTE} );

					$segment = self::edit_first_image( $segment, $attachment_id, false );
					$changed = true;
				}

				return $changed ? array( $attrs, $segment ) : null;
			}
		);

		$result = self::scan(
			$content,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( $attachment_id ): bool {
				if ( $image['attachment_id'] !== $attachment_id || true !== $processor->has_class( self::MARKER ) ) {
					return false;
				}

				$processor->remove_class( self::MARKER );

				return true;
			}
		);

		return $result['html'];
	}

	/**
	 * Read every Media Library image in a piece of HTML, optionally changing it.
	 *
	 * Walks every token rather than every tag, because whether an image is
	 * the only content of a link depends on the text around it.
	 *
	 * @param string        $html    HTML to scan.
	 * @param callable|null $visitor Receives each image and the processor positioned on it. Returns true when it changed the tag.
	 * @param callable|null $wanted  Optional. Returns false for an image nobody needs, before its file is checked.
	 * @param bool          $foreign Optional. Also show the visitor images whose file belongs elsewhere. They are never listed.
	 *
	 * @phpstan-param null|callable(array{ordinal: int, attachment_id: int, alt: string|null, decorative: bool, src: string, background: bool, foreign: bool}, WP_HTML_Tag_Processor): bool $visitor
	 * @phpstan-param null|callable(array{attachment_id: int, alt: string|null, decorative: bool}): bool $wanted
	 *
	 * @return array{html: string, images: list<array{attachment_id: int, alt: string|null, decorative: bool, src: string, background: bool, linked: bool}>}
	 */
	private static function scan( string $html, ?callable $visitor, ?callable $wanted = null, bool $foreign = false ): array {
		$empty = array(
			'html'   => $html,
			'images' => array(),
		);

		// Every image AltRelay handles carries a wp-image-N class.
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || false === strpos( $html, 'wp-image-' ) ) {
			return $empty;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$changed   = false;
		$images    = array();

		/**
		 * Open elements, as [tag name, carries the marker, index into $links or -1].
		 *
		 * @var list<array{0: string, 1: bool, 2: int}> $stack
		 */
		$stack = array();

		/**
		 * For every link seen so far, whether it has text or a label of its own.
		 *
		 * @var list<bool> $link_named
		 */
		$link_named = array();

		/**
		 * For every link seen so far, how many described images it contains.
		 *
		 * @var list<int> $link_alts
		 */
		$link_alts = array();

		/**
		 * For each image in $images, the link it sits in (or -1) and whether
		 * its own alt text counts towards that link's name.
		 *
		 * @var list<array{0: int, 1: bool}> $placement
		 */
		$placement = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				$link = self::open_link( $stack );

				if ( $link >= 0 && '' !== trim( $processor->get_modifiable_text() ) ) {
					$link_named[ $link ] = true;
				}

				continue;
			}

			if ( '#tag' !== $type ) {
				continue;
			}

			$tag = (string) $processor->get_tag();

			if ( $processor->is_tag_closer() ) {
				$stack = self::pop_element( $stack, $tag );
				continue;
			}

			// End tags that were left out, where a browser would imply them:
			// a block starts after an open paragraph, or a link starts inside
			// another. Otherwise the paragraph's mark, or the link, would
			// reach every image that follows.
			if ( in_array( $tag, self::CLOSES_P, true ) ) {
				$stack = self::close_implied( $stack, 'P' );
			} elseif ( 'A' === $tag ) {
				$stack = self::close_implied( $stack, 'A' );
			}

			$marked     = true === $processor->has_class( self::MARKER );
			$background = 'DIV' === $tag && true === $processor->has_class( self::BACKGROUND_CLASS );

			if ( 'IMG' === $tag || $background ) {
				$alt       = self::read_alt( $processor, $background );
				$has_alt   = is_string( $alt ) && '' !== trim( $alt );
				$link      = self::open_link( $stack );
				$hidden    = self::image_is_hidden( $processor );
				$described = $has_alt && ! $hidden;

				// Any described image names the link it sits in, whether or
				// not it comes from the Media Library.
				if ( $link >= 0 && $described ) {
					++$link_alts[ $link ];
				}

				$attachment_id = self::attachment_id_from_class( $processor->get_attribute( 'class' ) );
				$decorative    = $marked || self::stack_is_marked( $stack ) || $hidden;

				if ( $attachment_id > 0 && null !== $wanted && ! $wanted(
					array(
						'attachment_id' => $attachment_id,
						'alt'           => $alt,
						'decorative'    => $decorative,
					)
				) ) {
					$attachment_id = 0;
				}

				$src = $attachment_id > 0 ? self::read_source( $processor, $background ) : '';

				if ( $attachment_id > 0 && self::image_matches_attachment( $src, $attachment_id ) ) {
					$image = array(
						'ordinal'       => count( $images ),
						'attachment_id' => $attachment_id,
						'alt'           => $alt,
						'decorative'    => $decorative,
						'src'           => $src,
						'background'    => $background,
						'foreign'       => false,
					);

					$images[]    = $image;
					$placement[] = array( $link, $described );

					if ( null !== $visitor && $visitor( $image, $processor ) ) {
						$changed = true;
					}
				} elseif ( $foreign && $attachment_id > 0 && null !== $visitor ) {
					$image = array(
						'ordinal'       => -1,
						'attachment_id' => $attachment_id,
						'alt'           => $alt,
						'decorative'    => $decorative,
						'src'           => $src,
						'background'    => $background,
						'foreign'       => true,
					);

					if ( $visitor( $image, $processor ) ) {
						$changed = true;
					}
				}
			}

			if ( 'IMG' === $tag || in_array( $tag, self::VOID_TAGS, true ) || $processor->has_self_closing_flag() ) {
				continue;
			}

			$link_index = -1;

			if ( 'A' === $tag && null !== $processor->get_attribute( 'href' ) ) {
				$link_index   = count( $link_named );
				$link_named[] = self::has_label( $processor );
				$link_alts[]  = 0;
			}

			$stack[] = array( $tag, $marked, $link_index );
		}

		$listed = array();

		foreach ( $images as $index => $image ) {
			list( $link, $described ) = $placement[ $index ];

			$listed[] = array(
				'attachment_id' => $image['attachment_id'],
				'alt'           => $image['alt'],
				'decorative'    => $image['decorative'],
				'src'           => $image['src'],
				'background'    => $image['background'],
				'linked'        => $link >= 0 && ! $link_named[ $link ] && $link_alts[ $link ] - ( $described ? 1 : 0 ) <= 0,
			);
		}

		return array(
			'html'   => $changed ? $processor->get_updated_html() : $html,
			'images' => $listed,
		);
	}

	/**
	 * Read an image's alt text.
	 *
	 * @param WP_HTML_Tag_Processor $processor  Processor positioned on the image.
	 * @param bool                  $background Whether it is a Cover background <div>.
	 * @return string|null The alt text, or null when an <img> has no alt attribute.
	 */
	private static function read_alt( WP_HTML_Tag_Processor $processor, bool $background ): ?string {
		$value = $processor->get_attribute( $background ? 'aria-label' : 'alt' );

		if ( is_string( $value ) ) {
			return $value;
		}

		// A background without a label is the same as an empty alt, and so
		// is a bare "alt" attribute without a value.
		return $background || true === $value ? '' : null;
	}

	/**
	 * Write an image's alt text.
	 *
	 * A Cover background carries its description as role="img" plus
	 * aria-label, and carries neither when it has none.
	 *
	 * @param WP_HTML_Tag_Processor $processor  Processor positioned on the image.
	 * @param bool                  $background Whether it is a Cover background <div>.
	 * @param string                $alt        The alt text.
	 * @return void
	 */
	private static function write_alt( WP_HTML_Tag_Processor $processor, bool $background, string $alt ): void {
		if ( ! $background ) {
			$processor->set_attribute( 'alt', $alt );
			return;
		}

		if ( '' === $alt ) {
			$processor->remove_attribute( 'role' );
			$processor->remove_attribute( 'aria-label' );
			return;
		}

		$processor->set_attribute( 'role', 'img' );
		$processor->set_attribute( 'aria-label', $alt );
	}

	/**
	 * Read the URL of an image.
	 *
	 * @param WP_HTML_Tag_Processor $processor  Processor positioned on the image.
	 * @param bool                  $background Whether it is a Cover background <div>.
	 * @return string The URL, or an empty string.
	 */
	private static function read_source( WP_HTML_Tag_Processor $processor, bool $background ): string {
		if ( $background ) {
			$style = $processor->get_attribute( 'style' );

			return is_string( $style ) && preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)/i', $style, $found ) ? trim( $found[1] ) : '';
		}

		foreach ( self::SOURCE_ATTRIBUTES as $attribute ) {
			$value = $processor->get_attribute( $attribute );

			if ( is_string( $value ) && '' !== $value && 0 !== stripos( $value, 'data:' ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Whether the current tag hides its image from assistive technology.
	 *
	 * A role of "none" (what core's decorative setting saves), "presentation"
	 * and aria-hidden="true" all mean the image is not announced, so filling
	 * its alt would be wrong. This also covers decorative images in classic
	 * content and from other tools.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor positioned on an image.
	 * @return bool
	 */
	private static function image_is_hidden( WP_HTML_Tag_Processor $processor ): bool {
		$role = $processor->get_attribute( 'role' );

		if ( is_string( $role ) && in_array( strtolower( trim( $role ) ), self::HIDDEN_ROLES, true ) ) {
			return true;
		}

		$hidden = $processor->get_attribute( 'aria-hidden' );

		return is_string( $hidden ) && 'true' === strtolower( trim( $hidden ) );
	}

	/**
	 * Whether a link names itself through an attribute.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor positioned on an <a>.
	 * @return bool
	 */
	private static function has_label( WP_HTML_Tag_Processor $processor ): bool {
		foreach ( array( 'aria-label', 'aria-labelledby', 'title' ) as $attribute ) {
			$value = $processor->get_attribute( $attribute );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply an edit to every supported media block in post content.
	 *
	 * The callback receives the block name, its attributes and its segment:
	 * the markup between the block's opening comment and the next block
	 * comment, which is where the block's own image lives. Keeping edits
	 * inside the segment is what stops a block without markup of its own
	 * from reaching into the block after it.
	 *
	 * @param string   $content Post content.
	 * @param callable $edit    Returns the new attributes and segment, or null to leave the block alone.
	 *
	 * @phpstan-param callable(string, stdClass, string): (array{0: stdClass, 1: string}|null) $edit
	 *
	 * @return string
	 */
	private static function edit_media_blocks( string $content, callable $edit ): string {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		if ( ! preg_match_all( self::DELIMITER_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		// Walk backwards so earlier offsets stay valid as the content changes.
		for ( $index = count( $matches ) - 1; $index >= 0; $index-- ) {
			$found = array();

			foreach ( $matches[ $index ] as $key => $value ) {
				$found[ $key ] = $value[0];
			}

			$name  = $found['name'];
			$attrs = self::media_block_attributes( $found );

			if ( null === $attrs ) {
				continue;
			}

			$start   = (int) $matches[ $index ][0][1];
			$after   = $start + strlen( (string) $found[0] );
			$end     = isset( $matches[ $index + 1 ] ) ? (int) $matches[ $index + 1 ][0][1] : strlen( $content );
			$segment = substr( $content, $after, $end - $after );
			$result  = $edit( $name, $attrs, $segment );

			if ( null === $result ) {
				continue;
			}

			$delimiter = self::replace_attributes( (string) $found[0], (string) ( $found['attrs'] ?? '' ), $result[0] );
			$content   = substr( $content, 0, $start ) . $delimiter . $result[1] . substr( $content, $end );
		}

		return $content;
	}

	/**
	 * Decode the attributes of a supported media block's opening comment.
	 *
	 * @param array<int|string, string> $found Delimiter match.
	 * @return stdClass|null Attributes, or null when this is not the opening
	 *                       comment of a core media block that names an attachment.
	 */
	private static function media_block_attributes( array $found ): ?stdClass {
		$name = $found['name'] ?? '';

		// A closing comment, a block without attributes, or a void block,
		// which has no markup of its own to change.
		if ( ! isset( self::MEDIA_BLOCKS[ $name ] ) || '' !== ( $found['closer'] ?? '' ) || '' === ( $found['attrs'] ?? '' ) || '' !== ( $found['void'] ?? '' ) ) {
			return null;
		}

		$namespace = $found['namespace'] ?? '';

		if ( '' !== $namespace && 'core/' !== $namespace ) {
			return null;
		}

		$attrs  = json_decode( $found['attrs'] );
		$id_key = self::MEDIA_BLOCKS[ $name ];

		if ( ! $attrs instanceof stdClass || ! isset( $attrs->{$id_key} ) || ! is_numeric( $attrs->{$id_key} ) ) {
			return null;
		}

		return $attrs;
	}

	/**
	 * The attachment a media block shows.
	 *
	 * @param string   $name  Block name without namespace.
	 * @param stdClass $attrs Block attributes.
	 * @return int
	 */
	private static function block_media_id( string $name, stdClass $attrs ): int {
		$value = $attrs->{self::MEDIA_BLOCKS[ $name ] ?? 'id'} ?? 0;

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Whether a media block is already decorative, by either mechanism.
	 *
	 * @param stdClass $attrs Block attributes.
	 * @return bool
	 */
	private static function block_is_decorative( stdClass $attrs ): bool {
		return self::has_marker( $attrs->{self::CLASS_ATTRIBUTE} ?? '' )
			|| true === ( $attrs->{self::CORE_DECORATIVE_ATTRIBUTE} ?? false );
	}

	/**
	 * The first copy of an attachment in a piece of markup.
	 *
	 * @param string $html          Markup.
	 * @param int    $attachment_id The attachment ID.
	 * @return array{attachment_id: int, alt: string|null, decorative: bool, src: string, background: bool, linked: bool}|null
	 */
	private static function first_image( string $html, int $attachment_id ): ?array {
		foreach ( self::scan( $html, null )['images'] as $image ) {
			if ( $image['attachment_id'] === $attachment_id ) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * Add the marker class to the first element, the block wrapper, or remove it.
	 *
	 * @param string $html Markup that starts with the block's wrapper.
	 * @param bool   $mark Whether to add (true) or remove (false) the class.
	 * @return string
	 */
	private static function edit_first_element( string $html, bool $mark ): string {
		$wrapper = new WP_HTML_Tag_Processor( $html );

		if ( ! $wrapper->next_tag() ) {
			return $html;
		}

		if ( $mark ) {
			$wrapper->add_class( self::MARKER );
		} else {
			$wrapper->remove_class( self::MARKER );
		}

		return $wrapper->get_updated_html();
	}

	/**
	 * Hide the block's image the way core's decorative setting saves it, or
	 * show it again.
	 *
	 * @param string $html          Markup of one Image block.
	 * @param int    $attachment_id The attachment ID.
	 * @param bool   $hide          Whether to hide (true) or show (false) the image.
	 * @return string
	 */
	private static function edit_first_image( string $html, int $attachment_id, bool $hide ): string {
		$done = false;

		$result = self::scan(
			$html,
			static function ( array $image, WP_HTML_Tag_Processor $processor ) use ( $attachment_id, $hide, &$done ): bool {
				if ( $done || $image['attachment_id'] !== $attachment_id ) {
					return false;
				}

				$done = true;

				if ( $hide ) {
					$processor->set_attribute( 'alt', '' );
					$processor->set_attribute( 'role', 'none' );
				} else {
					$processor->remove_attribute( 'role' );
				}

				return true;
			}
		);

		if ( $hide || ! $done ) {
			return $result['html'];
		}

		// The HTML API leaves the space of a removed attribute behind. Where
		// AltRelay added the role itself, that is right after the tag name;
		// taking it out again gives the block back exactly as it was.
		return (string) preg_replace( '/<img {2,}/', '<img ', $result['html'], 1 );
	}

	/**
	 * Make the alt in Cover block comments agree with the markup.
	 *
	 * The comment is set from the image rather than from the new alt text,
	 * so a Cover whose image was left alone (decorative through a group
	 * around it, or an empty alt that is respected) keeps a comment that
	 * matches it.
	 *
	 * @param string $content        Post content.
	 * @param int[]  $attachment_ids The attachments whose Cover blocks to check.
	 * @return string
	 */
	private static function sync_cover_comments( string $content, array $attachment_ids ): string {
		if ( empty( $attachment_ids ) || false === strpos( $content, 'wp:cover' ) ) {
			return $content;
		}

		return self::edit_media_blocks(
			$content,
			static function ( string $name, stdClass $attrs, string $segment ) use ( $attachment_ids ): ?array {
				$attachment_id = self::block_media_id( $name, $attrs );

				if ( 'cover' !== $name || ! in_array( $attachment_id, $attachment_ids, true ) ) {
					return null;
				}

				$image = self::first_image( $segment, $attachment_id );

				if ( null === $image ) {
					return null;
				}

				$markup_alt = (string) $image['alt'];
				$current    = isset( $attrs->alt ) && is_string( $attrs->alt ) ? $attrs->alt : '';

				if ( $current === $markup_alt ) {
					return null;
				}

				// The editor omits attributes that equal their default.
				if ( '' === $markup_alt ) {
					unset( $attrs->alt );
				} else {
					$attrs->alt = $markup_alt;
				}

				return array( $attrs, $segment );
			}
		);
	}

	/**
	 * Swap the JSON in a delimiter while keeping its original whitespace.
	 *
	 * The parser's "attrs" group includes the whitespace after the closing
	 * brace, so it is trimmed first; otherwise the space before "-->" would be
	 * swallowed and every touched delimiter would change shape.
	 *
	 * @param string   $delimiter The full delimiter.
	 * @param string   $old_json  The JSON currently in it, as captured by the parser.
	 * @param stdClass $attrs     The new attributes.
	 * @return string
	 */
	private static function replace_attributes( string $delimiter, string $old_json, stdClass $attrs ): string {
		$old_json = rtrim( $old_json );
		$position = '' === $old_json ? false : strpos( $delimiter, $old_json );

		if ( false === $position ) {
			return $delimiter;
		}

		return substr_replace( $delimiter, self::encode_attributes( $attrs ), $position, strlen( $old_json ) );
	}

	/**
	 * Encode block attributes exactly like serialize_block_attributes().
	 *
	 * Mirrors core rather than calling it so objects (which keep "{}" intact
	 * where an array would become "[]") can be passed through.
	 *
	 * Newer versions of WordPress also write a backslash as \, so that
	 * functions that strip slashes cannot remove it. Whether the running
	 * version does is asked of core itself, so the comment AltRelay writes is
	 * the one the editor would write.
	 *
	 * @param stdClass $attrs Attributes.
	 * @return string
	 */
	private static function encode_attributes( stdClass $attrs ): string {
		$json = (string) wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$map  = array(
			'--'  => '\\u002d\\u002d',
			'<'   => '\\u003c',
			'>'   => '\\u003e',
			'&'   => '\\u0026',
			'\\"' => '\\u0022',
		);

		if ( self::core_escapes_backslashes() ) {
			$map = array( '\\\\' => '\\u005c' ) + $map;
		}

		return strtr( $json, $map );
	}

	/**
	 * Whether the running WordPress writes a backslash in block attributes
	 * as \.
	 *
	 * @return bool
	 */
	private static function core_escapes_backslashes(): bool {
		static $escapes = null;

		if ( null === $escapes ) {
			$escapes = ! function_exists( 'serialize_block_attributes' )
				|| false !== strpos( serialize_block_attributes( array( 'a' => '\\' ) ), 'u005c' );
		}

		return (bool) $escapes;
	}

	/**
	 * Split a class attribute value into class names.
	 *
	 * @param mixed $class_names Class attribute value.
	 * @return list<string>
	 */
	private static function class_list( mixed $class_names ): array {
		if ( ! is_string( $class_names ) || '' === trim( $class_names ) ) {
			return array();
		}

		$names = preg_split( '/\s+/', trim( $class_names ) );

		return is_array( $names ) ? $names : array();
	}

	/**
	 * Close an element whose end tag was left out, as a browser would when
	 * the next element starts, unless a table cell, button or similar lies
	 * in between.
	 *
	 * @param list<array{0: string, 1: bool, 2: int}> $stack Open elements.
	 * @param string                                  $tag   Upper-case tag name to close.
	 * @return list<array{0: string, 1: bool, 2: int}>
	 */
	private static function close_implied( array $stack, string $tag ): array {
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( $stack[ $i ][0] === $tag ) {
				return array_slice( $stack, 0, $i );
			}

			if ( in_array( $stack[ $i ][0], self::SCOPE_BOUNDARIES, true ) ) {
				break;
			}
		}

		return $stack;
	}

	/**
	 * Pop the stack back to (and including) the most recent matching element.
	 *
	 * A closer with no matching opener is ignored, so stray markup cannot
	 * empty the stack.
	 *
	 * @param list<array{0: string, 1: bool, 2: int}> $stack Open elements.
	 * @param string                                  $tag   Closing tag name.
	 * @return list<array{0: string, 1: bool, 2: int}>
	 */
	private static function pop_element( array $stack, string $tag ): array {
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( $stack[ $i ][0] === $tag ) {
				return array_slice( $stack, 0, $i );
			}
		}

		return $stack;
	}

	/**
	 * Whether any open element carries the marker.
	 *
	 * @param list<array{0: string, 1: bool, 2: int}> $stack Open elements.
	 * @return bool
	 */
	private static function stack_is_marked( array $stack ): bool {
		foreach ( $stack as $element ) {
			if ( $element[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The innermost open link.
	 *
	 * @param list<array{0: string, 1: bool, 2: int}> $stack Open elements.
	 * @return int Index into the list of links, or -1 outside a link.
	 */
	private static function open_link( array $stack ): int {
		for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
			if ( $stack[ $i ][2] >= 0 ) {
				return $stack[ $i ][2];
			}
		}

		return -1;
	}
}
