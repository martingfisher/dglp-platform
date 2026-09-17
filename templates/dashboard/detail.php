<?php
/**
 * One submission, its status and its history. Wireframe 1g.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Schema\Field;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$post     = $data['post'];
$values   = $data['values'] ?? [];
$revision = $data['revision'] ?? null;
$pending  = $revision instanceof WP_Post && Statuses::PENDING === $revision->post_status;
?>
<?php if ( ! empty( $data['archived'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Archived.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'It is off the site and kept here. Restore it at any time; it goes back through review first.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $data['restored'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Restored and sent for review.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'The team will read it again before it goes back on the site.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $data['scheduled'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Dates and times saved.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'The listing already shows them. Nothing went through review.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $data['extended'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Kept on the site.', 'dgl-platform' ); ?></strong>
		<?php
		printf(
			/* translators: %s: a date. */
			esc_html__( 'It is listed until %s. We will ask again two weeks before then.', 'dgl-platform' ),
			esc_html( (string) ( $data['series_until'] ?? '' ) )
		);
		?></p>
	</div>
<?php endif; ?>

<?php if ( '' !== (string) ( $data['action_error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $data['action_error'] ); ?></p></div>
<?php endif; ?>

<?php if ( ! empty( $data['submitted'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Sent for review.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'Somebody will read it and either publish it or come back to you. You will get an email either way.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $data['discarded'] ) ) : ?>
	<div class="dgl-alert" role="status">
		<p><strong><?php esc_html_e( 'Edit discarded.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'The version below is what is on the site, and it has not changed.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php
/*
 * The edit is announced, never rendered in place. What is shown below this is
 * always the published version, so nobody reads an unapproved change and takes
 * it for what is on the site.
 */
?>
<?php if ( $revision instanceof WP_Post ) : ?>
	<div class="dgl-alert dgl-alert--edit" role="status">
		<p>
			<strong>
				<?php echo $pending
					? esc_html__( 'You have an edit waiting for review.', 'dgl-platform' )
					: esc_html__( 'You have an unfinished edit.', 'dgl-platform' ); ?>
			</strong>
			<?php
			echo $pending
				? esc_html__( 'The version below is the one on the site. It stays up while the team read your edit, so nothing has come down.', 'dgl-platform' )
				: esc_html__( 'It has not been sent to anybody yet. The version below is still what is on the site.', 'dgl-platform' );
			?>
		</p>

		<p class="dgl-alert__actions">
			<?php if ( ! $pending ) : ?>
				<a class="dgl-button dgl-button--small" href="<?php echo esc_url( Router::url( 'edit', (string) $revision->ID, '1' ) ); ?>">
					<?php esc_html_e( 'Carry on editing', 'dgl-platform' ); ?>
				</a>
				<form method="post" action="<?php echo esc_url( Router::url( 'discard', (string) $revision->ID ) ); ?>" class="dgl-inline-form">
					<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
					<button type="submit" class="dgl-button dgl-button--small dgl-button--quiet"
						data-dgl-confirm="<?php esc_attr_e( 'Throw this edit away? The version on the site is not affected.', 'dgl-platform' ); ?>">
						<?php esc_html_e( 'Discard the edit', 'dgl-platform' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</p>
	</div>

<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Dashboard', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span>
			<a href="<?php echo esc_url( Router::url( $data['slug'] ) ); ?>"><?php echo esc_html( $data['singular'] ); ?></a>
		</p>
		<p class="dgl-detail__status">
			<?php echo View::chip( (string) $post->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</p>
		<h1 class="dgl-page-head__title">
			<?php echo esc_html( $post->post_title !== '' ? $post->post_title : __( 'Untitled', 'dgl-platform' ) ); ?>
		</h1>
	</div>

	<?php if ( ! empty( $data['can_edit'] ) && ! $pending ) : ?>
		<?php
		/*
		 * Where this goes depends on the state. An open edit is reopened rather
		 * than a second one started, because two pending edits to one item is a
		 * question with no good answer.
		 */
		$edit_target = $revision instanceof WP_Post ? (int) $revision->ID : (int) $post->ID;
		?>
		<a class="dgl-button" href="<?php echo esc_url( Router::url( 'edit', (string) $edit_target, '1' ) ); ?>">
			<?php
			if ( $revision instanceof WP_Post ) {
				esc_html_e( 'Carry on editing', 'dgl-platform' );
			} elseif ( Statuses::CHANGES === $post->post_status ) {
				esc_html_e( 'Edit and resubmit', 'dgl-platform' );
			} else {
				esc_html_e( 'Edit', 'dgl-platform' );
			}
			?>
		</a>
	<?php endif; ?>

	<?php if ( ! empty( $data['can_archive'] ) ) : ?>
		<form method="post" class="dgl-inline-form">
			<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
			<button type="submit" class="dgl-button dgl-button--secondary" name="dgl_intent" value="archive"
				data-dgl-confirm="<?php echo Statuses::LIVE === $post->post_status
					? esc_attr__( 'Take this off the site? It goes to your archive, and you can restore it later.', 'dgl-platform' )
					: esc_attr__( 'Archive this? You can restore it later.', 'dgl-platform' ); ?>">
				<?php echo Statuses::LIVE === $post->post_status
					? esc_html__( 'Take off the site', 'dgl-platform' )
					: esc_html__( 'Archive', 'dgl-platform' ); ?>
			</button>
		</form>
	<?php endif; ?>

	<?php if ( ! empty( $data['can_restore'] ) ) : ?>
		<form method="post" class="dgl-inline-form">
			<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
			<button type="submit" class="dgl-button" name="dgl_intent" value="restore"
				data-dgl-confirm="<?php esc_attr_e( 'Restore this? It goes to the review team before it is back on the site.', 'dgl-platform' ); ?>">
				<?php esc_html_e( 'Restore', 'dgl-platform' ); ?>
			</button>
		</form>
	<?php endif; ?>
</header>

<?php
/*
 * Below the title, not above it. A comparison that appears before the reader
 * knows which item they are looking at is a list of words with no subject.
 */
if ( $revision instanceof WP_Post ) {
	View::output(
		'dashboard/changes',
		[
			'changes'       => $data['changes'] ?? [],
			'changes_title' => __( 'What your edit would change', 'dgl-platform' ),
		]
	);
}
?>

<?php if ( ! empty( $data['can_schedule'] ) ) : ?>
	<section class="dgl-card dgl-schedule" aria-labelledby="dgl-schedule-title">
		<h2 class="dgl-section__title" id="dgl-schedule-title"><?php esc_html_e( 'Dates and times', 'dgl-platform' ); ?></h2>

		<?php if ( ! empty( $data['schedule_locked'] ) ) : ?>
			<p class="dgl-help"><?php esc_html_e( 'Finish or discard your open edit first. It carries the dates too, and two versions of the same schedule is a question with no good answer.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<p class="dgl-help"><?php esc_html_e( 'These apply as soon as you save them. Nothing goes through review, because a date is a fact and a wrong one for a week is worse than an unread one.', 'dgl-platform' ); ?></p>

			<?php if ( ! empty( $data['can_extend'] ) ) : ?>
				<form method="post" class="dgl-schedule__extend" action="<?php echo esc_url( Router::url( 'item', (string) $post->ID ) ); ?>">
					<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
					<p>
						<?php
						printf(
							/* translators: %s: a date. */
							esc_html__( 'Listed until %s. Still running after that?', 'dgl-platform' ),
							'<strong>' . esc_html( (string) ( $data['series_until'] ?? '' ) ) . '</strong>'
						);
						?>
					</p>
					<button type="submit" class="dgl-button" name="dgl_intent" value="extend">
						<?php esc_html_e( 'Keep it listed for another 6 months', 'dgl-platform' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<?php $schedule_errors = (array) ( $data['schedule_errors'] ?? [] ); ?>
			<?php if ( [] !== $schedule_errors ) : ?>
				<div class="dgl-alert" role="alert">
					<p><strong><?php esc_html_e( 'Not saved yet.', 'dgl-platform' ); ?></strong></p>
					<ul>
						<?php foreach ( $schedule_errors as $key => $message ) : ?>
							<li><a href="#dgl-<?php echo esc_attr( (string) $key ); ?>"><?php echo esc_html( (string) $message ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form class="dgl-form dgl-schedule__form" method="post" novalidate action="<?php echo esc_url( Router::url( 'item', (string) $post->ID ) ); ?>">
				<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
				<?php foreach ( (array) ( $data['schedule_fields'] ?? [] ) as $field ) : ?>
					<?php
					echo \DGL\Dashboard\FieldRenderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						$field,
						$data['schedule_values'][ $field->key ] ?? '',
						$schedule_errors[ $field->key ] ?? ''
					);
					?>
				<?php endforeach; ?>
				<div class="dgl-form__actions dgl-form__actions--alone">
					<button type="submit" class="dgl-button" name="dgl_intent" value="schedule">
						<?php esc_html_e( 'Save dates and times', 'dgl-platform' ); ?>
					</button>
				</div>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>

<div class="dgl-detail">
	<section class="dgl-card">
		<h2 class="dgl-section__title">
			<?php echo $revision instanceof WP_Post
				? esc_html__( 'What is on the site now', 'dgl-platform' )
				: esc_html__( 'What you submitted', 'dgl-platform' ); ?>
		</h2>
		<?php
		$filled = array_filter(
			$data['fields'],
			static fn( $field ): bool => ( is_array( $values[ $field->key ] ?? '' ) ? [] !== $values[ $field->key ] : '' !== (string) ( $values[ $field->key ] ?? '' ) ) || Field::CHECKBOX === $field->type
		);
		?>
		<?php if ( [] === $filled ) : ?>
			<p class="dgl-help"><?php esc_html_e( 'Nothing filled in yet. Open it to carry on.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<dl class="dgl-review__list">
				<?php foreach ( $filled as $field ) : ?>
					<dt><?php echo esc_html( $field->label ); ?></dt>
					<dd>
						<?php
						echo View::field_value( $field, $values[ $field->key ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						?>
					</dd>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
	</section>

	<aside class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'History', 'dgl-platform' ); ?></h2>

		<?php if ( empty( $data['history'] ) ) : ?>
			<p class="dgl-help"><?php esc_html_e( 'Nothing yet. Steps appear here once you send it.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<ol class="dgl-timeline">
				<?php foreach ( array_reverse( $data['history'] ) as $entry ) : ?>
					<li class="dgl-timeline__item">
						<p class="dgl-timeline__action">
							<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $entry['action'] ) ) ); ?>
							<?php if ( ! empty( $entry['is_edit'] ) ) : ?>
								<?php /* Otherwise "Approved" twice in one timeline reads as a repeat. */ ?>
								<span class="dgl-edit-flag"><?php esc_html_e( 'Edit', 'dgl-platform' ); ?></span>
							<?php endif; ?>
						</p>
						<p class="dgl-timeline__when"><?php echo esc_html( View::date( $entry['logged_at'], true ) ); ?></p>
						<?php if ( '' !== (string) ( $entry['note'] ?? '' ) ) : ?>
							<p class="dgl-timeline__note"><?php echo esc_html( (string) $entry['note'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</aside>
</div>
