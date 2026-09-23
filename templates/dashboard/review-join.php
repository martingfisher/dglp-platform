<?php
/**
 * A joining request, with a decision: an organisation somebody registered,
 * or one they picked from the list without a domain match. Either can be
 * approved, refused, or attached to an organisation already on the list.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\Wizard;
use DGL\Joining\Signup;
use DGL\Meta;

defined( 'ABSPATH' ) || exit;

$signup   = $data['signup'];
$waiting  = $signup instanceof Signup && Signup::AWAITING === $signup->state;
$is_claim = Signup::KIND_CLAIM === (string) ( $data['kind'] ?? '' );
$members  = (array) ( $data['members'] ?? [] );
$likely   = (array) ( $data['likely'] ?? [] );
$shown    = (array) ( $data['shown'] ?? [] );
$options  = (array) ( $data['attach_options'] ?? [] );
$org_name = (string) ( $data['org'] ?? '' );

$status_label = static fn( string $status ): string => match ( $status ) {
	Meta::ORG_APPROVED  => __( 'Verified', 'dgl-platform' ),
	Meta::ORG_SUSPENDED => __( 'Suspended', 'dgl-platform' ),
	default             => __( 'Awaiting verification', 'dgl-platform' ),
};

$closeness = match ( (string) ( $data['closeness'] ?? 'none' ) ) {
	'same'      => __( 'Their address is on the organisation\'s email domain.', 'dgl-platform' ),
	'subdomain' => sprintf(
		/* translators: %s: email domain. */
		__( 'Their address is on a subdomain of the organisation\'s domain (%s). Probably genuine. If so, consider adding that domain to the organisation in wp-admin so colleagues can join by domain.', 'dgl-platform' ),
		(string) ( $data['email_domain'] ?? '' )
	),
	'website'   => sprintf(
		/* translators: %s: email domain. */
		__( 'Their email domain (%s) matches the organisation\'s website, though it is not recorded as an email domain. Probably genuine.', 'dgl-platform' ),
		(string) ( $data['email_domain'] ?? '' )
	),
	'public'    => sprintf(
		/* translators: %s: email domain. */
		__( 'A public email address (%s). Nothing to go on but what they said, so check with the organisation if in doubt.', 'dgl-platform' ),
		(string) ( $data['email_domain'] ?? '' )
	),
	default     => sprintf(
		/* translators: %s: email domain. */
		__( 'Their email domain (%s) is not close to anything recorded for the organisation.', 'dgl-platform' ),
		(string) ( $data['email_domain'] ?? '' )
	),
};
?>
<?php if ( '' !== (string) ( $data['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs"><a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a></p>
		<h1 class="dgl-page-head__title"><?php echo esc_html( $org_name ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php
			if ( ! $waiting ) {
				printf(
					/* translators: %s: state. */
					esc_html__( 'This request is %s. Nothing is waiting.', 'dgl-platform' ),
					esc_html( $signup instanceof Signup ? $signup->state : '' )
				);
			} elseif ( $is_claim ) {
				printf(
					/* translators: 1: person, 2: email, 3: date. */
					esc_html__( '%1$s (%2$s) says they are part of this organisation. Their email address is not on its domain, so it is yours to check. Waiting since %3$s. They can draft but not submit until you decide.', 'dgl-platform' ),
					esc_html( (string) $data['who'] ),
					esc_html( $signup->email ),
					esc_html( (string) $data['since'] )
				);
			} else {
				printf(
					/* translators: 1: person, 2: email, 3: date. */
					esc_html__( 'Registered by %1$s (%2$s) as an organisation that is not on the list. Waiting since %3$s. They can draft but not submit until you decide.', 'dgl-platform' ),
					esc_html( (string) $data['who'] ),
					esc_html( $signup->email ),
					esc_html( (string) $data['since'] )
				);
			}
			?>
		</p>
	</div>
</header>

<?php if ( $is_claim ) : ?>
	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'What they told us', 'dgl-platform' ); ?></h2>
		<dl class="dgl-review__list">
			<dt><?php esc_html_e( 'How they are connected', 'dgl-platform' ); ?></dt>
			<dd><?php echo '' === (string) $data['note'] ? esc_html__( 'They did not say.', 'dgl-platform' ) : esc_html( (string) $data['note'] ); ?></dd>
			<dt><?php esc_html_e( 'Their email address', 'dgl-platform' ); ?></dt>
			<dd><?php echo esc_html( $closeness ); ?></dd>
		</dl>
	</section>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'The organisation', 'dgl-platform' ); ?></h2>
		<dl class="dgl-review__list">
			<dt><?php esc_html_e( 'Status', 'dgl-platform' ); ?></dt>
			<dd><?php echo esc_html( $status_label( (string) $data['org_status'] ) ); ?></dd>
			<dt><?php esc_html_e( 'Email domains recorded', 'dgl-platform' ); ?></dt>
			<dd><?php echo [] === $data['domains'] ? esc_html__( 'None', 'dgl-platform' ) : esc_html( implode( ', ', (array) $data['domains'] ) ); ?></dd>
		</dl>
		<?php if ( [] === $members ) : ?>
			<p class="dgl-help"><?php esc_html_e( 'Nobody is in it yet. If you approve, this person becomes its owner: they run its page and can invite colleagues.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<p class="dgl-help"><?php esc_html_e( 'People already in it. If you approve, this person joins as a contributor; an owner can promote them. The owners have been emailed about this request.', 'dgl-platform' ); ?></p>
			<table class="dgl-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'dgl-platform' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role', 'dgl-platform' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Account', 'dgl-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $members as $member ) : ?>
						<tr>
							<td><?php echo esc_html( $member['name'] ); ?> <span class="dgl-help"><a href="<?php echo esc_url( 'mailto:' . $member['email'] ); ?>"><?php echo esc_html( $member['email'] ); ?></a></span></td>
							<td><?php echo esc_html( $member['role'] ); ?></td>
							<td><?php echo esc_html( $member['account'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'What they told us', 'dgl-platform' ); ?></h2>
		<dl class="dgl-review__list">
			<?php foreach ( (array) $data['details'] as $label => $value ) : ?>
				<dt><?php echo esc_html( (string) $label ); ?></dt>
				<dd><?php echo esc_html( (string) $value ); ?></dd>
			<?php endforeach; ?>
			<dt><?php esc_html_e( 'Email domain recorded', 'dgl-platform' ); ?></dt>
			<dd><?php echo [] === $data['domains'] ? esc_html__( 'None: a public email provider, so colleagues will need to pick the organisation from the list or be invited', 'dgl-platform' ) : esc_html( implode( ', ', (array) $data['domains'] ) ); ?></dd>
			<?php if ( [] !== $shown ) : ?>
				<dt><?php esc_html_e( 'Shown before registering', 'dgl-platform' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: organisations. */
						esc_html__( 'They were shown %s and said none of them is theirs.', 'dgl-platform' ),
						esc_html( implode( ', ', $shown ) )
					);
					?>
				</dd>
			<?php endif; ?>
		</dl>
	</section>

	<?php if ( $waiting ) : ?>
		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Likely matches on the list', 'dgl-platform' ); ?></h2>
			<?php if ( [] === $likely ) : ?>
				<p class="dgl-help"><?php esc_html_e( 'Nothing on the list looks like this organisation: no similar name, and no shared website, email domain, number or postcode.', 'dgl-platform' ); ?></p>
			<?php else : ?>
				<p class="dgl-help"><?php esc_html_e( 'Organisations already on the list that this could be. If it is one of them, attach the person to it below instead of verifying a second record.', 'dgl-platform' ); ?></p>
				<ul class="dgl-matches__list">
					<?php foreach ( $likely as $match ) : ?>
						<li class="dgl-match dgl-match--<?php echo esc_attr( $match['trashed'] ? 'trashed' : $match['strength'] ); ?>">
							<div class="dgl-match__who">
								<strong><a href="<?php echo esc_url( $match['edit_url'] ); ?>"><?php echo esc_html( $match['name'] ); ?></a></strong>
								<span class="dgl-match__status"><?php echo $match['trashed'] ? esc_html__( 'previously refused, in the bin', 'dgl-platform' ) : esc_html( $status_label( $match['status'] ) ); ?></span>
								<span class="dgl-match__why"><?php echo esc_html( $match['why'] ); ?></span>
							</div>
							<?php if ( $match['trashed'] ) : ?>
								<span class="dgl-help"><?php esc_html_e( 'Restore it from wp-admin if it was refused by mistake, then attach this person to it.', 'dgl-platform' ); ?></span>
							<?php else : ?>
								<button class="dgl-button dgl-button--secondary dgl-match__take" type="button" data-dgl-attach="<?php echo esc_attr( (string) $match['id'] ); ?>"><?php esc_html_e( 'It is this one', 'dgl-platform' ); ?></button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $waiting ) : ?>
	<section class="dgl-card dgl-decision">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Decision', 'dgl-platform' ); ?></h2>
		<p class="dgl-help">
			<?php
			if ( $is_claim ) {
				echo [] === $members
					? esc_html__( 'Approving adds them to the organisation as its owner, since nobody else is in it. Refusing closes the account and tells them why; the organisation is untouched.', 'dgl-platform' )
					: esc_html__( 'Approving adds them to the organisation as a contributor. Refusing closes the account and tells them why; the organisation is untouched.', 'dgl-platform' );
			} else {
				esc_html_e( 'Verifying makes the organisation a member and lets this person submit and invite colleagues. Refusing removes the organisation and closes the account; they are told why.', 'dgl-platform' );
			}
			?>
		</p>

		<form method="post" class="dgl-form dgl-form--bare">
			<?php wp_nonce_field( Wizard::NONCE ); ?>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl-note"><?php esc_html_e( 'Note to the person', 'dgl-platform' ); ?></label>
				<textarea class="dgl-field dgl-field--area" id="dgl-note" name="dgl_note" rows="4"></textarea>
				<p class="dgl-help"><?php esc_html_e( 'Required if you are refusing. They read it by email.', 'dgl-platform' ); ?></p>
			</div>
			<div class="dgl-decision__actions">
				<button class="dgl-button" type="submit" name="dgl_intent" value="approve">
					<?php
					if ( $is_claim ) {
						/* translators: %s: organisation. */
						printf( esc_html__( 'Add them to %s', 'dgl-platform' ), esc_html( $org_name ) );
					} else {
						esc_html_e( 'Verify the organisation', 'dgl-platform' );
					}
					?>
				</button>
				<button class="dgl-button dgl-button--danger" type="submit" name="dgl_intent" value="refuse"
					data-dgl-confirm="<?php echo $is_claim ? esc_attr__( 'Refuse this request? The account is closed. The organisation is untouched.', 'dgl-platform' ) : esc_attr__( 'Refuse this registration? The organisation is removed and the account closed.', 'dgl-platform' ); ?>"><?php esc_html_e( 'Refuse', 'dgl-platform' ); ?></button>
			</div>
		</form>
	</section>

	<section class="dgl-card dgl-attach" id="dgl-attach">
		<h2 class="dgl-section__title"><?php echo $is_claim ? esc_html__( 'They belong to a different organisation on the list', 'dgl-platform' ) : esc_html__( 'This is actually an organisation already on the list', 'dgl-platform' ); ?></h2>
		<form method="post" class="dgl-form dgl-form--bare">
			<?php wp_nonce_field( Wizard::NONCE ); ?>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl-attach-org"><?php esc_html_e( 'Attach this person to', 'dgl-platform' ); ?></label>
				<div class="dgl-picker" data-dgl-picker>
					<select class="dgl-field" id="dgl-attach-org" name="dgl_attach_org">
						<option value=""><?php esc_html_e( 'Choose from the list', 'dgl-platform' ); ?></option>
						<?php foreach ( $options as $option_id => $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option_id ); ?>" <?php echo Meta::ORG_PENDING === $option['status'] ? 'data-pending="1"' : ''; ?>>
								<?php
								echo esc_html( (string) $option['name'] );

								if ( Meta::ORG_PENDING === $option['status'] ) {
									echo ' ' . esc_html__( '(awaiting verification)', 'dgl-platform' );
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<p class="dgl-help">
					<?php
					echo $is_claim
						? esc_html__( 'They join it with the same rules as a domain match: owner if nobody is in it, contributor otherwise. The organisation they picked is untouched.', 'dgl-platform' )
						: esc_html__( 'They join it with the same rules as a domain match: owner if nobody is in it, contributor otherwise. The registration they typed is removed for good, and its details fill any gaps in the existing record; nothing already there is overwritten.', 'dgl-platform' );
					?>
				</p>
			</div>
			<?php if ( ! empty( $data['can_add_domain'] ) ) : ?>
				<label class="dgl-check">
					<input type="checkbox" name="dgl_add_domain" value="1" checked>
					<span>
						<?php
						printf(
							/* translators: %s: email domain. */
							esc_html__( 'Record their email domain (%s) on that organisation, so colleagues can join by domain', 'dgl-platform' ),
							esc_html( (string) ( $data['email_domain'] ?? '' ) )
						);
						?>
					</span>
				</label>
			<?php endif; ?>
			<div class="dgl-decision__actions">
				<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="attach"
					data-dgl-confirm="<?php echo $is_claim ? esc_attr__( 'Attach them to that organisation instead?', 'dgl-platform' ) : esc_attr__( 'Attach them to that organisation and remove the registration they typed?', 'dgl-platform' ); ?>"><?php echo $is_claim ? esc_html__( 'Attach to that organisation', 'dgl-platform' ) : esc_html__( 'Attach and remove the duplicate', 'dgl-platform' ); ?></button>
			</div>
		</form>
	</section>
<?php endif; ?>
