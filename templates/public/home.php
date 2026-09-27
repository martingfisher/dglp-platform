<?php
/**
 * The sample home page, built from the agreed wireframe.
 *
 * Twelve blocks, top to bottom: hero, quick links, what the partnership does,
 * what's on, latest news, training, funding, find an organisation,
 * testimonials, the weekly round-up. The theme's header and footer do the
 * rest. The live sections are the partials under `public/home/`, the
 * same ones the shortcodes render, so the sample and a page built in
 * the theme can never drift apart. Funding and testimonials are drawn
 * as labelled placeholders until there is something real to show.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

$quotes   = (array) ( $data['quotes'] ?? [] );
$partners = (array) ( $data['partners'] ?? [] );
$urls     = (array) ( $data['urls'] ?? [] );

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

	<?php
	// 5 to 9: the live sections, the same partials the shortcodes render.
	foreach ( [ 'events', 'news', 'training', 'funding', 'directory' ] as $dgl_section ) {
		echo \DGL\Frontend\HomeBlocks::section( $dgl_section ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}
	?>

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

	<?php echo \DGL\Frontend\HomeBlocks::section( 'roundup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>

</div>
