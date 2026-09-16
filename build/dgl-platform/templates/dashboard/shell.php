<?php
/**
 * The member area's own document.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\UserContext;
use DGL\Dashboard\Router;

defined( 'ABSPATH' ) || exit;

/*
 * This is a full HTML document rather than a fragment inside the theme's
 * header and footer, and that is deliberate.
 *
 * Rendered inside the site chrome, a signed-in member was shown the public
 * utility bar inviting them to "Register/Login", a main menu competing with
 * this page's own navigation, a breadcrumb that means nothing inside a tool,
 * and the whole site footer. Two navigation systems on one page, one of them
 * offering to log in somebody who already had.
 *
 * `wp_head()` and `wp_footer()` are still called, which is the part that
 * matters: analytics, snippet plugins and anything else hooked there carries on
 * working. Only the theme's header and footer *templates* are skipped, so
 * nothing here depends on Blocksy's markup and a theme update cannot break the
 * member area.
 */

$nav      = $data['nav'] ?? [];
$user     = $data['user'] ?? null;
$sections = [];

foreach ( $nav as $item ) {
	$sections[ $item['section'] ][] = $item;
}

$alerts = (int) ( $data['alerts'] ?? 0 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'dgl-body' ); ?>>

<a class="dgl-skip" href="#dgl-main"><?php esc_html_e( 'Skip to content', 'dgl-platform' ); ?></a>

<header class="dgl-topbar">
	<div class="dgl-topbar__inner">
		<a class="dgl-topbar__brand" href="<?php echo esc_url( null !== $user ? Router::url() : home_url() ); ?>">
			<?php echo \DGL\Logo::dashboard_html( 'medium', [ 'class' => 'dgl-topbar__logo' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="dgl-topbar__label"><?php esc_html_e( 'Member area', 'dgl-platform' ); ?></span>
		</a>

		<div class="dgl-topbar__end">
			<?php if ( null !== $user ) : ?>
				<a class="dgl-topbar__link" href="<?php echo esc_url( Router::url( 'notifications' ) ); ?>">
					<?php esc_html_e( 'Notifications', 'dgl-platform' ); ?>
					<?php if ( $alerts > 0 ) : ?>
						<span class="dgl-topbar__badge"><?php echo esc_html( (string) $alerts ); ?></span>
					<?php endif; ?>
				</a>
				<a class="dgl-topbar__link" href="<?php echo esc_url( Router::url( 'profile' ) ); ?>">
					<?php echo esc_html( wp_get_current_user()->display_name ); ?>
				</a>
				<a class="dgl-topbar__link" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">
					<?php esc_html_e( 'Sign out', 'dgl-platform' ); ?>
				</a>
			<?php endif; ?>

			<?php /* Nobody should feel shut inside the tool. */ ?>
			<a class="dgl-topbar__link dgl-topbar__link--back" href="<?php echo esc_url( home_url() ); ?>">
				<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Back to the website', 'dgl-platform' ); ?>
			</a>
		</div>
	</div>
</header>

<div class="dgl-dash">
	<?php
	/*
	 * The layout is a two-column grid with the sidebar first. On a screen that
	 * has no sidebar - signing in, no access, accepting an invitation - <main>
	 * became the first grid item and inherited the 260px navigation column,
	 * with the wide column left empty beside it. Every word wrapped. It looked
	 * like a broken stylesheet and had been doing it on the sign-in screen from
	 * the start, because nothing renders those pages except a browser.
	 */
	$layout_class = [] !== $sections ? 'dgl-dash__layout' : 'dgl-dash__layout dgl-dash__layout--solo';
	?>
	<div class="<?php echo esc_attr( $layout_class ); ?>">
		<?php if ( [] !== $sections ) : ?>
			<?php
			/*
			 * A <details> element, not a JavaScript menu. On a phone the
			 * navigation collapses to one line so the page starts at the top;
			 * on a wide screen CSS forces it open and the summary is hidden. It
			 * works with JavaScript off and it is keyboard accessible without
			 * anybody writing that part.
			 */
			?>
			<details class="dgl-dash__sidebar" id="dgl-menu" open>
				<summary class="dgl-dash__menu-toggle"><?php esc_html_e( 'Menu', 'dgl-platform' ); ?></summary>

				<nav class="dgl-nav" aria-label="<?php esc_attr_e( 'Member area', 'dgl-platform' ); ?>">
					<?php foreach ( $sections as $heading => $items ) : ?>
						<?php if ( '' !== $heading ) : ?>
							<p class="dgl-nav__heading"><?php echo esc_html( $heading ); ?></p>
						<?php endif; ?>
						<ul class="dgl-nav__list">
							<?php foreach ( $items as $item ) : ?>
								<li>
									<a
										class="dgl-nav__item<?php echo $item['current'] ? ' dgl-nav__item--active' : ''; ?>"
										href="<?php echo esc_url( $item['url'] ); ?>"
										<?php echo $item['current'] ? ' aria-current="page"' : ''; ?>
									>
										<span><?php echo esc_html( $item['label'] ); ?></span>
										<?php if ( null !== $item['count'] ) : ?>
											<span class="dgl-nav__count"><?php echo esc_html( (string) $item['count'] ); ?></span>
										<?php endif; ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</nav>

				<?php if ( null !== $user ) : ?>
					<div class="dgl-dash__account">
						<p class="dgl-dash__account-name"><?php echo esc_html( wp_get_current_user()->display_name ); ?></p>
						<?php if ( null !== $user->org_id ) : ?>
							<p class="dgl-dash__account-org"><?php echo esc_html( get_the_title( $user->org_id ) ); ?></p>
						<?php endif; ?>
						<a class="dgl-dash__signout" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">
							<?php esc_html_e( 'Sign out', 'dgl-platform' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</details>
		<?php endif; ?>

		<main class="dgl-dash__main" id="dgl-main">
			<?php
			if ( null !== $user && UserContext::ACCOUNT_PENDING === $user->account_status ) :
				?>
				<div class="dgl-banner" role="status">
					<strong><?php esc_html_e( 'Your account is still being checked.', 'dgl-platform' ); ?></strong>
					<?php esc_html_e( 'You can write drafts now and submit them as soon as the team approves you. This usually takes one working day.', 'dgl-platform' ); ?>
				</div>
			<?php endif; ?>

			<?php echo $data['content'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered by View, escaped at source. ?>
		</main>
	</div>
</div>

<?php
/*
 * wp_footer() stays, for the scripts and anything a plugin hooks there. But
 * on staging the theme hooks its entire site footer there too, and the
 * member area ended with the public footer under it: logo, policies, the
 * copyright line, in sky blue. So the output is captured and any <footer>
 * element in it is removed. Scripts, styles and the admin bar pass through
 * untouched.
 */
ob_start();
wp_footer();
$footer_output = (string) ob_get_clean();
echo preg_replace( '#<footer\b[^>]*>.*?</footer>#si', '', $footer_output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress's own footer output, minus the theme's footer element.
?>
</body>
</html>
