<?php
/**
 * A venue's public page: photos, what it is and where, its spaces, how
 * to get in, and how to ask about hiring it.
 *
 * Expects `$data['post']` (the venue) and, optionally, `$data['enquiry']`
 * (the enquiry form's state from Spaces\Enquiry; absent until stage 3).
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\View;
use DGL\Frontend\Frontend;
use DGL\Org\Directory;
use DGL\Org\DirectoryQuery;
use DGL\Schema\Types\Venue;
use DGL\Spaces\SpacesQuery;

defined( 'ABSPATH' ) || exit;

$post = $data['post'] ?? null;

if ( ! $post instanceof WP_Post ) {
	return;
}

$id       = (int) $post->ID;
$org      = Frontend::organisation( $post );
$org_url  = $org['id'] > 0 && Directory::is_listed( $org['id'] ) ? DirectoryQuery::url( $org['id'] ) : '';
$summary  = (string) Frontend::value( $post, 'summary' );
$body     = (string) Frontend::value( $post, 'body' );
$has_body = '' !== trim( wp_strip_all_tags( $body ) );
$archive  = Frontend::archive_url( \DGL\PostTypes::VENUE );
$value    = static fn( string $key ): string => trim( (string) Frontend::value( $post, $key ) );
$type     = Venue::labelled( Venue::TYPES )[ $value( 'venue_type' ) ] ?? '';
$ward     = Venue::wards()[ $value( 'ward' ) ] ?? '';
$access   = array_intersect_key( Venue::labelled( Venue::ACCESS ), array_flip( (array) Frontend::value( $post, 'access' ) ) );
$fac      = array_intersect_key( Venue::labelled( Venue::FACILITIES ), array_flip( (array) Frontend::value( $post, 'facilities' ) ) );
$reply    = Venue::labelled( Venue::REPLY )[ $value( 'reply_time' ) ] ?? '';
$spaces   = SpacesQuery::spaces( $id );
$facts    = SpacesQuery::quick_facts( array_column( $spaces, 'meta' ) );
$enquiry  = (array) ( $data['enquiry'] ?? [] );
$has_form = ! empty( $enquiry['show'] );
$enquire  = $has_form ? (string) get_permalink( $post ) : '';

// Every photo: the venue's own, then one per space that has one.
$photos = [];

foreach ( [ 'image', 'image_2', 'image_3', 'image_4' ] as $key ) {
	$photo_id = (int) Frontend::value( $post, $key );

	if ( $photo_id > 0 ) {
		$photos[] = $photo_id;
	}
}

foreach ( $spaces as $space ) {
	$photo_id = (int) ( $space['meta']['image'] ?? 0 );

	if ( $photo_id > 0 && ! in_array( $photo_id, $photos, true ) ) {
		$photos[] = $photo_id;
	}
}

$about = array_filter(
	[
		__( 'Access', 'dgl-platform' )            => implode( ', ', $access ),
		__( 'Facilities', 'dgl-platform' )        => implode( ', ', $fac ),
		__( 'Getting there', 'dgl-platform' )     => $value( 'getting_there' ),
		__( 'Usually available', 'dgl-platform' ) => $value( 'availability' ),
		__( 'Good to know', 'dgl-platform' )      => $value( 'good_to_know' ),
	]
);

$address = array_filter( [ $value( 'address' ), $value( 'postcode' ) ] );
$phone   = $value( 'contact_phone' );
?>
<div class="dgl-pub dgl-venue">
	<article class="dgl-pub__item">
		<p class="dgl-pub__kicker">
			<?php echo esc_html( implode( ' / ', array_filter( [ $type, $ward ] ) ) ); ?>
		</p>

		<h1 class="dgl-pub__title"><?php echo esc_html( get_the_title( $post ) ); ?></h1>

		<?php if ( '' !== $org['name'] ) : ?>
			<p class="dgl-venue__runby">
				<?php
				$org_html = '' !== $org_url
					? '<a href="' . esc_url( $org_url ) . '">' . esc_html( $org['name'] ) . '</a>'
					: esc_html( $org['name'] );
				printf(
					/* translators: %s: the organisation. */
					esc_html__( 'Run by %s, a partnership member', 'dgl-platform' ),
					$org_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( [] !== $photos ) : ?>
			<div class="dgl-venue__gallery dgl-venue__gallery--<?php echo (int) min( 3, count( $photos ) ); ?>">
				<?php foreach ( array_slice( $photos, 0, 3 ) as $i => $photo_id ) : ?>
					<div class="dgl-venue__photo">
						<?php echo wp_get_attachment_image( $photo_id, 0 === $i ? 'large' : 'medium_large', false, [ 'loading' => 0 === $i ? 'eager' : 'lazy' ] ); ?>
					</div>
				<?php endforeach; ?>
				<?php if ( count( $photos ) > 3 ) : ?>
					<p class="dgl-venue__morephotos">
						<?php
						/* translators: %s: a number. */
						echo esc_html( sprintf( __( '%s photos', 'dgl-platform' ), number_format_i18n( count( $photos ) ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $facts['count'] > 0 ) : ?>
			<ul class="dgl-venue__quick" aria-label="<?php esc_attr_e( 'At a glance', 'dgl-platform' ); ?>">
				<li>
					<?php
					/* translators: %s: a number. */
					echo wp_kses_post( sprintf( _n( '<b>%s</b> space', '<b>%s</b> spaces', $facts['count'], 'dgl-platform' ), number_format_i18n( $facts['count'] ) ) );
					?>
				</li>
				<?php if ( $facts['max_people'] > 0 ) : ?>
					<li>
						<?php
						/* translators: %s: a number. */
						echo wp_kses_post( sprintf( __( 'Up to <b>%s</b> people', 'dgl-platform' ), number_format_i18n( $facts['max_people'] ) ) );
						?>
					</li>
				<?php endif; ?>
				<?php if ( null !== $facts['from'] ) : ?>
					<li>
						<?php
						$from_unit = Venue::labelled( \DGL\Schema\Types\Space::UNITS )[ $facts['from_unit'] ] ?? '';
						if ( $facts['from'] <= 0 ) {
							echo wp_kses_post( __( '<b>Free</b> to hire', 'dgl-platform' ) );
						} else {
							/* translators: 1: an amount like £16, 2: "an hour", "a session" or "a day". */
							echo wp_kses_post( sprintf( __( 'From <b>%1$s</b> %2$s', 'dgl-platform' ), SpacesQuery::money( (float) $facts['from'] ), $from_unit ) );
						}
						?>
					</li>
				<?php elseif ( $facts['on_request'] ) : ?>
					<li><?php esc_html_e( 'Price on request', 'dgl-platform' ); ?></li>
				<?php endif; ?>
				<?php if ( isset( $access['step_free'] ) ) : ?>
					<li><?php esc_html_e( 'Step-free entrance', 'dgl-platform' ); ?></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>

		<?php if ( '' !== trim( $summary ) ) : ?>
			<p class="dgl-pub__standfirst"><?php echo esc_html( $summary ); ?></p>
		<?php endif; ?>

		<div class="dgl-pub__layout dgl-venue__layout">
			<div class="dgl-venue__main">
				<?php if ( $has_body ) : ?>
					<div class="dgl-pub__body"><?php echo wp_kses_post( wpautop( $body ) ); ?></div>
				<?php endif; ?>

				<section class="dgl-venue__spaces" aria-labelledby="dgl-venue-spaces">
					<h2 class="dgl-org__h2" id="dgl-venue-spaces"><?php esc_html_e( 'Spaces at this venue', 'dgl-platform' ); ?></h2>
					<?php if ( [] === $spaces ) : ?>
						<p class="dgl-dir__count"><?php esc_html_e( 'No spaces are listed yet. Ask the venue what they can offer.', 'dgl-platform' ); ?></p>
					<?php else : ?>
						<div class="dgl-venue__cards">
							<?php foreach ( $spaces as $space ) : ?>
								<?php View::output( 'public/space-card', [ 'space' => $space['post'], 'meta' => $space['meta'], 'enquire_url' => $enquire ] ); ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<?php if ( [] !== $about ) : ?>
					<section class="dgl-org__section" aria-labelledby="dgl-venue-about">
						<h2 class="dgl-org__h2" id="dgl-venue-about"><?php esc_html_e( 'About the venue', 'dgl-platform' ); ?></h2>
						<dl class="dgl-org__list dgl-venue__about">
							<?php foreach ( $about as $label => $text ) : ?>
								<dt><?php echo esc_html( (string) $label ); ?></dt>
								<dd><?php echo esc_html( (string) $text ); ?></dd>
							<?php endforeach; ?>
						</dl>
					</section>
				<?php endif; ?>
			</div>

			<aside class="dgl-pub__aside dgl-venue__aside" id="enquire">
				<?php if ( $has_form ) : ?>
					<?php View::output( 'public/enquiry-form', [ 'post' => $post, 'spaces' => $spaces, 'enquiry' => $enquiry, 'reply' => $reply ] ); ?>
				<?php elseif ( ! empty( $enquiry['off'] ) ) : ?>
					<div class="dgl-pub__card">
						<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Enquire', 'dgl-platform' ); ?></h2>
						<p class="dgl-pub__note"><?php esc_html_e( 'Enquiries through the site are not switched on yet. Contact the venue directly using the details below.', 'dgl-platform' ); ?></p>
					</div>
				<?php endif; ?>

				<div class="dgl-pub__card">
					<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Where and who', 'dgl-platform' ); ?></h2>
					<dl class="dgl-pub__facts dgl-org__facts">
						<?php if ( [] !== $address ) : ?>
							<dt><?php esc_html_e( 'Address', 'dgl-platform' ); ?></dt>
							<dd><?php echo esc_html( implode( ', ', $address ) ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== $value( 'contact_name' ) ) : ?>
							<dt><?php esc_html_e( 'Contact', 'dgl-platform' ); ?></dt>
							<dd><?php echo esc_html( $value( 'contact_name' ) ); ?></dd>
						<?php endif; ?>
						<?php if ( 1 === preg_match( '/\d{5,}/', $phone ) ) : ?>
							<dt><?php esc_html_e( 'Phone', 'dgl-platform' ); ?></dt>
							<dd><a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd>
						<?php endif; ?>
						<?php if ( ! $has_form && '' !== $value( 'contact_email' ) ) : ?>
							<dt><?php esc_html_e( 'Email', 'dgl-platform' ); ?></dt>
							<dd><a href="mailto:<?php echo esc_attr( $value( 'contact_email' ) ); ?>"><?php echo esc_html( $value( 'contact_email' ) ); ?></a></dd>
						<?php endif; ?>
						<?php if ( '' !== $value( 'website' ) ) : ?>
							<dt><?php esc_html_e( 'Website', 'dgl-platform' ); ?></dt>
							<dd><a href="<?php echo esc_url( $value( 'website' ) ); ?>" rel="nofollow noopener"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $value( 'website' ) ) ); ?></a></dd>
						<?php endif; ?>
						<?php if ( '' !== $ward ) : ?>
							<dt><?php esc_html_e( 'Ward', 'dgl-platform' ); ?></dt>
							<dd><?php echo esc_html( $ward ); ?></dd>
						<?php endif; ?>
					</dl>
					<?php if ( ! empty( $data['map'] ) ) : ?>
						<div class="dgl-venue__map" id="dgl-venue-map" data-dgl-map="<?php echo esc_attr( (string) wp_json_encode( $data['map'] ) ); ?>"></div>
					<?php endif; ?>
				</div>
			</aside>
		</div>

		<?php if ( '' !== $archive ) : ?>
			<p class="dgl-pub__back"><a href="<?php echo esc_url( $archive ); ?>"><?php esc_html_e( 'All spaces to hire', 'dgl-platform' ); ?></a></p>
		<?php endif; ?>
	</article>

	<?php if ( $has_form && [] !== $spaces ) : ?>
		<div class="dgl-venue__bar" aria-hidden="true">
			<p>
				<?php if ( null !== $facts['from'] && $facts['from'] > 0 ) : ?>
					<b><?php echo esc_html( sprintf( /* translators: 1: an amount, 2: "an hour" etc. */ __( 'From %1$s %2$s', 'dgl-platform' ), SpacesQuery::money( (float) $facts['from'] ), Venue::labelled( \DGL\Schema\Types\Space::UNITS )[ $facts['from_unit'] ] ?? '' ) ); ?></b>
				<?php endif; ?>
				<?php echo esc_html( sprintf( /* translators: %s: a number. */ _n( '%s space', '%s spaces', $facts['count'], 'dgl-platform' ), number_format_i18n( $facts['count'] ) ) ); ?>
			</p>
			<a class="dgl-pub__button" href="#enquire"><?php esc_html_e( 'Enquire', 'dgl-platform' ); ?></a>
		</div>
	<?php endif; ?>
</div>
