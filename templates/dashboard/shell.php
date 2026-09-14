<?php
/**
 * Member area chrome: sidebar and content column.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;

defined( 'ABSPATH' ) || exit;

$nav      = $data['nav'] ?? [];
$user     = $data['user'] ?? null;
$sections = [];

foreach ( $nav as $item ) {
	$sections[ $item['section'] ][] = $item;
}
?>
<div class="dgl-dash">
	<div class="dgl-dash__layout">
		<aside class="dgl-dash__sidebar" aria-label="<?php esc_attr_e( 'Member area', 'dgl-platform' ); ?>">
			<a class="dgl-dash__brand" href="<?php echo esc_url( Router::url() ); ?>">
				<?php echo \DGL\Logo::dashboard_html( 'medium', [ 'class' => 'dgl-dash__logo' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>

			<nav class="dgl-nav">
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
		</aside>

		<main class="dgl-dash__main" id="dgl-main">
			<?php
			if ( null !== $user && \DGL\Access\UserContext::ACCOUNT_PENDING === $user->account_status ) :
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
