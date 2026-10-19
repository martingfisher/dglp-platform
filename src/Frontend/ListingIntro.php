<?php
/**
 * The title and intro of each listing page, editable under Settings.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * /news/, /events/, /training/, /funding/ and /volunteering/ are archives the
 * plugin draws, not pages in the block editor, so their title and the line
 * under it were fixed text in code. Settings, Listing pages gives each a
 * title and an intro the team can change. Empty means the standard wording.
 *
 * A custom title is the page's heading, its browser title and its name in
 * search results. A custom intro is the text under the heading and, in plain
 * form, the page's meta description.
 */
final class ListingIntro {

	public const SLUG  = 'dgl-listing-pages';
	public const GROUP = 'dgl_listing_pages';

	/** The longest meta description we offer, in characters. */
	public const DESCRIPTION_MAX = 160;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'settings' ] );
		add_filter( 'post_type_archive_title', [ self::class, 'archive_title' ], 10, 2 );
	}

	/**
	 * The post types that have a listing page.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array_values( array_filter( [ PostTypes::NEWS, PostTypes::EVENT, PostTypes::TRAINING, PostTypes::GRANT, PostTypes::VOLUNTEERING ], [ PostTypes::class, 'is_enabled' ] ) );
	}

	/** The option that holds one setting. Pure. */
	public static function option( string $kind, string $type ): string {
		return 'dgl_listing_' . ( 'intro' === $kind ? 'intro' : 'title' ) . '_' . $type;
	}

	/** The custom text if there is any, else the standard. Pure. */
	public static function resolve( string $custom, string $default ): string {
		return '' !== trim( $custom ) ? trim( $custom ) : $default;
	}

	/**
	 * A plain-text excerpt of some HTML, cut at a word, for a meta
	 * description. Pure.
	 */
	public static function excerpt( string $html, int $max = self::DESCRIPTION_MAX ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false === $space || $space < $max / 2 ? $cut : mb_substr( $cut, 0, $space ), " ,;:.-" ) . '…';
	}

	/** The page's heading: the custom title, else the type's plural label. */
	public static function title( string $type ): string {
		return self::resolve( (string) get_option( self::option( 'title', $type ), '' ), Frontend::type_label( $type, true ) );
	}

	public static function default_intro(): string {
		return __( 'Posted by organisations in the Doing Good Leeds Partnership.', 'dgl-platform' );
	}

	/** The custom intro as HTML, or '' when there is none. */
	public static function custom_intro( string $type ): string {
		$html = trim( (string) get_option( self::option( 'intro', $type ), '' ) );

		return '' === trim( strip_tags( $html ) ) ? '' : wpautop( wp_kses_post( $html ) );
	}

	/** What goes under the heading, ready to print: the custom intro or the standard line. */
	public static function intro_html( string $type ): string {
		$custom = self::custom_intro( $type );

		return '' !== $custom ? $custom : '<p>' . esc_html( self::default_intro() ) . '</p>';
	}

	/** The meta description when the team wrote an intro; '' to use the standard. */
	public static function description( string $type ): string {
		return self::excerpt( self::custom_intro( $type ) );
	}

	/**
	 * Carry a custom title into the browser title, for our archives only.
	 *
	 * @param string $name      The post type's own label.
	 * @param string $post_type The post type.
	 */
	public static function archive_title( string $name, string $post_type ): string {
		return in_array( $post_type, self::types(), true ) ? self::title( $post_type ) : $name;
	}

	/* ---- The settings screen -------------------------------------------- */

	public static function menu(): void {
		add_options_page(
			__( 'Listing pages', 'dgl-platform' ),
			__( 'Listing pages', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'screen' ]
		);
	}

	public static function settings(): void {
		foreach ( self::types() as $type ) {
			register_setting( self::GROUP, self::option( 'title', $type ), [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
			register_setting( self::GROUP, self::option( 'intro', $type ), [ 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '' ] );
		}
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Listing pages', 'dgl-platform' ); ?></h1>
			<p><?php esc_html_e( 'The title and the introduction at the top of each listing page. Leave a box empty to keep the standard wording. The title also becomes the page\'s browser title; the introduction becomes its description in search results.', 'dgl-platform' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<?php foreach ( self::types() as $type ) : ?>
					<?php
					$title_opt = self::option( 'title', $type );
					$intro_opt = self::option( 'intro', $type );
					$url       = Frontend::archive_url( $type );
					?>
					<h2><?php echo esc_html( Frontend::type_label( $type, true ) ); ?>
						<?php if ( '' !== $url ) : ?>
							<a class="page-title-action" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View page', 'dgl-platform' ); ?></a>
						<?php endif; ?>
					</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $title_opt ); ?>"><?php esc_html_e( 'Title', 'dgl-platform' ); ?></label></th>
							<td><input type="text" class="regular-text" id="<?php echo esc_attr( $title_opt ); ?>" name="<?php echo esc_attr( $title_opt ); ?>" value="<?php echo esc_attr( (string) get_option( $title_opt, '' ) ); ?>" placeholder="<?php echo esc_attr( Frontend::type_label( $type, true ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $intro_opt ); ?>"><?php esc_html_e( 'Introduction', 'dgl-platform' ); ?></label></th>
							<td>
								<?php
								wp_editor(
									(string) get_option( $intro_opt, '' ),
									$intro_opt,
									[
										'textarea_name' => $intro_opt,
										'textarea_rows' => 5,
										'teeny'         => true,
										'media_buttons' => false,
									]
								);
								?>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: the standard intro line. */ __( 'Standard wording: "%s"', 'dgl-platform' ), self::default_intro() ) ); ?></p>
							</td>
						</tr>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
