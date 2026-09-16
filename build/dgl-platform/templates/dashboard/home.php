<?php
/**
 * Dashboard home. Wireframe 1d.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

use DGL\Dashboard\Router;

$counts    = $data['counts'] ?? [];
$org       = $data['org'] ?? null;
$filter    = (string) ( $data['filter'] ?? '' );
$attention = $data['attention'] ?? [];
$has_any   = ! empty( $data['has_any'] );

$tiles = [
	[ 'label' => __( 'Awaiting review', 'dgl-platform' ), 'status' => Statuses::PENDING, 'accent' => false ],
	[ 'label' => __( 'Live on site', 'dgl-platform' ), 'status' => Statuses::LIVE, 'accent' => false ],
	[ 'label' => __( 'Needs your attention', 'dgl-platform' ), 'status' => Statuses::CHANGES, 'accent' => true ],
	[ 'label' => __( 'Drafts', 'dgl-platform' ), 'status' => Statuses::DRAFT, 'accent' => false ],
];
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Your dashboard', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php if ( null !== $org ) : ?>
				<?php
				printf(
					/* translators: %s: organisation name. */
					esc_html__( 'Posting as %s. Everything you submit is checked before it appears on the site.', 'dgl-platform' ),
					esc_html( get_the_title( $org ) )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'Everything you submit is checked before it appears on the site.', 'dgl-platform' ); ?>
			<?php endif; ?>
		</p>
	</div>

	<?php /* Straight to the forms, per the wireframe. An anchor, so it works with no JavaScript. */ ?>
	<?php if ( null !== $org ) : ?>
		<a class="dgl-button" href="#dgl-submit-heading"><?php esc_html_e( 'New submission', 'dgl-platform' ); ?></a>
	<?php endif; ?>
</header>

<?php
/*
 * Anything the team has sent back is the only thing on this page that demands
 * something of the member, so it is named and linked rather than left as one
 * number among four.
 */
?>
<?php if ( ! empty( $attention ) ) : ?>
	<div class="dgl-alert dgl-alert--edit" role="status">
		<p>
			<strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: how many submissions were sent back. */
						_n(
							'The team have asked for a change on %d submission.',
							'The team have asked for a change on %d submissions.',
							count( $attention ),
							'dgl-platform'
						),
						count( $attention )
					)
				);
				?>
			</strong>
			<?php esc_html_e( 'Nothing is on the site until you send it back.', 'dgl-platform' ); ?>
		</p>
		<ul class="dgl-alert__list">
			<?php foreach ( $attention as $row ) : ?>
				<li><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php
/*
 * A member with nothing yet sees four noughts above the only thing they can
 * usefully do. The tiles stand down until there is something to count.
 */
?>
<?php if ( $has_any ) : ?>
	<ul class="dgl-stats">
		<?php foreach ( $tiles as $tile ) : ?>
			<?php
			$value = (int) ( $counts[ $tile['status'] ] ?? 0 );
			$is_on = $filter === $tile['status'];
			?>
			<li>
				<a
					class="dgl-stat<?php echo $tile['accent'] && $value > 0 ? ' dgl-stat--attention' : ''; ?><?php echo $is_on ? ' dgl-stat--on' : ''; ?>"
					href="<?php echo esc_url( $is_on ? Router::url() : add_query_arg( 'status', $tile['status'], Router::url() ) ); ?>"
					<?php echo $is_on ? 'aria-current="true"' : ''; ?>
				>
					<span class="dgl-stat__label"><?php echo esc_html( $tile['label'] ); ?></span>
					<span class="dgl-stat__value"><?php echo esc_html( (string) $value ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php $staff_only = null === $org && isset( $data['user'] ) && $data['user']->is_moderator(); ?>
<?php if ( $staff_only ) : ?>
	<?php /* The review team do not submit. Their dashboard is the queue. */ ?>
	<section class="dgl-card dgl-section" aria-labelledby="dgl-staff-heading">
		<h2 class="dgl-section__title" id="dgl-staff-heading"><?php esc_html_e( 'You are on the review team', 'dgl-platform' ); ?></h2>
		<p><?php esc_html_e( 'Member submissions, organisation changes and new organisations all land in the review queue. Nothing waits anywhere else.', 'dgl-platform' ); ?></p>
		<p><a class="dgl-button" href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Open the review queue', 'dgl-platform' ); ?></a></p>
	</section>
<?php elseif ( null === $org ) : ?>
	<?php
	/*
	 * No organisation, no tiles. They led to a wizard that could only end in
	 * "your account is not linked to an organisation yet", four screens later.
	 */
	?>
	<section class="dgl-card dgl-section" aria-labelledby="dgl-noorg-heading">
		<h2 class="dgl-section__title" id="dgl-noorg-heading"><?php esc_html_e( 'Your account is not linked to an organisation yet', 'dgl-platform' ); ?></h2>
		<p><?php esc_html_e( 'Submissions are made on behalf of an organisation, so there is nothing to start until you are part of one. If somebody at your organisation already uses the member area, ask them to invite you from their Members page. Otherwise the DGLP team can link you.', 'dgl-platform' ); ?></p>
	</section>
<?php else : ?>
<section class="dgl-section" aria-labelledby="dgl-submit-heading">
	<div class="dgl-section__head">
		<h2 class="dgl-section__title" id="dgl-submit-heading"><?php esc_html_e( 'Submit something', 'dgl-platform' ); ?></h2>
		<p class="dgl-section__note"><?php esc_html_e( 'Each type has its own short form', 'dgl-platform' ); ?></p>
	</div>

	<ul class="dgl-tiles">
		<?php foreach ( $data['tiles'] ?? [] as $tile ) : ?>
			<li>
				<a class="dgl-tile" href="<?php echo esc_url( $tile['url'] ); ?>">
					<span class="dgl-tile__label"><?php echo esc_html( $tile['label'] ); ?></span>
					<span class="dgl-tile__blurb"><?php echo esc_html( $tile['blurb'] ); ?></span>
					<span class="dgl-tile__cta"><?php esc_html_e( 'Start a submission', 'dgl-platform' ); ?> <span aria-hidden="true">-&gt;</span></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
<?php endif; ?>

<?php if ( ! $staff_only ) : ?>
<section class="dgl-section" aria-labelledby="dgl-recent-heading">
	<div class="dgl-section__head">
		<h2 class="dgl-section__title" id="dgl-recent-heading">
			<?php
			if ( '' !== $filter ) {
				printf(
					/* translators: %s: a status name, for example "Drafts". */
					esc_html__( 'Showing: %s', 'dgl-platform' ),
					esc_html( Statuses::label( $filter ) )
				);
			} else {
				esc_html_e( 'Recent activity', 'dgl-platform' );
			}
			?>
		</h2>
		<?php if ( '' !== $filter ) : ?>
			<p class="dgl-section__note">
				<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Show everything', 'dgl-platform' ); ?></a>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( empty( $data['recent'] ) ) : ?>
		<p class="dgl-empty">
			<?php
			echo '' !== $filter
				? esc_html__( 'Nothing with that status.', 'dgl-platform' )
				: esc_html__( 'Nothing here yet. Once you submit something it will show up here with its status.', 'dgl-platform' );
			?>
		</p>
	<?php else : ?>
		<table class="dgl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'dgl-platform' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['recent'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $row['type'] ); ?></td>
						<td><?php echo View::chip( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( View::date( $row['updated'], true ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
<?php endif; ?>
