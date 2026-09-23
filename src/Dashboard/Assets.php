<?php
/**
 * Stylesheet loading for the member area.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the dashboard stylesheet, and only on dashboard requests.
 *
 * Version is the file's modification time rather than the plugin version, so a
 * CSS change busts the cache without needing a release. That matters more than
 * usual here: the site sits behind a CDN with a 24 hour TTL.
 */
final class Assets {

	public const HANDLE = 'dgl-dashboard';

	public static function enqueue( bool $with_editor = false ): void {
		self::style( 'assets/dashboard.css', self::HANDLE );
		self::script( 'assets/dashboard.js', self::HANDLE );

		wp_add_inline_script(
			self::HANDLE,
			'window.dglUpload = ' . wp_json_encode(
				[
					'uploading'  => __( 'Uploading the picture…', 'dgl-platform' ),
					'describing' => __( 'Picture uploaded. Writing a description of it…', 'dgl-platform' ),
					'suggested'  => __( 'Picture uploaded. We have suggested a description below; change it if it does not say what the picture shows.', 'dgl-platform' ),
					'done'       => __( 'Picture uploaded.', 'dgl-platform' ),
					'noAlt'      => __( 'Picture uploaded. No description came back; add one below if you can.', 'dgl-platform' ),
					'failed'     => __( 'Could not upload it now. It will be sent when you save this step.', 'dgl-platform' ),
				]
			) . ';',
			'before'
		);
		self::autoload();

		if ( $with_editor ) {
			/*
			 * Front-end wp_editor() only works if the editor's own assets are
			 * queued before wp_head runs. This is called from the controller
			 * ahead of get_header(), which is the window where that is still
			 * possible.
			 */
			wp_enqueue_editor();
		}
	}

	/**
	 * Enqueue one of the plugin's stylesheets, tokens first.
	 *
	 * The tokens live in their own file so the member area and the public
	 * pages share one definition of the palette rather than two that drift.
	 * Everything else depends on it, so nothing can load a stylesheet whose
	 * every colour resolves to nothing.
	 */
	public static function style( string $relative, string $handle, array $deps = [] ): void {
		$path = \DGL\PLUGIN_DIR . $relative;

		if ( 'dgl-tokens' !== $handle ) {
			self::style( 'assets/tokens.css', 'dgl-tokens' );
			$deps[] = 'dgl-tokens';
		}

		wp_enqueue_style(
			$handle,
			\DGL\PLUGIN_URL . $relative,
			$deps,
			is_readable( $path ) ? (string) filemtime( $path ) : \DGL\VERSION
		);
	}

	/**
	 * The load-more-on-scroll script, shared by the member area and the
	 * public pages, with its few words translated.
	 */
	public static function autoload(): void {
		self::script( 'assets/autoload.js', 'dgl-autoload' );

		wp_add_inline_script(
			'dgl-autoload',
			'window.dglAutoload = ' . wp_json_encode(
				[
					'more'    => __( 'Load more', 'dgl-platform' ),
					'loading' => __( 'Loading…', 'dgl-platform' ),
					/* translators: 1: how many were just added, 2: how many are now shown. */
					'loaded'  => __( '%1$d more loaded, showing %2$d.', 'dgl-platform' ),
					'all'     => __( 'That is everything.', 'dgl-platform' ),
					'failed'  => __( 'Could not load more. The page links are below.', 'dgl-platform' ),
				]
			) . ';',
			'before'
		);
	}

	public static function script( string $relative, string $handle ): void {
		$path = \DGL\PLUGIN_DIR . $relative;

		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			\DGL\PLUGIN_URL . $relative,
			[],
			(string) filemtime( $path ),
			true
		);
	}
}
