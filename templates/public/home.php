<?php
/**
 * The sample home page, built from the agreed wireframe.
 *
 * Twelve blocks, top to bottom: hero, quick links, what the partnership does,
 * what's on, latest news, training, funding, find an organisation,
 * testimonials, the weekly round-up. The theme's header and footer do the
 * rest. Every list reads live content; funding and testimonials are drawn
 * as labelled placeholders until there is something real to show.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Frontend\Frontend;
use DGL\Frontend\Home;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

$events    = (array) ( $data['events'] ?? [] );
$news      = (array) ( $data['news'] ?? [] );
$training  = (array) ( $data['training'] ?? [] );
$grants    = (array) ( $data['grants'] ?? [] );
$quotes    = (array) ( $data['quotes'] ?? [] );
$partners  = (array) ( $data['partners'] ?? [] );
$areas     = (array) ( $data['areas'] ?? [] );
$urls      = (array) ( $data['urls'] ?? [] );
$org_total = (int) ( $data['org_total'] ?? 0 );
$lead      = [] !== $news ? array_shift( $news ) : null;

/** One dated row: the leaf, the picture, the title and the where/org line. */
$dated_row = static function ( WP_Post $item, string $heading = 'h3' ): void {
	$org   = Frontend::organisation( $item )['name'];
	$where = Frontend::where( $item );
	$line  = implode( ' · ', array_filter( [ $where, $org ] ) );
	?>
	<li class="dgl-home__row">
		<?php echo Cards::date_block( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		<a class="dgl-home__rowpic" href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
		<div class="dgl-home__rowbody">
			<?php if ( Home::is_featured( $item ) ) : ?>
				<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
			<?php elseif ( PostTypes::EVENT === $item->post_type && \DGL\Events\Cancel::is_cancelled( (int) $item->ID ) ) : ?>
				<span class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></span>
			<?php endif; ?>
			<<?php echo esc_html( $heading ); ?> class="dgl-home__rowtitle"><a href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></<?php echo esc_html( $heading ); ?>>
			<?php if ( '' !== $line ) : ?>
				<p class="dgl-home__rowmeta"><?php echo esc_html( $line ); ?></p>
			<?php endif; ?>
		</div>
	</li>
	<?php
};
?>
<div class="dgl-pub dgl-home">

	<!-- 2. Hero -->
	<section class="dgl-home__hero" aria-labelledby="dgl-home-h1">
		<div class="dgl-home__herotext">
			<p class="dgl-home__kicker"><?php esc_html_e( 'Doing Good Leeds Partnership', 'dgl-platform' ); ?></p>
			<h1 class="dgl-home__h1" id="dgl-home-h1"><?php esc_html_e( 'The people doing good in Leeds, in one place.', 'dgl-platform' ); ?></h1>
			<p class="dgl-home__lede"><?php esc_html_e( 'News, events, training and funding from voluntary and community organisations across the city. Posted by the organisations themselves, checked by the partnership team before it goes live.', 'dgl-platform' ); ?></p>
			<div class="dgl-home__actions">
				<a class="dgl-pub__button dgl-home__button" href="<?php echo esc_url( (string) $data['join_url'] ); ?>"><?php esc_html_e( 'Join the partnership', 'dgl-platform' ); ?></a>
				<a class="dgl-home__button dgl-home__button--quiet" href="<?php echo esc_url( (string) $data['dir_url'] ); ?>"><?php esc_html_e( 'Find an organisation', 'dgl-platform' ); ?></a>
			</div>
			<p class="dgl-home__small"><?php esc_html_e( 'Free for organisations working in Leeds. Accounts are approved by the team.', 'dgl-platform' ); ?></p>
		</div>
		<div class="dgl-home__heropic<?php echo '' === $data['hero_image'] ? ' dgl-home__heropic--none' : ''; ?>">
			<?php if ( '' !== $data['hero_image'] ) : ?>
				<?php echo $data['hero_image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
			<?php else : ?>
				<p class="dgl-home__placeholder"><?php esc_html_e( 'Hero photo goes here: real people at a real Leeds community event.', 'dgl-platform' ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<!-- 3. Quick links -->
	<nav class="dgl-home__quick" aria-label="<?php esc_attr_e( 'I am looking for', 'dgl-platform' ); ?>">
		<p class="dgl-home__quicklabel"><?php esc_html_e( 'I am looking for', 'dgl-platform' ); ?></p>
		<ul class="dgl-home__quicklist">
			<li><a href="<?php echo esc_url( (string) $data['dir_url'] ); ?>"><?php esc_html_e( 'An organisation that can help', 'dgl-platform' ); ?></a></li>
			<li><a href="<?php echo esc_url( (string) ( $urls[ PostTypes::EVENT ] ?? '' ) ); ?>"><?php esc_html_e( 'Something happening near me', 'dgl-platform' ); ?></a></li>
			<li><a href="<?php echo esc_url( (string) ( $urls[ PostTypes::TRAINING ] ?? '' ) ); ?>"><?php esc_html_e( 'Training for my role', 'dgl-platform' ); ?></a></li>
			<li><a href="<?php echo esc_url( '' !== (string) ( $urls[ PostTypes::GRANT ] ?? '' ) ? (string) $urls[ PostTypes::GRANT ] : '#dgl-home-funding' ); ?>"><?php esc_html_e( 'Funding for my organisation', 'dgl-platform' ); ?></a></li>
		</ul>
	</nav>

	<!-- 4. What the partnership does -->
	<section class="dgl-home__section dgl-home__about" aria-labelledby="dgl-home-about">
		<h2 class="dgl-home__h2" id="dgl-home-about"><?php esc_html_e( 'What the partnership does', 'dgl-platform' ); ?></h2>
		<ul class="dgl-home__benefits">
			<li>
				<span class="dgl-home__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H8l-4 4z"></path><path d="M8 9h8M8 12h5"></path></svg></span>
				<h3><?php esc_html_e( 'Share what is happening', 'dgl-platform' ); ?></h3>
				<p><?php esc_html_e( 'Members post their own news, events and training. Every item is read by the team before it is published.', 'dgl-platform' ); ?></p>
			</li>
			<li>
				<span class="dgl-home__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg></span>
				<h3><?php esc_html_e( 'Find organisations you can trust', 'dgl-platform' ); ?></h3>
				<p><?php esc_html_e( 'A directory of verified organisations across Leeds, searchable by name, by what they do and by who they work with.', 'dgl-platform' ); ?></p>
			</li>
			<li>
				<span class="dgl-home__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path></svg></span>
				<h3><?php esc_html_e( 'Stay in the loop', 'dgl-platform' ); ?></h3>
				<p><?php esc_html_e( 'One email every Tuesday with everything new from the past seven days. Change what you get or stop it any time.', 'dgl-platform' ); ?></p>
			</li>
		</ul>
		<?php if ( [] !== $partners ) : ?>
			<div class="dgl-home__partners">
				<span class="dgl-home__partnerslabel"><?php esc_html_e( 'A partnership of', 'dgl-platform' ); ?></span>
				<ul>
					<?php foreach ( $partners as $partner ) : ?>
						<li><a href="<?php echo esc_url( (string) ( $partner['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $partner['name'] ?? '' ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</section>

	<!-- 5. What's on -->
	<section class="dgl-home__section" aria-labelledby="dgl-home-events">
		<div class="dgl-home__sechead">
			<h2 class="dgl-home__h2" id="dgl-home-events"><?php esc_html_e( "What's on", 'dgl-platform' ); ?></h2>
			<a class="dgl-home__all" href="<?php echo esc_url( (string) ( $urls[ PostTypes::EVENT ] ?? '' ) ); ?>"><?php esc_html_e( 'All events', 'dgl-platform' ); ?></a>
		</div>
		<?php if ( [] === $events ) : ?>
			<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<ul class="dgl-home__rows dgl-home__rows--3">
				<?php foreach ( $events as $item ) : ?>
					<?php $dated_row( $item ); ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<!-- 6. Latest news -->
	<section class="dgl-home__section" aria-labelledby="dgl-home-news">
		<div class="dgl-home__sechead">
			<h2 class="dgl-home__h2" id="dgl-home-news"><?php esc_html_e( 'Latest news', 'dgl-platform' ); ?></h2>
			<a class="dgl-home__all" href="<?php echo esc_url( (string) ( $urls[ PostTypes::NEWS ] ?? '' ) ); ?>"><?php esc_html_e( 'All news', 'dgl-platform' ); ?></a>
		</div>
		<?php if ( null === $lead ) : ?>
			<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<div class="dgl-home__news">
				<article class="dgl-home__lead">
					<a class="dgl-home__leadpic" href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $lead, 'large', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
					<p class="dgl-card__chip">
						<?php echo esc_html( Cards::chip( $lead ) ); ?>
						<?php if ( Home::is_featured( $lead ) ) : ?>
							<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
						<?php endif; ?>
					</p>
					<h3 class="dgl-home__leadtitle"><a href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>"><?php echo esc_html( get_the_title( $lead ) ); ?></a></h3>
					<?php $summary = Cards::summary( $lead, 40 ); ?>
					<?php if ( '' !== $summary ) : ?>
						<p class="dgl-home__leadsummary"><?php echo esc_html( $summary ); ?></p>
					<?php endif; ?>
					<p class="dgl-home__rowmeta"><?php echo esc_html( implode( ' · ', array_filter( [ Frontend::organisation( $lead )['name'], sprintf( /* translators: %s: a date like "24 Sep 2026". */ __( 'Posted: %s', 'dgl-platform' ), Cards::posted( $lead ) ) ] ) ) ); ?></p>
				</article>
				<?php if ( [] !== $news ) : ?>
					<ul class="dgl-home__newslist">
						<?php foreach ( $news as $item ) : ?>
							<li class="dgl-home__row dgl-home__row--news">
								<a class="dgl-home__rowpic" href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
								<div class="dgl-home__rowbody">
									<h3 class="dgl-home__rowtitle"><a href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></h3>
									<p class="dgl-home__rowmeta"><?php echo esc_html( implode( ' · ', array_filter( [ Frontend::organisation( $item )['name'], sprintf( /* translators: %s: a date like "24 Sep 2026". */ __( 'Posted: %s', 'dgl-platform' ), Cards::posted( $item ) ) ] ) ) ); ?></p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- 7. Training -->
	<section class="dgl-home__section" aria-labelledby="dgl-home-training">
		<div class="dgl-home__sechead">
			<h2 class="dgl-home__h2" id="dgl-home-training"><?php esc_html_e( 'Training and learning', 'dgl-platform' ); ?></h2>
			<a class="dgl-home__all" href="<?php echo esc_url( (string) ( $urls[ PostTypes::TRAINING ] ?? '' ) ); ?>"><?php esc_html_e( 'All training', 'dgl-platform' ); ?></a>
		</div>
		<?php if ( [] === $training ) : ?>
			<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<ul class="dgl-home__rows dgl-home__rows--3">
				<?php foreach ( $training as $item ) : ?>
					<?php $dated_row( $item ); ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<!-- 8. Funding -->
	<section class="dgl-home__section" id="dgl-home-funding" aria-labelledby="dgl-home-funding-h">
		<div class="dgl-home__sechead">
			<h2 class="dgl-home__h2" id="dgl-home-funding-h"><?php esc_html_e( 'Funding and grants', 'dgl-platform' ); ?></h2>
			<?php if ( $data['grants_on'] ) : ?>
				<a class="dgl-home__all" href="<?php echo esc_url( (string) ( $urls[ PostTypes::GRANT ] ?? '' ) ); ?>"><?php esc_html_e( 'All funding', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( ! $data['grants_on'] ) : ?>
			<div class="dgl-home__placeholderbox">
				<p class="dgl-home__placeholder"><?php esc_html_e( 'Open funding opportunities will list here, soonest closing first, once the Grants section is switched on.', 'dgl-platform' ); ?></p>
			</div>
		<?php elseif ( [] === $grants ) : ?>
			<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<ul class="dgl-home__rows dgl-home__rows--3">
				<?php foreach ( $grants as $item ) : ?>
					<?php $dated_row( $item ); ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<!-- 9. Find an organisation -->
	<section class="dgl-home__section dgl-home__find" aria-labelledby="dgl-home-find">
		<h2 class="dgl-home__h2" id="dgl-home-find"><?php esc_html_e( 'Find an organisation', 'dgl-platform' ); ?></h2>
		<p class="dgl-home__findlede">
			<?php
			if ( $org_total > 0 ) {
				/* translators: %s: a number. */
				echo esc_html( sprintf( _n( '%s verified organisation listed so far.', '%s verified organisations, every one checked by the partnership team.', $org_total, 'dgl-platform' ), number_format_i18n( $org_total ) ) );
			} else {
				esc_html_e( 'Every organisation listed is checked by the partnership team.', 'dgl-platform' );
			}
			?>
		</p>
		<form class="dgl-home__search" method="get" action="<?php echo esc_url( (string) $data['dir_url'] ); ?>" role="search">
			<label class="dgl-home__srlabel" for="dgl-home-q"><?php esc_html_e( 'Search the directory', 'dgl-platform' ); ?></label>
			<input class="dgl-home__input" id="dgl-home-q" type="search" name="q" placeholder="<?php esc_attr_e( 'Try food, mental health, older people, Armley', 'dgl-platform' ); ?>">
			<button class="dgl-home__searchbutton" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
		</form>
		<?php if ( [] !== $areas ) : ?>
			<div class="dgl-home__chips">
				<span class="dgl-home__chipslabel"><?php esc_html_e( 'Browse by area of work', 'dgl-platform' ); ?></span>
				<?php foreach ( $areas as $key => $label ) : ?>
					<a class="dgl-home__chip" href="<?php echo esc_url( add_query_arg( 'area', (string) $key, (string) $data['dir_url'] ) ); ?>"><?php echo esc_html( (string) $label ); ?></a>
				<?php endforeach; ?>
				<a class="dgl-home__browse" href="<?php echo esc_url( (string) $data['dir_url'] ); ?>"><?php esc_html_e( 'Browse the full directory', 'dgl-platform' ); ?></a>
			</div>
		<?php endif; ?>
	</section>

	<!-- 10. Testimonials -->
	<section class="dgl-home__section" aria-labelledby="dgl-home-quotes">
		<h2 class="dgl-home__h2" id="dgl-home-quotes"><?php esc_html_e( 'What members say', 'dgl-platform' ); ?></h2>
		<ul class="dgl-home__quotes">
			<?php if ( [] === $quotes ) : ?>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
					<li class="dgl-home__quote dgl-home__quote--placeholder">
						<p class="dgl-home__placeholder"><?php esc_html_e( 'Testimonial to be supplied: 25 to 40 words from a member about a specific result, with their name, role and organisation.', 'dgl-platform' ); ?></p>
					</li>
				<?php endfor; ?>
			<?php else : ?>
				<?php foreach ( $quotes as $quote ) : ?>
					<li class="dgl-home__quote">
						<blockquote><p><?php echo esc_html( (string) $quote['quote'] ); ?></p></blockquote>
						<?php if ( '' !== $quote['name'] || '' !== $quote['role'] ) : ?>
							<p class="dgl-home__quotewho"><strong><?php echo esc_html( (string) $quote['name'] ); ?></strong><?php if ( '' !== $quote['role'] ) : ?> <span><?php echo esc_html( (string) $quote['role'] ); ?></span><?php endif; ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</section>

	<!-- 11. Weekly round-up -->
	<section class="dgl-home__section dgl-home__roundup" aria-labelledby="dgl-home-roundup">
		<div>
			<h2 class="dgl-home__h2" id="dgl-home-roundup"><?php esc_html_e( 'Get the weekly round-up', 'dgl-platform' ); ?></h2>
			<p class="dgl-home__lede"><?php esc_html_e( 'Join the partnership and every Tuesday morning we send you what is new: news, events, training and funding from across Leeds. Pick what you want, or stop it any time from the email.', 'dgl-platform' ); ?></p>
		</div>
		<div class="dgl-home__actions dgl-home__actions--stack">
			<a class="dgl-pub__button dgl-home__button" href="<?php echo esc_url( (string) $data['join_url'] ); ?>"><?php esc_html_e( 'Join the partnership', 'dgl-platform' ); ?></a>
			<a class="dgl-home__button dgl-home__button--quiet" href="<?php echo esc_url( (string) $data['signin_url'] ); ?>"><?php esc_html_e( 'Already a member? Sign in', 'dgl-platform' ); ?></a>
		</div>
	</section>

</div>
