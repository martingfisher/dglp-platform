<?php
/**
 * The moderator's decision screen. Wireframe 1l.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;
use DGL\Moderation\Checks;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$post      = $data['post'];
$values    = $data['values'] ?? [];
$decidable = ! empty( $data['decidable'] );
$is_edit   = ! empty( $data['is_edit'] );
$parent    = $data['parent'] ?? null;

$check_class = static fn( string $status ): string => match ( $status ) {
	Checks::PASS => 'dgl-check-row--pass',
	Checks::WARN => 'dgl-check-row--warn',
	Checks::FAIL => 'dgl-check-row--fail',
	default      => 'dgl-check-row--unknown',
};

$check_word = static fn( string $status ): string => match ( $status ) {
	Checks::PASS => __( 'Pass', 'dgl-platform' ),
	Checks::WARN => __( 'Check', 'dgl-platform' ),
	Checks::FAIL => __( 'Fail', 'dgl-platform' ),
	default      => __( 'Not checked', 'dgl-platform' ),
};
?>
<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span>
			<?php
			echo $is_edit
				? esc_html(
					sprintf(
						/* translators: %s: content type name, for example "event". */
						__( 'Edit to %s', 'dgl-platform' ),
						strtolower( (string) $data['singular'] )
					)
				)
				: esc_html( (string) $data['singular'] );
			?>
		</p>
		<h1 class="dgl-page-head__title">
			<?php echo esc_html( $post->post_title !== '' ? $post->post_title : __( 'Untitled', 'dgl-platform' ) ); ?>
		</h1>
		<?php
		$submitted_on = View::date( get_post_meta( $post->ID, \DGL\Meta::ITEM_SUBMITTED_AT, true ), true );
		$who          = $data['submitter'] ? $data['submitter']->display_name : __( 'someone', 'dgl-platform' );
		$where        = $data['org'] ? get_the_title( $data['org'] ) : __( 'no organisation', 'dgl-platform' );
		?>
		<p class="dgl-page-head__lede">
			<?php
			if ( '' !== $submitted_on ) {
				printf(
					/* translators: 1: submitter name, 2: organisation, 3: date. */
					esc_html__( 'Submitted by %1$s at %2$s on %3$s.', 'dgl-platform' ),
					esc_html( $who ),
					esc_html( $where ),
					esc_html( $submitted_on )
				);
			} else {
				printf(
					/* translators: 1: submitter name, 2: organisation. */
					esc_html__( 'From %1$s at %2$s.', 'dgl-platform' ),
					esc_html( $who ),
					esc_html( $where )
				);
			}
			?>
		</p>
	</div>

	<?php if ( null !== ( $data['position'] ?? null ) ) : ?>
		<nav class="dgl-queue-nav" aria-label="<?php esc_attr_e( 'Queue', 'dgl-platform' ); ?>">
			<span class="dgl-queue-nav__count">
				<?php
				printf(
					/* translators: 1: position, 2: total. */
					esc_html__( '%1$d of %2$d', 'dgl-platform' ),
					(int) $data['position'],
					(int) $data['total']
				);
				?>
			</span>
			<?php if ( $data['prev'] ) : ?>
				<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'review', (string) $data['prev'] ) ); ?>"><?php esc_html_e( 'Previous', 'dgl-platform' ); ?></a>
			<?php endif; ?>
			<?php if ( $data['next'] ) : ?>
				<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'review', (string) $data['next'] ) ); ?>"><?php esc_html_e( 'Next', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</header>

<?php if ( $is_edit ) : ?>
	<div class="dgl-alert dgl-alert--edit" role="status">
		<p>
			<strong><?php esc_html_e( 'This is an edit to something already on the site.', 'dgl-platform' ); ?></strong>
			<?php esc_html_e( 'The published version is unchanged and still live. Approving this replaces it. Refusing it leaves the site exactly as it is.', 'dgl-platform' ); ?>
		</p>
		<?php if ( $parent instanceof WP_Post ) : ?>
			<p class="dgl-alert__actions">
				<a class="dgl-button dgl-button--small dgl-button--quiet" href="<?php echo esc_url( (string) get_permalink( $parent ) ); ?>" rel="noopener">
					<?php esc_html_e( 'See the published version', 'dgl-platform' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>

	<?php
	if ( empty( $data['changes'] ) ) {
		echo '<div class="dgl-alert dgl-alert--warn" role="status"><p>'
			. esc_html__( 'Nothing in this edit is different from the published version. Approving it changes nothing.', 'dgl-platform' )
			. '</p></div>';
	} else {
		View::output(
			'dashboard/changes',
			[
				'changes'       => $data['changes'],
				'changes_title' => __( 'What this edit would change', 'dgl-platform' ),
			]
		);
	}
	?>
<?php endif; ?>

<?php if ( '' !== ( $data['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( $data['error'] ); ?></p></div>
<?php endif; ?>

<div class="dgl-detail">
	<div>
		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'As it will appear', 'dgl-platform' ); ?></h2>

			<article class="dgl-preview">
				<?php
				$image_id = (int) ( $values['image'] ?? 0 );

				if ( $image_id > 0 ) {
					echo wp_get_attachment_image( $image_id, 'large', false, [ 'class' => 'dgl-preview__image' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
				<p class="dgl-preview__eyebrow"><?php echo esc_html( strtoupper( $data['singular'] ) ); ?></p>
				<h3 class="dgl-preview__title"><?php echo esc_html( (string) ( $values['title'] ?? '' ) ); ?></h3>
				<p class="dgl-preview__summary"><?php echo esc_html( (string) ( $values['summary'] ?? '' ) ); ?></p>

				<div class="dgl-preview__body">
					<?php echo wp_kses_post( wpautop( (string) ( $values['body'] ?? '' ) ) ); ?>
				</div>

				<?php if ( ! empty( $data['topics'] ) ) : ?>
					<p class="dgl-preview__topics">
						<?php foreach ( (array) $data['topics'] as $topic ) : ?>
							<span class="dgl-chip dgl-chip--draft"><?php echo esc_html( $topic ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</article>
		</section>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Every field as submitted', 'dgl-platform' ); ?></h2>
			<dl class="dgl-review__list">
				<?php foreach ( $data['fields'] as $field ) : ?>
					<?php if ( ! $field->applies( $values ) ) { continue; } ?>
					<dt><?php echo esc_html( $field->label ); ?></dt>
					<dd><?php echo View::field_value( $field, $values[ $field->key ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
				<?php endforeach; ?>
			</dl>
		</section>
	</div>

	<aside class="dgl-review-side">
		<section class="dgl-card dgl-notes" id="dgl-team-notes">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Team notes', 'dgl-platform' ); ?></h2>
			<p class="dgl-help"><?php esc_html_e( 'For the review team only. The organisation never sees these, and they stay with the listing through every edit.', 'dgl-platform' ); ?></p>
			<?php if ( '1' === ( $data['noted'] ?? null ) ) : ?>
				<div class="dgl-alert dgl-alert--good" role="status"><p><?php esc_html_e( 'Note added.', 'dgl-platform' ); ?></p></div>
			<?php elseif ( '0' === ( $data['noted'] ?? null ) ) : ?>
				<div class="dgl-alert dgl-alert--good" role="status"><p><?php esc_html_e( 'Note removed.', 'dgl-platform' ); ?></p></div>
			<?php endif; ?>
			<?php $team_notes = (array) ( $data['notes'] ?? [] ); ?>
			<?php if ( [] !== $team_notes ) : ?>
				<ul class="dgl-notes__list">
					<?php foreach ( $team_notes as $team_note ) : ?>
						<li class="dgl-notes__note">
							<p class="dgl-notes__text"><?php echo esc_html( (string) $team_note['text'] ); ?></p>
							<p class="dgl-notes__meta">
								<?php echo esc_html( (string) $team_note['by_name'] ); ?>, <?php echo esc_html( View::date( (string) $team_note['at'], true ) ); ?>
								<form method="post" class="dgl-inline-form" action="<?php echo esc_url( Router::url( 'review', (string) $post->ID ) ); ?>">
									<?php wp_nonce_field( Wizard::NONCE ); ?>
									<input type="hidden" name="dgl_note_id" value="<?php echo esc_attr( (string) $team_note['id'] ); ?>">
									<button class="dgl-linkish" type="submit" name="dgl_intent" value="note_remove"><?php esc_html_e( 'Remove', 'dgl-platform' ); ?></button>
								</form>
							</p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" class="dgl-form dgl-form--bare dgl-notes__add" action="<?php echo esc_url( Router::url( 'review', (string) $post->ID ) ); ?>">
				<?php wp_nonce_field( Wizard::NONCE ); ?>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl-note-text"><?php esc_html_e( 'Add a note', 'dgl-platform' ); ?></label>
					<textarea class="dgl-field dgl-field--area" id="dgl-note-text" name="dgl_note_text" rows="2" maxlength="<?php echo esc_attr( (string) \DGL\Moderation\Notes::MAX_LENGTH ); ?>"></textarea>
				</div>
				<div class="dgl-form__actions dgl-form__actions--alone">
					<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="note_add"><?php esc_html_e( 'Add note', 'dgl-platform' ); ?></button>
				</div>
			</form>
		</section>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Automatic checks', 'dgl-platform' ); ?></h2>
			<p class="dgl-help"><?php esc_html_e( 'Advice, not a gate. You can publish something with every one of these complaining.', 'dgl-platform' ); ?></p>

			<ul class="dgl-checks-list">
				<?php foreach ( $data['checks'] as $check ) : ?>
					<li class="dgl-check-row <?php echo esc_attr( $check_class( $check['status'] ) ); ?>">
						<span class="dgl-check-row__label"><?php echo esc_html( $check['label'] ); ?></span>
						<span class="dgl-check-row__verdict"><?php echo esc_html( $check_word( $check['status'] ) ); ?></span>
						<span class="dgl-check-row__detail"><?php echo esc_html( $check['detail'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="post">
				<?php wp_nonce_field( Wizard::NONCE ); ?>
				<button class="dgl-linkish" type="submit" name="dgl_intent" value="check_links">
					<?php esc_html_e( 'Check the external links now', 'dgl-platform' ); ?>
				</button>
			</form>
		</section>

		<?php if ( ! empty( $data['can_pin'] ) ) : ?>
			<section class="dgl-card dgl-decision">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Feature it', 'dgl-platform' ); ?></h2>
				<?php if ( '1' === ( $data['featured'] ?? null ) ) : ?>
					<div class="dgl-alert dgl-alert--good" role="status"><p><?php esc_html_e( 'Featured. It sits first on its list and the organisation has been told.', 'dgl-platform' ); ?></p></div>
				<?php elseif ( '0' === ( $data['featured'] ?? null ) ) : ?>
					<div class="dgl-alert dgl-alert--good" role="status"><p><?php esc_html_e( 'No longer featured.', 'dgl-platform' ); ?></p></div>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $data['pinned_until'] ?? '' ) ) : ?>
					<p class="dgl-help">
						<?php
						printf(
							/* translators: %s: a date. */
							esc_html__( 'Featured at the top of its list until %s, then it drops back on its own.', 'dgl-platform' ),
							'<strong>' . esc_html( (string) $data['pinned_until'] ) . '</strong>'
						);
						?>
					</p>
					<form method="post" class="dgl-form dgl-form--bare">
						<?php wp_nonce_field( Wizard::NONCE ); ?>
						<div class="dgl-decision__actions">
							<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="unpin"><?php esc_html_e( 'Stop featuring it', 'dgl-platform' ); ?></button>
						</div>
					</form>
				<?php else : ?>
					<p class="dgl-help"><?php esc_html_e( 'Holds it at the top of its public list for a week or a fortnight, with a Featured stamp, then it drops back on its own. The organisation is told.', 'dgl-platform' ); ?></p>
					<form method="post" class="dgl-form dgl-form--bare">
						<?php wp_nonce_field( Wizard::NONCE ); ?>
						<div class="dgl-decision__actions">
							<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="pin" onclick="this.form.dgl_pin_days.value=7"><?php esc_html_e( 'Feature for 7 days', 'dgl-platform' ); ?></button>
							<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="pin" onclick="this.form.dgl_pin_days.value=14"><?php esc_html_e( 'Feature for 14 days', 'dgl-platform' ); ?></button>
							<input type="hidden" name="dgl_pin_days" value="7">
						</div>
					</form>
				<?php endif; ?>
			</section>
		<?php elseif ( empty( $data['is_edit'] ) && ! empty( $data['decidable'] ) ) : ?>
			<section class="dgl-card">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Feature it', 'dgl-platform' ); ?></h2>
				<p class="dgl-help"><?php esc_html_e( 'Once this is live, this card offers "Feature for 7 days" or "14 days": it then sits first on its public list with a Featured stamp. Approve it, then open it again from Approved.', 'dgl-platform' ); ?></p>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $data['can_take_down'] ) ) : ?>
			<section class="dgl-card dgl-decision">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Take it off the site', 'dgl-platform' ); ?></h2>
				<p class="dgl-help"><?php esc_html_e( 'This is live. Taking it down puts it back in the queue: it comes off the site now and gets decided again. The member is told, with your reason.', 'dgl-platform' ); ?></p>

				<form method="post" class="dgl-form dgl-form--bare">
					<?php wp_nonce_field( Wizard::NONCE ); ?>

					<div class="dgl-field-row">
						<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Why', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
						<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="4" required></textarea>
					</div>

					<div class="dgl-decision__actions">
						<button class="dgl-button dgl-button--danger" type="submit" name="dgl_intent" value="take_down"
							data-dgl-confirm="<?php esc_attr_e( 'Take this off the site now?', 'dgl-platform' ); ?>">
							<?php esc_html_e( 'Take it down', 'dgl-platform' ); ?>
						</button>
					</div>
				</form>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $data['can_reopen'] ) ) : ?>
			<section class="dgl-card dgl-decision">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Look at it again', 'dgl-platform' ); ?></h2>
				<p class="dgl-help"><?php esc_html_e( 'This was refused. Reopening puts it back in the queue to be decided again, and the member is told. Nothing goes on the site until somebody approves it.', 'dgl-platform' ); ?></p>

				<form method="post" class="dgl-form dgl-form--bare">
					<?php wp_nonce_field( Wizard::NONCE ); ?>

					<div class="dgl-field-row">
						<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Note to the member', 'dgl-platform' ); ?></label>
						<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="3"></textarea>
						<p class="dgl-help"><?php esc_html_e( 'Optional. For example, that the refusal was a mistake.', 'dgl-platform' ); ?></p>
					</div>

					<div class="dgl-decision__actions">
						<button class="dgl-button" type="submit" name="dgl_intent" value="reopen">
							<?php esc_html_e( 'Reopen for review', 'dgl-platform' ); ?>
						</button>
					</div>
				</form>
			</section>
		<?php endif; ?>

		<?php if ( ! empty( $data['can_restore'] ) ) : ?>
			<section class="dgl-card dgl-decision">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Restore it', 'dgl-platform' ); ?></h2>
				<p class="dgl-help"><?php esc_html_e( 'This is archived. Restoring puts it back in the queue to be decided again; it does not go straight on the site.', 'dgl-platform' ); ?></p>

				<form method="post" class="dgl-form dgl-form--bare">
					<?php wp_nonce_field( Wizard::NONCE ); ?>
					<div class="dgl-decision__actions">
						<button class="dgl-button" type="submit" name="dgl_intent" value="restore"
							data-dgl-confirm="<?php esc_attr_e( 'Restore this and send it back through review?', 'dgl-platform' ); ?>">
							<?php esc_html_e( 'Restore and review again', 'dgl-platform' ); ?>
						</button>
					</div>
				</form>
			</section>
		<?php endif; ?>

		<?php if ( $decidable ) : ?>
			<section class="dgl-card dgl-decision">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Decision', 'dgl-platform' ); ?></h2>

				<form method="post" class="dgl-form dgl-form--bare">
					<?php wp_nonce_field( Wizard::NONCE ); ?>

					<div class="dgl-field-row">
						<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Note to the member', 'dgl-platform' ); ?></label>
						<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="5"></textarea>
						<p class="dgl-help"><?php esc_html_e( 'Required if you are asking for a change or refusing. They see this on their dashboard and by email, so write it to them rather than about them.', 'dgl-platform' ); ?></p>
					</div>

					<div class="dgl-decision__actions">
						<button class="dgl-button" type="submit" name="dgl_intent" value="approve">
							<?php
							// An edit is not published, it is applied. The item
							// was already on the site and never came off it.
							echo $is_edit
								? esc_html__( 'Approve this edit', 'dgl-platform' )
								: esc_html__( 'Approve and publish', 'dgl-platform' );
							?>
						</button>
						<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="changes">
							<?php esc_html_e( 'Ask for a change', 'dgl-platform' ); ?>
						</button>
						<button class="dgl-button dgl-button--danger" type="submit" name="dgl_intent" value="reject">
							<?php esc_html_e( 'Refuse', 'dgl-platform' ); ?>
						</button>
					</div>
				</form>
			</section>
		<?php else : ?>
			<section class="dgl-card">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Decision', 'dgl-platform' ); ?></h2>
				<p class="dgl-help">
					<?php
					echo Statuses::PENDING === $post->post_status
						? esc_html__( 'You submitted this, so somebody else has to decide on it.', 'dgl-platform' )
						: sprintf(
							/* translators: %s: status label. */
							esc_html__( 'Already decided: %s. Anything you can change about that is offered above.', 'dgl-platform' ),
							esc_html( Statuses::label( (string) $post->post_status ) )
						);
					?>
				</p>
			</section>
		<?php endif; ?>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Who sent it', 'dgl-platform' ); ?></h2>
			<dl class="dgl-review__list">
				<dt><?php esc_html_e( 'Organisation', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( $data['org'] ? get_the_title( $data['org'] ) : __( 'None', 'dgl-platform' ) ); ?></dd>
				<dt><?php esc_html_e( 'Trust', 'dgl-platform' ); ?></dt>
				<dd>
					<?php echo esc_html( (string) $data['org_trust'] ); ?>
					<?php if ( 'type' === (string) ( $data['trust_why'] ?? '' ) ) : ?>
						<span class="dgl-help"><?php esc_html_e( 'Went live without review: this type is trusted.', 'dgl-platform' ); ?></span>
					<?php elseif ( 'edits' === (string) ( $data['trust_why'] ?? '' ) ) : ?>
						<span class="dgl-help"><?php esc_html_e( 'Went live without review: edits are trusted.', 'dgl-platform' ); ?></span>
					<?php endif; ?>
					<?php if ( $data['org'] ) : ?>
						<span class="dgl-help"><a href="<?php echo esc_url( (string) $data['trust_url'] ); ?>"><?php esc_html_e( 'Change', 'dgl-platform' ); ?></a></span>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Live on site', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( (string) $data['approved'] ); ?></dd>
				<dt><?php esc_html_e( 'Previously refused', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( (string) $data['rejected'] ); ?></dd>
			</dl>

			<?php if ( ! empty( $data['can_reassign'] ) && ! empty( $data['orgs'] ) ) : ?>
				<?php if ( ! empty( $data['moved'] ) ) : ?>
					<div class="dgl-alert dgl-alert--good" role="status"><p><?php esc_html_e( 'Moved. It now sits under that organisation, on the site and in their dashboard.', 'dgl-platform' ); ?></p></div>
				<?php endif; ?>
				<form method="post" class="dgl-form dgl-form--bare" id="dgl-reassign">
					<?php wp_nonce_field( Wizard::NONCE ); ?>
					<div class="dgl-field-row">
						<label class="dgl-label" for="dgl-org"><?php esc_html_e( 'Move it to another organisation', 'dgl-platform' ); ?></label>
						<select class="dgl-field" id="dgl-org" name="dgl_org">
							<?php foreach ( $data['orgs'] as $org_id => $org_name ) : ?>
								<option value="<?php echo (int) $org_id; ?>"<?php selected( $data['org'] && (int) $data['org']->ID === (int) $org_id ); ?>><?php echo esc_html( $org_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="dgl-help"><?php esc_html_e( 'For a story filed under the wrong name. The item keeps its status; the audit trail records the move.', 'dgl-platform' ); ?></p>
					</div>
					<div class="dgl-decision__actions">
						<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="reassign"><?php esc_html_e( 'Move it', 'dgl-platform' ); ?></button>
					</div>
				</form>
			<?php endif; ?>
		</section>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'History', 'dgl-platform' ); ?></h2>
			<?php if ( empty( $data['history'] ) ) : ?>
				<p class="dgl-help"><?php esc_html_e( 'Nothing recorded yet.', 'dgl-platform' ); ?></p>
			<?php else : ?>
				<ol class="dgl-timeline">
					<?php foreach ( array_reverse( $data['history'] ) as $entry ) : ?>
						<li class="dgl-timeline__item">
							<p class="dgl-timeline__action"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $entry['action'] ) ) ); ?></p>
							<p class="dgl-timeline__when"><?php echo esc_html( View::date( $entry['logged_at'], true ) ); ?></p>
							<?php if ( '' !== (string) ( $entry['note'] ?? '' ) ) : ?>
								<p class="dgl-timeline__note"><?php echo esc_html( (string) $entry['note'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	</aside>
</div>
