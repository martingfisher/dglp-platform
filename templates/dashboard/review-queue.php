<?php
/**
 * Moderation queue. Wireframe 1k.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;
?>
<?php
$decided_copy = [
	'approve'   => __( 'Approved and published. The member has been told.', 'dgl-platform' ),
	'changes'   => __( 'Sent back with your note. The member has been told.', 'dgl-platform' ),
	'reject'    => __( 'Refused. The member has been told, with your reason.', 'dgl-platform' ),
	'take_down' => __( 'Taken off the site. It is back in this queue, and the member has been told why.', 'dgl-platform' ),
	'reopen'    => __( 'Reopened. It is back in this queue to be decided again, and the member has been told.', 'dgl-platform' ),
	'restore'   => __( 'Restored. It is back in this queue to be decided again.', 'dgl-platform' ),
	'org_approve' => __( 'Organisation change accepted. Their listings carry the new details from now, and the owners have been told.', 'dgl-platform' ),
	'org_refuse'  => __( 'Organisation change refused. The member has been told, with your reason.', 'dgl-platform' ),
	'join_approve' => __( 'Approved. They can submit now, and they have been told.', 'dgl-platform' ),
	'join_refuse'  => __( 'Refused. The person has been told why.', 'dgl-platform' ),
	'join_attach'  => __( 'Attached to the organisation already on the list. The person has been told, and so have its owners.', 'dgl-platform' ),
];
$decided = (string) ( $data['decided'] ?? '' );
?>
<?php if ( isset( $decided_copy[ $decided ] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status"><p><?php echo esc_html( $decided_copy[ $decided ] ); ?></p></div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php esc_html_e( 'Member submissions waiting for a decision. Oldest first.', 'dgl-platform' ); ?>
			<a href="<?php echo esc_url( \DGL\Dashboard\Router::url( 'review', 'decided' ) ); ?>"><?php esc_html_e( 'Look over what has been decided', 'dgl-platform' ); ?></a>
		</p>
	</div>
</header>

<?php
View::output(
	'dashboard/list-table',
	[
		'items'  => $data['items'] ?? [],
		'action' => __( 'Review', 'dgl-platform' ),
		'empty'  => __( 'The queue is empty. New submissions land here as soon as members send them.', 'dgl-platform' ),
	]
);
?>

<?php if ( ! empty( $data['org_changes'] ) ) : ?>
	<section class="dgl-section" aria-labelledby="dgl-orgchanges-heading">
		<div class="dgl-section__head">
			<h2 class="dgl-section__title" id="dgl-orgchanges-heading"><?php esc_html_e( 'Organisation changes waiting', 'dgl-platform' ); ?></h2>
			<p class="dgl-section__note"><?php esc_html_e( 'A name or logo a member has asked to change', 'dgl-platform' ); ?></p>
		</div>

		<table class="dgl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Organisation', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Wants to change', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Waiting since', 'dgl-platform' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['org_changes'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td><?php echo esc_html( $row['what'] ); ?></td>
						<td><?php echo esc_html( $row['since'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>

<?php if ( ! empty( $data['joins'] ) ) : ?>
	<section class="dgl-section" aria-labelledby="dgl-joins-heading">
		<div class="dgl-section__head">
			<h2 class="dgl-section__title" id="dgl-joins-heading"><?php esc_html_e( 'Joining requests to check', 'dgl-platform' ); ?></h2>
			<p class="dgl-section__note"><?php esc_html_e( 'People whose email address matched nothing on the list: an organisation they registered, or one they picked', 'dgl-platform' ); ?></p>
		</div>

		<table class="dgl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Request', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Waiting since', 'dgl-platform' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['joins'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['asks'] ); ?></a></td>
						<td><?php echo esc_html( $row['who'] ); ?> <span class="dgl-help"><?php echo esc_html( $row['email'] ); ?></span></td>
						<td><?php echo esc_html( $row['since'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
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
		'noun'  => __( 'waiting', 'dgl-platform' ),
		'label' => __( 'Review queue pages', 'dgl-platform' ),
	]
);
