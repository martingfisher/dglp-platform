<?php
/**
 * The site search behind a magnifying glass in the header.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\View;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Three small hooks make one feature. The theme's own search box (a
 * Blocksy Pro "Search Input" element in the top row) is dropped from the
 * header layout as it is read, so nothing stored changes and switching the
 * plugin off brings it straight back. A button with a magnifier joins the
 * end of the Top Bar menu, where the box was, and the end of the Main Menu
 * for the phone drawer. Pressing it opens a dialog in the middle of the
 * screen: one box, a row of kinds to search (all, events, news, training,
 * spaces to hire, organisations) and Search. The dialog is the ordinary
 * search form, so it lands on the same results page as before.
 */
final class Finder {

	/** The header element id the theme gives its search box. */
	public const SEARCH_ELEMENT = 'search-input';

	/** The menu at the top right of the desktop header, where the box was. */
	public const TOP_BAR_MENU = 'top-bar';

	/** The menu the phone drawer shows. */
	public const MAIN_MENU = 'main-menu';

	public const DIALOG_ID = 'dgl-finder';

	public static function init(): void {
		add_filter( 'theme_mod_header_placements', [ self::class, 'without_search_box' ] );
		add_filter( 'wp_nav_menu_items', [ self::class, 'menu_items' ], 20, 2 );
		add_action( 'wp_footer', [ self::class, 'overlay' ] );
	}

	/**
	 * The header layout without the theme's search box.
	 *
	 * @param mixed $placements Whatever the option holds.
	 * @return mixed
	 */
	public static function without_search_box( mixed $placements ): mixed {
		return is_array( $placements ) ? self::strip( $placements, self::SEARCH_ELEMENT ) : $placements;
	}

	/**
	 * Remove one element id from every placement's list of items, however
	 * deep. Pure. The element's own settings (an array with an 'id' key)
	 * are left where they are; only the strings that place it go.
	 *
	 * @param array<mixed> $tree
	 * @return array<mixed>
	 */
	public static function strip( array $tree, string $element ): array {
		foreach ( $tree as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( 'items' === $key && [] === array_filter( $value, static fn( mixed $v ): bool => ! is_string( $v ) ) ) {
				$tree[ $key ] = array_values( array_filter( $value, static fn( string $id ): bool => $id !== $element ) );
				continue;
			}

			$tree[ $key ] = self::strip( $value, $element );
		}

		return $tree;
	}

	/**
	 * The kinds a visitor can narrow to, in the order the dialog shows them:
	 * everything first, then what people come for most.
	 *
	 * @return array<string, string> Key ('' for all) => label.
	 */
	public static function categories(): array {
		$order = [ 'events', 'news', 'training', 'spaces', 'organisations' ];
		$out   = [ '' => __( 'All', 'dgl-platform' ) ];

		foreach ( $order as $key ) {
			if ( isset( Search::groups()[ $key ] ) ) {
				$out[ $key ] = Search::label( $key );
			}
		}

		return $out;
	}

	/**
	 * Add the trigger to the end of the two menus that carry it.
	 *
	 * @param string $items The menu's list items.
	 * @param object $args  The menu arguments, with `menu` resolved to a term.
	 */
	public static function menu_items( string $items, object $args ): string {
		if ( is_admin() || '' === self::placement( $args ) ) {
			return $items;
		}

		return $items . self::item( self::placement( $args ) );
	}

	/**
	 * Which menu this is: 'top-bar', 'main' or '' for one we leave alone.
	 *
	 * @param object $args
	 */
	public static function placement( object $args ): string {
		$menu = $args->menu ?? null;
		$slug = $menu instanceof WP_Term ? (string) $menu->slug : '';

		if ( '' === $slug && is_scalar( $menu ) ) {
			$term = is_numeric( $menu ) ? get_term( (int) $menu, 'nav_menu' ) : get_term_by( 'slug', (string) $menu, 'nav_menu' );
			$slug = $term instanceof WP_Term ? (string) $term->slug : '';
		}

		return match ( $slug ) {
			self::TOP_BAR_MENU => 'top-bar',
			self::MAIN_MENU    => 'main',
			default            => '',
		};
	}

	/**
	 * One list item holding the button. The label is always in the markup;
	 * the stylesheet hides it beside the icon in the desktop header and
	 * shows it in the phone drawer, where an icon alone among words would
	 * read badly.
	 */
	public static function item( string $placement ): string {
		return sprintf(
			'<li class="menu-item dgl-finder-item dgl-finder-item--%1$s">%2$s</li>',
			esc_attr( $placement ),
			self::trigger()
		);
	}

	public static function trigger(): string {
		return sprintf(
			'<button type="button" class="ct-menu-link dgl-finder-trigger" data-dgl-finder-open aria-haspopup="dialog" aria-controls="%1$s" aria-expanded="false"><span class="dgl-finder-trigger__ring">%2$s</span><span class="dgl-finder-trigger__label">%3$s</span></button>',
			esc_attr( self::DIALOG_ID ),
			self::icon(),
			esc_html__( 'Search', 'dgl-platform' )
		);
	}

	/**
	 * A magnifier drawn in strokes only. The theme paints every svg in a
	 * menu with a fill, so the stylesheet turns that off again; the
	 * attribute alone was a filled disc on a stick.
	 */
	public static function icon(): string {
		return '<svg class="dgl-finder-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5" fill="none"/><path d="M20 20l-4.6-4.6"/></svg>';
	}

	/** The dialog, once, at the foot of every public page. */
	public static function overlay(): void {
		if ( is_admin() ) {
			return;
		}

		View::output(
			'public/finder',
			[
				'id'         => self::DIALOG_ID,
				'action'     => home_url( '/' ),
				'categories' => self::categories(),
				'term'       => is_search() ? Search::term_from( wp_unslash( $_GET ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public search.
				'type'       => is_search() ? Search::type_from( wp_unslash( $_GET ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			]
		);
	}

	/**
	 * The row of kinds, shared by the dialog and the results page.
	 *
	 * @param array<string, string> $categories
	 */
	public static function chips( array $categories, string $chosen, string $name_prefix ): string {
		$out = '';

		foreach ( $categories as $key => $label ) {
			$id   = $name_prefix . '-' . ( '' === $key ? 'all' : $key );
			$out .= sprintf(
				'<label class="dgl-finder__chip" for="%1$s"><input type="radio" name="%2$s" id="%1$s" value="%3$s"%4$s><span>%5$s</span></label>',
				esc_attr( $id ),
				esc_attr( Search::PARAM_TYPE ),
				esc_attr( $key ),
				$chosen === $key ? ' checked' : '',
				esc_html( $label )
			);
		}

		return $out;
	}
}
