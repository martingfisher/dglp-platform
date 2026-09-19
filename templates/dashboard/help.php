<?php
/**
 * A help guide: sections, a contents list, questions people ask, and the
 * same thing as a PDF.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$guide    = (array) ( $data['guide'] ?? [] );
$sections = (array) ( $guide['sections'] ?? [] );
$faqs     = (array) ( $guide['faqs'] ?? [] );
$pdf_url  = (string) ( $data['pdf_url'] ?? '' );
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php echo esc_html( (string) ( $guide['title'] ?? '' ) ); ?></h1>
		<p class="dgl-page-head__lede"><?php echo esc_html( (string) ( $guide['lede'] ?? '' ) ); ?></p>
	</div>
	<?php if ( '' !== $pdf_url ) : ?>
		<div class="dgl-page-head__actions">
			<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( $pdf_url ); ?>" download><?php esc_html_e( 'Download as PDF', 'dgl-platform' ); ?></a>
		</div>
	<?php endif; ?>
</header>

<div class="dgl-help-guide">
	<nav class="dgl-card dgl-help-guide__contents" aria-label="<?php esc_attr_e( 'On this page', 'dgl-platform' ); ?>">
		<p class="dgl-help-guide__contents-label"><?php esc_html_e( 'On this page', 'dgl-platform' ); ?></p>
		<ol>
			<?php foreach ( $sections as $section ) : ?>
				<li><a href="#<?php echo esc_attr( (string) $section['id'] ); ?>"><?php echo esc_html( (string) $section['heading'] ); ?></a></li>
			<?php endforeach; ?>
			<?php if ( [] !== $faqs ) : ?>
				<li><a href="#faqs"><?php echo esc_html( (string) ( $guide['faq_heading'] ?? __( 'Questions people ask', 'dgl-platform' ) ) ); ?></a></li>
			<?php endif; ?>
		</ol>
	</nav>

	<?php foreach ( $sections as $section ) : ?>
		<section class="dgl-card dgl-help-guide__section" id="<?php echo esc_attr( (string) $section['id'] ); ?>">
			<h2 class="dgl-section__title"><?php echo esc_html( (string) $section['heading'] ); ?></h2>
			<?php foreach ( (array) $section['blocks'] as $block ) : ?>
				<?php switch ( (string) $block[0] ) :
					case 'ul': ?>
						<ul class="dgl-help-guide__list">
							<?php foreach ( (array) $block[1] as $item ) : ?>
								<li><?php echo esc_html( (string) $item ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php break; ?>
					<?php case 'ol': ?>
						<ol class="dgl-help-guide__list">
							<?php foreach ( (array) $block[1] as $item ) : ?>
								<li><?php echo esc_html( (string) $item ); ?></li>
							<?php endforeach; ?>
						</ol>
						<?php break; ?>
					<?php case 'h3': ?>
						<h3 class="dgl-help-guide__sub"><?php echo esc_html( (string) $block[1] ); ?></h3>
						<?php break; ?>
					<?php default: ?>
						<p><?php echo esc_html( (string) $block[1] ); ?></p>
				<?php endswitch; ?>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( [] !== $faqs ) : ?>
		<section class="dgl-card dgl-help-guide__section" id="faqs">
			<h2 class="dgl-section__title"><?php echo esc_html( (string) ( $guide['faq_heading'] ?? __( 'Questions people ask', 'dgl-platform' ) ) ); ?></h2>
			<?php foreach ( $faqs as $faq ) : ?>
				<details class="dgl-help-guide__faq">
					<summary><?php echo esc_html( (string) $faq['q'] ); ?></summary>
					<p><?php echo esc_html( (string) $faq['a'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>
</div>
