<?php
/**
 * The topic feed as a block in the editor, and the screen that sets its stand-in pictures.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The editor sees one block, "Topic feed", with two dropdowns: what kind of
 * content, and which topic. It draws exactly what the shortcode draws, on
 * the server, so what the editor previews is what the visitor gets. The
 * shortcode stays for anyone who prefers it.
 *
 * Under Settings, "Topic feed pictures" sets the picture an item without one
 * falls back to, for each type.
 */
final class FeedBlock {

	public const NAME  = 'dgl/feed';
	public const SLUG  = 'dgl-feed-pictures';
	public const GROUP = 'dgl_feed_pictures';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'enqueue_block_assets', [ self::class, 'editor_style' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_assets' ] );
	}

	public static function register(): void {
		$path = \DGL\PLUGIN_DIR . 'assets/feed-block.js';

		wp_register_script(
			'dgl-feed-block',
			\DGL\PLUGIN_URL . 'assets/feed-block.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ],
			is_readable( $path ) ? (string) filemtime( $path ) : \DGL\VERSION,
			true
		);

		register_block_type(
			self::NAME,
			[
				'api_version'     => 3,
				'title'           => __( 'Topic feed', 'dgl-platform' ),
				'description'     => __( 'News, events or training for one topic, four across.', 'dgl-platform' ),
				'category'        => 'widgets',
				'icon'            => 'grid-view',
				'editor_script'   => 'dgl-feed-block',
				'attributes'      => [
					'type'    => [ 'type' => 'string', 'default' => Feed::DEFAULT_TYPE ],
					'topic'   => [ 'type' => 'string', 'default' => '' ],
					'count'   => [ 'type' => 'number', 'default' => Feed::DEFAULT_COUNT ],
					'heading' => [ 'type' => 'string', 'default' => '' ],
					'link'    => [ 'type' => 'boolean', 'default' => true ],
				],
				'render_callback' => [ self::class, 'render' ],
			]
		);

		// The editor's two dropdowns. Printed before the block script runs.
		wp_add_inline_script(
			'dgl-feed-block',
			'window.dglFeed = ' . wp_json_encode(
				[
					'types'  => self::options( Feed::type_choices() ),
					'topics' => array_merge(
						[ [ 'value' => '', 'label' => __( 'Any topic (the most recent)', 'dgl-platform' ) ] ],
						self::options( Feed::topic_choices() )
					),
					'max'    => Feed::MAX_COUNT,
				]
			) . ';',
			'before'
		);
	}

	/** The public stylesheet inside the editor, so the preview looks like the page. */
	public static function editor_style(): void {
		if ( is_admin() ) {
			Assets::style( 'assets/public.css', 'dgl-public' );
		}
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		$atts = [
			'type'  => (string) ( $attributes['type'] ?? '' ),
			'topic' => (string) ( $attributes['topic'] ?? '' ),
			'count' => $attributes['count'] ?? Feed::DEFAULT_COUNT,
			'link'  => ( $attributes['link'] ?? true ) ? 'yes' : 'no',
		];

		// An empty heading in the editor means "use the type's own", not "none".
		if ( '' !== trim( (string) ( $attributes['heading'] ?? '' ) ) ) {
			$atts['heading'] = (string) $attributes['heading'];
		}

		return HomeBlocks::render( 'feed', $atts );
	}

	/**
	 * @param array<string, string> $choices
	 * @return array<int, array{value:string, label:string}>
	 */
	private static function options( array $choices ): array {
		$out = [];

		foreach ( $choices as $value => $label ) {
			$out[] = [ 'value' => (string) $value, 'label' => (string) $label ];
		}

		return $out;
	}

	/* ---- Stand-in pictures ---------------------------------------------- */

	public static function menu(): void {
		add_options_page(
			__( 'Topic feed pictures', 'dgl-platform' ),
			__( 'Topic feed pictures', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'screen' ]
		);
	}

	public static function settings(): void {
		foreach ( array_keys( Feed::TYPES ) as $type ) {
			register_setting(
				self::GROUP,
				sprintf( Feed::IMAGE_OPTION, $type ),
				[ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ]
			);
		}
	}

	public static function admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();
		Assets::script( 'assets/feed-pictures.js', 'dgl-feed-pictures' );
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Topic feed pictures', 'dgl-platform' ); ?></h1>
			<p><?php esc_html_e( 'When a news story, event or course has no picture of its own, or its picture file has gone missing, the picture chosen here for that kind of content is shown instead: in topic feeds, on the News, Events and Training pages, on the home page lists and in search results. With none chosen, a plain coloured tile is shown.', 'dgl-platform' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( Feed::type_choices() as $type => $label ) : ?>
						<?php
						$name = sprintf( Feed::IMAGE_OPTION, $type );
						$id   = (int) get_option( $name, 0 );
						$url  = $id > 0 ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td data-dgl-picker>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" data-dgl-picker-id>
								<p><img src="<?php echo esc_url( $url ); ?>" alt="" style="max-width:240px;height:auto;<?php echo '' === $url ? 'display:none;' : ''; ?>" data-dgl-picker-preview></p>
								<button type="button" class="button" data-dgl-picker-choose><?php esc_html_e( 'Choose picture', 'dgl-platform' ); ?></button>
								<button type="button" class="button-link" data-dgl-picker-clear<?php echo 0 === $id ? ' hidden' : ''; ?>><?php esc_html_e( 'Remove', 'dgl-platform' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
