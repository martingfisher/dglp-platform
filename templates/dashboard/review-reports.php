<?php
/**
 * The month in numbers, for the review team, with the CSV downloads.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\PostTypes;
use DGL\Reports\Csv;

defined( 'ABSPATH' ) || exit;

$report  = (array) ( $data['report'] ?? [] );
$months  = (array) ( $data['months'] ?? [] );
$month   = (string) ( $data['month'] ?? '' );
$dec     = (array) ( $report['decisions'] ?? [] );
$by      = (array) ( $report['approved_by'] ?? [] );
$speed   = (array) ( $report['speed'] ?? [] );
$orgs    = (array) ( $report['organisations'] ?? [] );
$people  = (array) ( $report['members'] ?? [] );
$now     = (array) ( $report['now'] ?? [] );
$hours   = static function ( ?float $h ): string {
	if ( null === $h ) {
		return '-';
	}
	if ( $h < 1 ) {
		return __( 'Under an hour', 'dgl-platform' );
	}
	if ( $h < 48 ) {
		/* translators: %s: a number of hours. */
		return sprintf( __( '%s hours', 'dgl-platform' ), number_format_i18n( $h, 1 ) );
	}
	/* translators: %s: a number of days. */
	return sprintf( __( '%s days', 'dgl-platform' ), number_format_i18n( $h / 24, 1 ) );
};
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php esc_html_e( 'Reports', 'dgl-platform' ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Reports', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'One month in numbers, read from the audit trail, and the site as it stands today. Download any of it as a spreadsheet.', 'dgl-platform' ); ?></p>
	</div>
	<form class="dgl-reports__pick" method="get" action="<?php echo esc_url( Router::url( 'review', 'reports' ) ); ?>">
		<label class="dgl-label" for="dgl-report-month"><?php esc_html_e( 'Month', 'dgl-platform' ); ?></label>
		<select class="dgl-field" id="dgl-report-month" name="month">
			<?php foreach ( $months as $ym => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $ym ); ?>"<?php selected( $month, (string) $ym ); ?>><?php echo esc_html( (string) $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="dgl-button dgl-button--secondary" type="submit"><?php esc_html_e( 'Show', 'dgl-platform' ); ?></button>
	</form>
</header>

<h2 class="dgl-section__title dgl-reports__month"><?php echo esc_html( (string) ( $report['label'] ?? '' ) ); ?></h2>

<ul class="dgl-stats dgl-reports__stats">
	<?php
	$headline = [
		[ 'label' => __( 'Sent for review', 'dgl-platform' ), 'value' => $dec['submit'] ?? 0 ],
		[ 'label' => __( 'Approved', 'dgl-platform' ), 'value' => $dec['approve'] ?? 0 ],
		[ 'label' => __( 'Sent back', 'dgl-platform' ), 'value' => $dec['request_changes'] ?? 0 ],
		[ 'label' => __( 'Refused', 'dgl-platform' ), 'value' => $dec['reject'] ?? 0 ],
		[ 'label' => __( 'Median time to approve', 'dgl-platform' ), 'value' => $hours( isset( $speed['median_hours'] ) ? (float) $speed['median_hours'] : null ) ],
		[ 'label' => __( 'New organisations verified', 'dgl-platform' ), 'value' => $orgs['verified'] ?? 0 ],
	];
	?>
	<?php foreach ( $headline as $tile ) : ?>
		<li>
			<span class="dgl-stat dgl-stat--still">
				<span class="dgl-stat__label"><?php echo esc_html( (string) $tile['label'] ); ?></span>
				<span class="dgl-stat__value"><?php echo esc_html( (string) $tile['value'] ); ?></span>
			</span>
		</li>
	<?php endforeach; ?>
</ul>

<div class="dgl-reports__grid">
	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Decisions this month', 'dgl-platform' ); ?></h2>
		<table class="dgl-reports__table">
			<tbody>
				<?php foreach ( $dec as $action => $n ) : ?>
					<tr><th scope="row"><?php echo esc_html( Csv::decision_label( (string) $action ) ); ?></th><td><?php echo esc_html( (string) $n ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="dgl-help">
			<a href="<?php echo esc_url( (string) ( $data['decisions_csv'] ?? '' ) ); ?>" download><?php esc_html_e( 'Download every decision this month as a spreadsheet', 'dgl-platform' ); ?></a>
		</p>
	</section>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'What was approved', 'dgl-platform' ); ?></h2>
		<table class="dgl-reports__table">
			<tbody>
				<?php foreach ( $by as $type => $n ) : ?>
					<tr><th scope="row"><?php echo esc_html( 'edits' === $type ? __( 'Edits to live listings', 'dgl-platform' ) : (string) ( PostTypes::definitions()[ $type ]['plural'] ?? $type ) ); ?></th><td><?php echo esc_html( (string) $n ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<h3 class="dgl-reports__sub"><?php esc_html_e( 'How quickly', 'dgl-platform' ); ?></h3>
		<table class="dgl-reports__table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Approvals timed', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $speed['count'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Median, submission to approval', 'dgl-platform' ); ?></th><td><?php echo esc_html( $hours( isset( $speed['median_hours'] ) ? (float) $speed['median_hours'] : null ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Longest', 'dgl-platform' ); ?></th><td><?php echo esc_html( $hours( isset( $speed['longest_hours'] ) ? (float) $speed['longest_hours'] : null ) ); ?></td></tr>
			</tbody>
		</table>
	</section>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Organisations and people', 'dgl-platform' ); ?></h2>
		<table class="dgl-reports__table">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'New organisations registered', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $orgs['registered'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Verified', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $orgs['verified'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Refused', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $orgs['refused'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Name or logo changes accepted', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $orgs['changes_accepted'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Name or logo changes refused', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $orgs['changes_refused'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'People joined by email match', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $people['joined'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'People joined by invitation', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $people['invited'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'People removed', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $people['removed'] ?? 0 ) ); ?></td></tr>
			</tbody>
		</table>
	</section>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'The site today', 'dgl-platform' ); ?></h2>
		<table class="dgl-reports__table">
			<tbody>
				<?php foreach ( (array) ( $now['live'] ?? [] ) as $type => $n ) : ?>
					<tr><th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plural type label. */ __( '%s on the site', 'dgl-platform' ), (string) ( PostTypes::definitions()[ $type ]['plural'] ?? $type ) ) ); ?></th><td><?php echo esc_html( (string) $n ); ?></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><?php esc_html_e( 'Waiting for a decision', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $now['waiting'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Verified organisations', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $now['organisations'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Member accounts', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) ( $now['members'] ?? 0 ) ); ?></td></tr>
			</tbody>
		</table>
		<h3 class="dgl-reports__sub"><?php esc_html_e( 'Download every listing', 'dgl-platform' ); ?></h3>
		<p class="dgl-help"><?php esc_html_e( 'One spreadsheet per type: every listing ever sent, whatever its state, with its organisation, dates, topics and every field.', 'dgl-platform' ); ?></p>
		<ul class="dgl-reports__downloads">
			<?php foreach ( (array) ( $data['exports'] ?? [] ) as $label => $url ) : ?>
				<li><a href="<?php echo esc_url( (string) $url ); ?>" download><?php echo esc_html( (string) $label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</section>
</div>
