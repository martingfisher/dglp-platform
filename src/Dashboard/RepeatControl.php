<?php
/**
 * The "this event repeats" control.
 *
 * One fieldset: a tick box, then the pattern, the days, the end date and the
 * dates it does not run. Every part reveals through the same data-dgl-depends
 * mechanism the rest of the wizard uses, so without JavaScript everything is
 * simply visible and the validator decides. Shared by the wizard and the
 * wp-admin meta box, so the two cannot drift.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Events\Rule;
use DGL\Events\Wording;
use DGL\Schema\Field;

defined( 'ABSPATH' ) || exit;

final class RepeatControl {

	/**
	 * @param mixed $value The stored rule array, or the raw posted array on a failed save.
	 */
	public static function render( Field $field, string $id, string $name, mixed $value, string $aria ): string {
		$v   = is_array( $value ) ? $value : [];
		$on  = array_key_exists( 'on', $v ) ? in_array( (string) $v['on'], [ '1', 'on', 'yes', 'true' ], true ) : '' !== (string) ( $v['freq'] ?? '' );
		$req = static fn( string $suffix ): string => esc_attr( $name . '[' . $suffix . ']' );
		$freq    = (string) ( $v['freq'] ?? Rule::WEEKLY );
		$monthly = (string) ( $v['monthly'] ?? Rule::BY_DAY );
		$chosen  = array_map( 'intval', (array) ( $v['weekdays'] ?? [] ) );
		$until   = (string) ( $v['until'] ?? '' );
		$skip    = is_array( $v['skip'] ?? null ) ? implode( "\n", $v['skip'] ) : (string) ( $v['skip'] ?? '' );
		$latest  = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . Rule::MAX_MONTHS_AHEAD . ' months' )->format( 'Y-m-d' );

		$out  = sprintf( '<fieldset class="dgl-repeat" id="%s"%s><legend class="dgl-label">%s</legend>', esc_attr( $id ), $aria, esc_html( $field->label ) );
		// Marks a form post, so an unticked box with JavaScript off (which still posts the parts) reads as off; a stored rule has no such key.
		$out .= sprintf( '<input type="hidden" name="%s" value="1">', $req( 'posted' ) );
		$out .= sprintf(
			'<label class="dgl-check" for="%1$s_on"><input type="checkbox" id="%1$s_on" name="%2$s" value="1"%3$s> <span>%4$s</span></label>',
			esc_attr( $id ),
			$req( 'on' ),
			$on ? ' checked' : '',
			esc_html__( 'This event repeats', 'dgl-platform' )
		);

		// Everything below shows only when the box is ticked.
		$out .= sprintf( '<div class="dgl-repeat__body" data-dgl-depends="%s" data-dgl-depends-on="1">', esc_attr( substr( $id, 4 ) . '_on' ) );

		$out .= sprintf( '<div class="dgl-field-row"><label class="dgl-label" for="%1$s_freq">%2$s</label><select class="dgl-field" id="%1$s_freq" name="%3$s">', esc_attr( $id ), esc_html__( 'How often', 'dgl-platform' ), $req( 'freq' ) );
		foreach ( [ Rule::WEEKLY => __( 'Every week', 'dgl-platform' ), Rule::FORTNIGHTLY => __( 'Every two weeks', 'dgl-platform' ), Rule::MONTHLY => __( 'Every month', 'dgl-platform' ) ] as $key => $label ) {
			$out .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $freq, $key, false ), esc_html( $label ) );
		}
		$out .= '</select></div>';

		// Weekly and fortnightly: which days.
		$out .= sprintf( '<div class="dgl-field-row" data-dgl-depends="%s" data-dgl-depends-on="%s">', esc_attr( substr( $id, 4 ) . '_freq' ), esc_attr( Rule::WEEKLY . '|' . Rule::FORTNIGHTLY ) );
		$out .= sprintf( '<fieldset class="dgl-choices dgl-repeat__days"><legend class="dgl-label">%s</legend>', esc_html__( 'On which days', 'dgl-platform' ) );
		$out .= sprintf( '<input type="hidden" name="%s[]" value="">', $req( 'weekdays' ) );
		for ( $d = 1; $d <= 7; $d++ ) {
			$out .= sprintf(
				'<label class="dgl-check" for="%1$s_d%2$d"><input type="checkbox" id="%1$s_d%2$d" name="%3$s[]" value="%2$d"%4$s> <span>%5$s</span></label>',
				esc_attr( $id ),
				$d,
				$req( 'weekdays' ),
				in_array( $d, $chosen, true ) ? ' checked' : '',
				esc_html( Wording::weekday_name( $d ) )
			);
		}
		$out .= '</fieldset>';
		$out .= sprintf( '<p class="dgl-help">%s</p></div>', esc_html__( 'The day of the start date is always included.', 'dgl-platform' ) );

		// Monthly: which pattern.
		$out .= sprintf( '<div class="dgl-field-row" data-dgl-depends="%s" data-dgl-depends-on="%s">', esc_attr( substr( $id, 4 ) . '_freq' ), esc_attr( Rule::MONTHLY ) );
		$out .= sprintf( '<fieldset class="dgl-repeat__monthly"><legend class="dgl-label">%s</legend>', esc_html__( 'On which day of the month', 'dgl-platform' ) );
		foreach (
			[
				Rule::BY_DAY  => __( 'The same date each month, for example the 15th', 'dgl-platform' ),
				Rule::BY_NTH  => __( 'The same weekday each month, for example the third Tuesday', 'dgl-platform' ),
				Rule::BY_LAST => __( 'The last such weekday of the month, for example the last Tuesday', 'dgl-platform' ),
			] as $key => $label
		) {
			$out .= sprintf(
				'<label class="dgl-check" for="%1$s_m_%2$s"><input type="radio" id="%1$s_m_%2$s" name="%3$s" value="%2$s"%4$s> <span>%5$s</span></label>',
				esc_attr( $id ),
				esc_attr( $key ),
				$req( 'monthly' ),
				checked( $monthly, $key, false ),
				esc_html( $label )
			);
		}
		$out .= '</fieldset>';
		$out .= sprintf( '<p class="dgl-help">%s</p></div>', esc_html__( 'Worked out from the start date. Months without that date are skipped.', 'dgl-platform' ) );

		// Until.
		$out .= sprintf(
			'<div class="dgl-field-row"><label class="dgl-label" for="%1$s_until">%2$s <span class="dgl-req" aria-hidden="true">*</span></label><input class="dgl-field" type="date" id="%1$s_until" name="%3$s" value="%4$s" max="%5$s"><p class="dgl-help">%6$s</p></div>',
			esc_attr( $id ),
			esc_html__( 'Runs until', 'dgl-platform' ),
			$req( 'until' ),
			esc_attr( '' !== $until ? $until : $latest ),
			esc_attr( $latest ),
			esc_html__( 'Up to six months ahead. Two weeks before this date we email the organisation\'s owners a one-click link to keep it going.', 'dgl-platform' )
		);

		// Skip dates.
		$out .= sprintf(
			'<div class="dgl-field-row"><label class="dgl-label" for="%1$s_skip">%2$s</label><textarea class="dgl-field dgl-field--area" id="%1$s_skip" name="%3$s" rows="3">%4$s</textarea><p class="dgl-help">%5$s</p></div>',
			esc_attr( $id ),
			esc_html__( 'Dates it does not run (optional)', 'dgl-platform' ),
			$req( 'skip' ),
			esc_textarea( $skip ),
			esc_html__( 'One per line, like 2026-12-23. Up to ten.', 'dgl-platform' )
		);

		return $out . '</div></fieldset>';
	}
}
