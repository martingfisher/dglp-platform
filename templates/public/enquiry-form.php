<?php
/**
 * The enquiry form on a venue page.
 *
 * Expects `$data['post']` (the venue), `$data['spaces']` (from
 * SpacesQuery::spaces), `$data['enquiry']` (from Enquiry::state) and
 * `$data['reply']` (the venue's usual reply time, in words, or '').
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Joining\Guard;
use DGL\Spaces\Enquiry;

defined( 'ABSPATH' ) || exit;

$post    = $data['post'];
$state   = (array) ( $data['enquiry'] ?? [] );
$values  = (array) ( $state['values'] ?? [] );
$errors  = (array) ( $state['errors'] ?? [] );
$options = Enquiry::options( (int) $post->ID );
$reply   = (string) ( $data['reply'] ?? '' );
$action  = (string) get_permalink( $post );
$val     = static fn( string $key ): string => (string) ( $values[ $key ] ?? '' );
$today   = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
?>
<div class="dgl-pub__card dgl-enquiry">
	<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Send an enquiry', 'dgl-platform' ); ?></h2>

	<?php if ( ! empty( $state['sent'] ) ) : ?>
		<div class="dgl-enquiry__sent" role="status">
			<p><strong><?php esc_html_e( 'Sent to the venue.', 'dgl-platform' ); ?></strong></p>
			<p><?php echo esc_html( '' !== $reply ? sprintf( /* translators: %s: a reply time, e.g. "within two working days". */ __( 'They reply by email, %s.', 'dgl-platform' ), strtolower( $reply ) ) : __( 'They reply by email.', 'dgl-platform' ) ); ?></p>
		</div>
	<?php else : ?>
		<p class="dgl-pub__note">
			<?php esc_html_e( 'Goes straight to the venue. They reply to you by email.', 'dgl-platform' ); ?>
			<?php if ( '' !== $reply ) : ?>
				<?php echo esc_html( $reply . '.' ); ?>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $state['limited'] ) ) : ?>
			<div class="dgl-enquiry__error" role="alert">
				<p><?php esc_html_e( 'Enough enquiries have been sent from your address for now. Try again in an hour, or contact the venue directly.', 'dgl-platform' ); ?></p>
			</div>
		<?php elseif ( [] !== $errors ) : ?>
			<div class="dgl-enquiry__error" role="alert">
				<p><strong><?php esc_html_e( 'A few things need fixing before it can be sent.', 'dgl-platform' ); ?></strong></p>
			</div>
		<?php endif; ?>

		<form class="dgl-enquiry__form" method="post" action="<?php echo esc_url( $action . '#enquire' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( Enquiry::FLAG ); ?>" value="1">
			<input type="hidden" name="<?php echo esc_attr( Guard::STAMP ); ?>" value="<?php echo esc_attr( (string) ( $state['stamp'] ?? '' ) ); ?>">
			<?php /* Never shown, never announced; a robot that fills every field fills this one. */ ?>
			<div class="dgl-nohoney" aria-hidden="true">
				<label for="dgl-enquiry-<?php echo esc_attr( Guard::HONEYPOT ); ?>"><?php esc_html_e( 'Leave this empty', 'dgl-platform' ); ?></label>
				<input type="text" id="dgl-enquiry-<?php echo esc_attr( Guard::HONEYPOT ); ?>" name="<?php echo esc_attr( Guard::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off">
			</div>

			<?php
			$field = static function ( string $key, string $label, string $control, string $error = '', string $help = '' ): void {
				?>
				<div class="dgl-enquiry__field<?php echo '' !== $error ? ' dgl-enquiry__field--error' : ''; ?>">
					<label class="dgl-dir__label" for="dgl-enq-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* below. ?>
					<?php if ( '' !== $error ) : ?>
						<p class="dgl-enquiry__msg" id="dgl-enq-<?php echo esc_attr( $key ); ?>-error"><?php echo esc_html( $error ); ?></p>
					<?php elseif ( '' !== $help ) : ?>
						<p class="dgl-enquiry__help"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
				</div>
				<?php
			};

			$attrs = static function ( string $key ) use ( $errors ): string {
				return ' id="dgl-enq-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"' . ( isset( $errors[ $key ] ) ? ' aria-invalid="true" aria-describedby="dgl-enq-' . esc_attr( $key ) . '-error"' : '' );
			};

			$select = '<select class="dgl-dir__input"' . $attrs( 'space' ) . '>';
			foreach ( $options as $value => $label ) {
				$select .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $val( 'space' ), (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$select .= '</select>';

			$field( 'space', __( 'Which space?', 'dgl-platform' ), $select, $errors['space'] ?? '' );
			$field( 'date', __( 'Date', 'dgl-platform' ), '<input class="dgl-dir__input" type="date" min="' . esc_attr( $today ) . '" value="' . esc_attr( $val( 'date' ) ) . '"' . $attrs( 'date' ) . ' required>', $errors['date'] ?? '' );
			?>
			<div class="dgl-enquiry__row">
				<?php $field( 'time_from', __( 'From', 'dgl-platform' ), '<input class="dgl-dir__input" type="time" value="' . esc_attr( $val( 'time_from' ) ) . '"' . $attrs( 'time_from' ) . '>', $errors['time_from'] ?? '' ); ?>
				<?php $field( 'time_to', __( 'To', 'dgl-platform' ), '<input class="dgl-dir__input" type="time" value="' . esc_attr( $val( 'time_to' ) ) . '"' . $attrs( 'time_to' ) . '>', $errors['time_to'] ?? '' ); ?>
			</div>
			<?php
			$field( 'people', __( 'How many people?', 'dgl-platform' ), '<input class="dgl-dir__input" type="number" min="1" max="' . (int) \DGL\Spaces\SpacesQuery::MAX_PEOPLE . '" inputmode="numeric" value="' . esc_attr( $val( 'people' ) ) . '"' . $attrs( 'people' ) . '>', $errors['people'] ?? '' );
			$field( 'message', __( 'What is it for?', 'dgl-platform' ), '<textarea class="dgl-dir__input dgl-enquiry__text" rows="4" maxlength="' . (int) Enquiry::MESSAGE_MAX . '"' . $attrs( 'message' ) . ' required>' . esc_textarea( $val( 'message' ) ) . '</textarea>', $errors['message'] ?? '', __( 'The kind of event, anything you need, any questions.', 'dgl-platform' ) );
			$field( 'name', __( 'Your name', 'dgl-platform' ), '<input class="dgl-dir__input" type="text" autocomplete="name" maxlength="120" value="' . esc_attr( $val( 'name' ) ) . '"' . $attrs( 'name' ) . ' required>', $errors['name'] ?? '' );
			$field( 'email', __( 'Your email', 'dgl-platform' ), '<input class="dgl-dir__input" type="email" autocomplete="email" maxlength="200" value="' . esc_attr( $val( 'email' ) ) . '"' . $attrs( 'email' ) . ' required>', $errors['email'] ?? '', __( 'The venue replies here.', 'dgl-platform' ) );
			$field( 'phone', __( 'Phone, if you would rather be called', 'dgl-platform' ), '<input class="dgl-dir__input" type="tel" autocomplete="tel" maxlength="40" value="' . esc_attr( $val( 'phone' ) ) . '"' . $attrs( 'phone' ) . '>', $errors['phone'] ?? '' );
			?>
			<button class="dgl-pub__button dgl-enquiry__send" type="submit"><?php esc_html_e( 'Send enquiry', 'dgl-platform' ); ?></button>
			<p class="dgl-pub__note"><?php esc_html_e( 'Your details go to the venue and nowhere else. This is an enquiry, not a booking.', 'dgl-platform' ); ?></p>
		</form>
	<?php endif; ?>
</div>
