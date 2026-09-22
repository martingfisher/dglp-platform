<?php
/**
 * What a list card says about an item: its chip, its picture, its meta line.
 *
 * The public lists show every item the same way, a square picture and three
 * lines, so the eye can run down them. This is the one place those lines
 * are decided, for the featured block and the rows alike.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\View;
use DGL\Events\Cancel;
use DGL\Events\Series;
use DGL\PostTypes;
use DGL\Taxonomies;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Cards {

	/** Words a minute, for the reading time on a story. */
	public const WORDS_PER_MINUTE = 200;

	/** The chip: the item's first topic, else what kind of thing it is. */
	public static function chip( WP_Post $post ): string {
		$terms = wp_get_object_terms( (int) $post->ID, Taxonomies::TOPIC, [ 'fields' => 'names', 'orderby' => 'name' ] );

		if ( is_array( $terms ) && [] !== $terms ) {
			return (string) $terms[0];
		}

		return Frontend::type_label( (string) $post->post_type );
	}

	/**
	 * The meta line, as segments the template joins with a slash.
	 *
	 * News: organisation, date, reading time. Events: when, where,
	 * organisation. Anything else: its date, where, organisation.
	 *
	 * @return string[]
	 */
	public static function meta( WP_Post $post ): array {
		$org  = Frontend::organisation( $post )['name'];
		$type = (string) $post->post_type;

		if ( PostTypes::NEWS === $type ) {
			return array_values( array_filter( [ $org, self::posted( $post ), self::reading_time( $post ) ] ) );
		}

		$parts = [];

		if ( PostTypes::EVENT === $type && Cancel::is_cancelled( (int) $post->ID ) ) {
			$parts[] = __( 'Cancelled', 'dgl-platform' );
		}

		$parts[] = self::when( $post );
		$parts[] = Frontend::where( $post );
		$parts[] = $org;

		return array_values( array_filter( $parts ) );
	}

	/** The day it was published, as people write it. */
	public static function posted( WP_Post $post ): string {
		return (string) get_the_date( 'j M Y', $post );
	}

	/** "3 min read", from the story's words. Never under a minute. */
	public static function reading_time( WP_Post $post ): string {
		$words = str_word_count( wp_strip_all_tags( (string) $post->post_content . ' ' . (string) Frontend::value( $post, 'summary' ) ) );

		if ( 0 === $words ) {
			return '';
		}

		$minutes = max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );

		/* translators: %d: minutes. */
		return sprintf( __( '%d min read', 'dgl-platform' ), $minutes );
	}

	/**
	 * When it happens, in one short phrase: the next date of a series, or
	 * the start of a one-off, with the time when there is one.
	 */
	public static function when( WP_Post $post ): string {
		$id = (int) $post->ID;

		if ( Series::is_series( $id ) ) {
			$next = Series::next_dates( $id, 1 );

			if ( [] !== $next ) {
				/* translators: 1: a date like "Tue 29 Sep", 2: a time like "13:00". */
				return sprintf( __( 'Next %1$s, %2$s', 'dgl-platform' ), wp_date( 'D j M', $next[0]->start->getTimestamp() ), wp_date( 'H:i', $next[0]->start->getTimestamp() ) );
			}
		}

		foreach ( [ 'start_datetime', 'start_date', 'deadline' ] as $key ) {
			$raw = (string) Frontend::value( $post, $key );

			if ( '' !== $raw ) {
				return View::wall_date( $raw, str_contains( $raw, ':' ) );
			}
		}

		return '';
	}

	/**
	 * The picture, or a tile that stands in for one.
	 *
	 * @param string $size A registered image size.
	 * @param bool   $eager Whether the browser should fetch it at once (the featured picture).
	 */
	public static function picture( WP_Post $post, string $size = 'medium_large', bool $eager = false ): string {
		$image_id = (int) Frontend::value( $post, 'image' );

		if ( $image_id > 0 ) {
			$img = wp_get_attachment_image( $image_id, $size, false, [ 'loading' => $eager ? 'eager' : 'lazy', 'alt' => '', 'class' => 'dgl-card__img' ] );

			if ( '' !== $img ) {
				return $img;
			}
		}

		// No picture: a plain tile with one word for what it is, so the row keeps
		// its shape and nobody mistakes the gap for a broken image.
		$word = (string) strtok( Frontend::type_label( (string) $post->post_type ), ' ' );

		return '<span class="dgl-card__img dgl-card__img--none" aria-hidden="true"><span>' . esc_html( $word ) . '</span></span>';
	}

	public static function has_picture( WP_Post $post ): bool {
		return (int) Frontend::value( $post, 'image' ) > 0;
	}
}
