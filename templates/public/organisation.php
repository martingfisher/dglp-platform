<?php
/**
 * One organisation's page in the public directory.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Frontend;
use DGL\Index\ItemsTable;
use DGL\Org\Directory;
use DGL\Org\Options;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$org  = $data['org'] ?? null;
$base = home_url( '/' . Directory::BASE . '/' );

if ( ! $org instanceof WP_Post ) :
	?>
	<div class="dgl-pub">
		<h1 class="dgl-pub__title"><?php esc_html_e( 'Not in the directory', 'dgl-platform' ); ?></h1>
		<p class="dgl-pub__lede"><?php esc_html_e( 'There is no organisation at this address, or it has chosen not to be listed.', 'dgl-platform' ); ?></p>
		<p class="dgl-pub__back"><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'All member organisations', 'dgl-platform' ); ?></a></p>
	</div>
	<?php
	return;
endif;

$id    = (int) $org->ID;
$meta  = static fn( string $key ): string => (string) get_post_meta( $id, 'dgl_org_' . $key, true );
$list  = static fn( string $key, array $options ): array => array_values( array_intersect_key( $options, array_flip( (array) get_post_meta( $id, 'dgl_org_' . $key, true ) ) ) );
$logo  = (int) $meta( 'logo' );
$blurb = $meta( 'description' );
$ward  = Options::wards()[ $meta( 'ward' ) ] ?? '';

$address = array_filter( [ $meta( 'address_1' ), $meta( 'address_2' ), $meta( 'city' ), $meta( 'postcode' ) ] );

$sections = [
	__( 'Areas of work', 'dgl-platform' )          => $list( 'specialism', Options::specialism() ),
	__( 'Services', 'dgl-platform' )               => $list( 'services', Options::services() ),
	__( 'How they deliver them', 'dgl-platform' )  => $list( 'delivery', Options::delivery() ),
	__( 'Who they work with', 'dgl-platform' )     => $list( 'service_users', Options::service_users() ),
	__( 'Accessibility at their premises', 'dgl-platform' ) => $list( 'accessibility', Options::accessibility() ),
	__( 'Accreditations', 'dgl-platform' )         => $list( 'accreditations', Options::accreditations() ),
];
$sections = array_filter( $sections );

$facts = array_filter(
	[
		__( 'Type', 'dgl-platform' )         => implode( ', ', $list( 'type', Options::org_type() ) ),
		__( 'Legal status', 'dgl-platform' ) => Options::legal()[ $meta( 'legal_status' ) ] ?? '',
		__( 'Charity or company number', 'dgl-platform' ) => $meta( 'number' ),
		__( 'Paid staff', 'dgl-platform' )   => Options::sizes()[ $meta( 'staff' ) ] ?? '',
		__( 'Volunteers', 'dgl-platform' )   => Options::sizes()[ $meta( 'volunteers' ) ] ?? '',
	]
);

// Their live listings, newest first, so the page is a door into what they are doing now.
$live = [];
foreach ( ItemsTable::for_org( $id, PostTypes::enabled_keys(), [ Statuses::LIVE ], 6 ) as $live_id ) {
	$item = get_post( (int) $live_id );
	if ( $item instanceof WP_Post ) {
		$live[] = $item;
	}
}
?>
<div class="dgl-pub dgl-org">
	<article class="dgl-pub__item">
		<p class="dgl-pub__kicker"><?php esc_html_e( 'Member organisation', 'dgl-platform' ); ?></p>

		<header class="dgl-org__head">
			<?php if ( $logo > 0 ) : ?>
				<div class="dgl-org__logo"><?php echo wp_get_attachment_image( $logo, 'medium', false, [ 'alt' => '' ] ); ?></div>
			<?php endif; ?>
			<div>
				<h1 class="dgl-pub__title"><?php echo esc_html( get_the_title( $org ) ); ?></h1>
				<?php if ( '' !== $ward ) : ?>
					<p class="dgl-org__where"><?php echo esc_html( $ward ); ?></p>
				<?php endif; ?>
			</div>
		</header>

		<div class="dgl-pub__layout<?php echo '' === $blurb && [] === $sections ? ' dgl-pub__layout--nobody' : ''; ?>">
			<div class="dgl-pub__body">
				<?php
				/*
				 * A long description opens folded: the first part, then a
				 * More button for the rest. Without JavaScript the whole
				 * text shows, since a hidden paragraph nobody can open is
				 * worse than a long one. The fold lands on a word boundary.
				 */
				$fold  = 320;
				$head  = $blurb;
				$tail  = '';
				if ( mb_strlen( $blurb ) > $fold + 80 ) {
					$at   = mb_strrpos( mb_substr( $blurb, 0, $fold ), ' ' );
					$at   = false === $at || $at < $fold / 2 ? $fold : $at;
					$head = mb_substr( $blurb, 0, $at );
					$tail = mb_substr( $blurb, $at );
				}
				?>
				<?php if ( '' !== $blurb ) : ?>
					<p class="dgl-pub__standfirst dgl-org__blurb" id="dgl-org-blurb">
						<?php echo esc_html( $head ); ?><?php if ( '' !== $tail ) : ?><span class="dgl-org__fold" hidden>…</span><span class="dgl-org__rest"><?php echo esc_html( $tail ); ?></span><?php endif; ?>
					</p>
					<?php if ( '' !== $tail ) : ?>
						<button class="dgl-org__more" type="button" aria-expanded="true" aria-controls="dgl-org-blurb" hidden><?php esc_html_e( 'More', 'dgl-platform' ); ?></button>
						<script>
						( function () {
							var more = document.querySelector( '.dgl-org__more' );
							var rest = document.querySelector( '.dgl-org__rest' );
							var fold = document.querySelector( '.dgl-org__fold' );
							if ( ! more || ! rest ) { return; }
							var open = false;
							function paint() {
								rest.hidden = ! open;
								fold.hidden = open;
								more.textContent = open ? <?php echo wp_json_encode( __( 'Less', 'dgl-platform' ) ); ?> : <?php echo wp_json_encode( __( 'More', 'dgl-platform' ) ); ?>;
								more.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
							}
							more.hidden = false;
							more.addEventListener( 'click', function () { open = ! open; paint(); } );
							paint();
						} )();
						</script>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				/*
				 * Areas of work are the organisation's identity and stay as a
				 * short row of tags under the description. Everything else is
				 * a labelled list: the category on the left, the items as a
				 * plain comma-separated line on the right. Forty chips at one
				 * weight were a wall nobody could parse; forty words in six
				 * lines read like a paragraph.
				 */
				$areas = $sections[ __( 'Areas of work', 'dgl-platform' ) ] ?? [];
				unset( $sections[ __( 'Areas of work', 'dgl-platform' ) ] );
				?>
				<?php if ( [] !== $areas ) : ?>
					<ul class="dgl-org__areas" aria-label="<?php esc_attr_e( 'Areas of work', 'dgl-platform' ); ?>">
						<?php foreach ( $areas as $label ) : ?>
							<li class="dgl-dir__tag"><?php echo esc_html( $label ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php foreach ( $sections as $heading => $items ) : ?>
					<section class="dgl-org__section">
						<h2 class="dgl-org__h2"><?php echo esc_html( $heading ); ?></h2>
						<?php
						// "Category: value" labels are grouped so the category is said once.
						$groups = [];
						foreach ( $items as $label ) {
							$parts = explode( ': ', $label, 2 );
							$groups[ 2 === count( $parts ) ? $parts[0] : '' ][] = 2 === count( $parts ) ? $parts[1] : $label;
						}
						if ( isset( $groups[''] ) ) {
							$groups = [ '' => $groups[''] ] + $groups;
						}
						$single = 1 === count( $groups ) && isset( $groups[''] );
						?>
						<?php if ( $single ) : ?>
							<p class="dgl-org__line"><?php echo esc_html( implode( ', ', $groups[''] ) ); ?></p>
						<?php else : ?>
							<dl class="dgl-org__list">
								<?php foreach ( $groups as $category => $tags ) : ?>
									<dt><?php echo '' === $category ? esc_html__( 'General', 'dgl-platform' ) : esc_html( $category ); ?></dt>
									<dd><?php echo esc_html( implode( ', ', $tags ) ); ?></dd>
								<?php endforeach; ?>
							</dl>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>

				<?php if ( [] !== $live ) : ?>
					<section class="dgl-org__section">
						<h2 class="dgl-org__h2"><?php esc_html_e( 'On the site now', 'dgl-platform' ); ?></h2>
						<ul class="dgl-org__live">
							<?php foreach ( $live as $item ) : ?>
								<li>
									<a href="<?php echo esc_url( get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a>
									<span class="dgl-org__livetype"><?php echo esc_html( Frontend::type_label( $item->post_type ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
			</div>

			<aside class="dgl-pub__aside">
				<div class="dgl-pub__card">
					<h2 class="dgl-pub__card-title"><?php esc_html_e( 'Get in touch', 'dgl-platform' ); ?></h2>
					<dl class="dgl-pub__facts dgl-org__facts">
						<?php if ( '' !== $meta( 'website' ) ) : ?>
							<dt><?php esc_html_e( 'Website', 'dgl-platform' ); ?></dt>
							<dd><a href="<?php echo esc_url( $meta( 'website' ) ); ?>" rel="nofollow noopener"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $meta( 'website' ) ) ); ?></a></dd>
						<?php endif; ?>
						<?php if ( '' !== $meta( 'email' ) ) : ?>
							<dt><?php esc_html_e( 'Email', 'dgl-platform' ); ?></dt>
							<dd><a href="mailto:<?php echo esc_attr( $meta( 'email' ) ); ?>"><?php echo esc_html( $meta( 'email' ) ); ?></a></dd>
						<?php endif; ?>
						<?php if ( 1 === preg_match( '/\d{5,}/', $meta( 'phone' ) ) ) : ?>
							<dt><?php esc_html_e( 'Phone', 'dgl-platform' ); ?></dt>
							<dd><a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $meta( 'phone' ) ) ); ?>"><?php echo esc_html( $meta( 'phone' ) ); ?></a></dd>
						<?php endif; ?>
						<?php if ( [] !== $address ) : ?>
							<dt><?php esc_html_e( 'Address', 'dgl-platform' ); ?></dt>
							<dd><?php echo esc_html( implode( ', ', $address ) ); ?></dd>
						<?php endif; ?>
					</dl>
				</div>

				<?php if ( [] !== $facts ) : ?>
					<div class="dgl-pub__card">
						<h2 class="dgl-pub__card-title"><?php esc_html_e( 'About', 'dgl-platform' ); ?></h2>
						<dl class="dgl-pub__facts dgl-org__facts">
							<?php foreach ( $facts as $label => $value ) : ?>
								<dt><?php echo esc_html( $label ); ?></dt>
								<dd><?php echo esc_html( $value ); ?></dd>
							<?php endforeach; ?>
						</dl>
					</div>
				<?php endif; ?>
			</aside>
		</div>

		<p class="dgl-pub__back"><a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'All member organisations', 'dgl-platform' ); ?></a></p>
	</article>
</div>
