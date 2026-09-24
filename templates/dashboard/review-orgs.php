<?php
/**
 * Every organisation, for the review team: who is trusted for what.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;

$rows = $data['rows'] ?? [];
$q    = (string) ( $data['q'] ?? '' );
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span> <?php esc_html_e( 'Organisations', 'dgl-platform' ); ?>
		</p>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Organisations', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede"><?php esc_html_e( 'Every organisation on the platform. Open one to decide what it may publish without review, and to see its people and details. Verification and email domains are still set in wp-admin.', 'dgl-platform' ); ?></p>
	</div>
</header>

<form class="dgl-orgsearch" method="get" action="<?php echo esc_url( Router::url( 'review', 'orgs' ) ); ?>" role="search">
	<label class="dgl-label" for="dgl-orgsearch-q"><?php esc_html_e( 'Find an organisation', 'dgl-platform' ); ?></label>
	<div class="dgl-orgsearch__row">
		<input class="dgl-field" type="search" id="dgl-orgsearch-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Part of the name', 'dgl-platform' ); ?>">
		<button class="dgl-button" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
		<?php if ( '' !== $q ) : ?>
			<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'review', 'orgs' ) ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
		<?php endif; ?>
	</div>
</form>

<?php if ( [] === $rows ) : ?>
	<p class="dgl-empty">
		<?php
		if ( '' === $q ) {
			esc_html_e( 'No organisations yet.', 'dgl-platform' );
		} else {
			printf(
				/* translators: %s: what was searched for. */
				esc_html__( 'Nothing called "%s".', 'dgl-platform' ),
				esc_html( $q )
			);
		}
		?>
	</p>
<?php else : ?>
	<table class="dgl-table dgl-orgs">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Organisation', 'dgl-platform' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Verification', 'dgl-platform' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Trust', 'dgl-platform' ); ?></th>
				<th scope="col" class="dgl-orgs__num"><?php esc_html_e( 'Members', 'dgl-platform' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<a class="dgl-orgs__name" href="<?php echo esc_url( (string) $row['url'] ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a>
						<?php if ( ! empty( $row['waiting'] ) ) : ?>
							<span class="dgl-chip dgl-chip--pending"><?php esc_html_e( 'Change waiting', 'dgl-platform' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></td>
					<td class="dgl-orgs__trust">
						<?php echo esc_html( (string) $row['trust'] ); ?>
						<?php if ( ! empty( $row['trusted'] ) && empty( $row['approved'] ) ) : ?>
							<span class="dgl-help"><?php esc_html_e( 'Not applying until verified.', 'dgl-platform' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="dgl-orgs__num" data-noun="<?php echo esc_attr( _n( 'member', 'members', (int) $row['members'], 'dgl-platform' ) ); ?>"><?php echo esc_html( (string) (int) $row['members'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php
View::output(
	'dashboard/pager',
	[
		'total' => (int) ( $data['total'] ?? 0 ),
		'page'  => (int) ( $data['page'] ?? 1 ),
		'pages' => (int) ( $data['pages'] ?? 1 ),
		'first' => (int) ( $data['first'] ?? 0 ),
		'last'  => (int) ( $data['last'] ?? 0 ),
		'base'  => (string) ( $data['base'] ?? '' ),
		'noun'  => __( 'organisations', 'dgl-platform' ),
		'label' => __( 'More organisations', 'dgl-platform' ),
	]
);
