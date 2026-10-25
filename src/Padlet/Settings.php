<?php
/**
 * Settings, Wellbeing board: the groups the Padlet posts are shown in.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Padlet;

defined( 'ABSPATH' ) || exit;

/**
 * Padlet's feeds do not say which column of the board a post is in, so the
 * team decides here: name the groups, write a line under each, and put each
 * post in one. A post that arrives later lands in "More support" and is
 * listed at the top of this screen until it is placed. The posts left out
 * (Amazon links, posts with no title) are listed too, with why, so nobody
 * wonders where they went.
 */
final class Settings {

	public const SLUG   = 'dgl-padlet';
	public const ACTION = 'dgl_padlet_save';
	public const FETCH  = 'dgl_padlet_fetch';

	/** Blank rows offered for new groups. */
	private const SPARE = 2;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
		add_action( 'admin_post_' . self::FETCH, [ self::class, 'fetch' ] );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Wellbeing board', 'dgl-platform' ),
			__( 'Wellbeing board', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'screen' ]
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dgl-platform' ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is cleaned by Board::clean_grouping().
		$names  = (array) wp_unslash( $_POST['group_name'] ?? [] );
		$intros = (array) wp_unslash( $_POST['group_intro'] ?? [] );
		$ids    = (array) wp_unslash( $_POST['group_id'] ?? [] );
		$orders = (array) wp_unslash( $_POST['group_order'] ?? [] );
		$assign = (array) wp_unslash( $_POST['assign'] ?? [] );
		// phpcs:enable

		$groups = [];

		foreach ( $names as $i => $name ) {
			$groups[] = [
				'id'    => (string) ( $ids[ $i ] ?? '' ),
				'name'  => (string) $name,
				'intro' => (string) ( $intros[ $i ] ?? '' ),
				'order' => is_numeric( $orders[ $i ] ?? null ) ? (float) $orders[ $i ] : 1000 + (int) $i,
			];
		}

		usort( $groups, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		update_option( Board::OPTION_GROUPS, Board::clean_grouping( [ 'groups' => $groups, 'assign' => $assign ] ), false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'saved' => '1' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function fetch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dgl-platform' ) );
		}

		check_admin_referer( self::FETCH );

		$last = Board::refresh();

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'fetched' => $last['ok'] ? '1' : '0' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$grouping = Board::grouping();
		$items    = Board::items();
		$left_out = Board::left_out();
		$last     = Board::last();
		$fetched  = Board::fetched();
		$unplaced = array_filter( $items, static fn( array $item ): bool => ! isset( $grouping['assign'][ (string) ( $item['key'] ?? '' ) ] ) );
		$rows     = array_merge( $grouping['groups'], array_fill( 0, self::SPARE, [ 'id' => '', 'name' => '', 'intro' => '' ] ) );
		$choices  = [ Board::DEFAULT_GROUP => __( 'More support', 'dgl-platform' ) ];

		foreach ( $grouping['groups'] as $group ) {
			$choices[ $group['id'] ] = $group['name'];
		}

		usort( $items, static fn( array $a, array $b ): int => strnatcasecmp( (string) $a['title'], (string) $b['title'] ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices after a redirect.
		$saved      = isset( $_GET['saved'] );
		$fetch_said = isset( $_GET['fetched'] ) ? '1' === $_GET['fetched'] : null;
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wellbeing board', 'dgl-platform' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. The board shows the new groups straight away.', 'dgl-platform' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null !== $fetch_said ) : ?>
				<div class="notice notice-<?php echo $fetch_said ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $fetch_said ? __( 'Fetched from Padlet.', 'dgl-platform' ) : sprintf( /* translators: %s: the error. */ __( 'The fetch failed: %s The last good board is still showing.', 'dgl-platform' ), (string) ( $last['error'] ?? '' ) ) ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: the board's address, linked. */
						__( 'The [dgl_padlet] block shows the posts from Leeds Health and Care Academy\'s %s as cards on this site. It is fetched from Padlet every night. Padlet does not say which column a post is in, so the groups are set here.', 'dgl-platform' ),
						'<a href="' . esc_url( Board::BOARD_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Health and Wellbeing Support board', 'dgl-platform' ) . '</a>'
					),
					[ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ] ]
				);
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: when, 2: how many shown, 3: how many left out. */
						__( 'Last fetched: %1$s. %2$d posts shown, %3$d left out.', 'dgl-platform' ),
						$fetched > 0 ? wp_date( 'j F Y, H:i', $fetched ) : __( 'never', 'dgl-platform' ),
						count( $items ),
						count( $left_out )
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FETCH ); ?>">
				<?php wp_nonce_field( self::FETCH ); ?>
				<?php submit_button( __( 'Fetch from Padlet now', 'dgl-platform' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<?php if ( [] !== $unplaced && [] !== $grouping['groups'] ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: how many posts. */
								_n( '%d post has no group yet, so it shows under More support. Choose a group for it below.', '%d posts have no group yet, so they show under More support. Choose a group for them below.', count( $unplaced ), 'dgl-platform' ),
								count( $unplaced )
							)
						);
						?>
					</p></div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Groups', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'Shown in order, lowest number first. Clear a name to remove that group: its posts move to More support. More support always comes last and only shows when something is in it.', 'dgl-platform' ); ?></p>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr>
						<th scope="col" style="width:70px"><?php esc_html_e( 'Order', 'dgl-platform' ); ?></th>
						<th scope="col" style="width:260px"><?php esc_html_e( 'Name', 'dgl-platform' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Line under the heading (optional)', 'dgl-platform' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $i => $group ) : ?>
							<tr>
								<td>
									<input type="hidden" name="group_id[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $group['id'] ); ?>">
									<label class="screen-reader-text" for="dgl-g-o-<?php echo (int) $i; ?>"><?php esc_html_e( 'Order', 'dgl-platform' ); ?></label>
									<input type="number" id="dgl-g-o-<?php echo (int) $i; ?>" name="group_order[<?php echo (int) $i; ?>]" value="<?php echo (int) ( $i + 1 ); ?>" min="1" step="1" style="width:60px">
								</td>
								<td>
									<label class="screen-reader-text" for="dgl-g-n-<?php echo (int) $i; ?>"><?php esc_html_e( 'Name', 'dgl-platform' ); ?></label>
									<input type="text" id="dgl-g-n-<?php echo (int) $i; ?>" name="group_name[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $group['name'] ); ?>" class="regular-text" style="width:100%"<?php echo '' === $group['id'] ? ' placeholder="' . esc_attr__( 'New group', 'dgl-platform' ) . '"' : ''; ?>>
								</td>
								<td>
									<label class="screen-reader-text" for="dgl-g-i-<?php echo (int) $i; ?>"><?php esc_html_e( 'Line under the heading', 'dgl-platform' ); ?></label>
									<input type="text" id="dgl-g-i-<?php echo (int) $i; ?>" name="group_intro[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $group['intro'] ); ?>" style="width:100%">
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'A new group can be given posts after it has been saved once.', 'dgl-platform' ); ?></p>

				<h2><?php esc_html_e( 'Posts', 'dgl-platform' ); ?></h2>
				<?php if ( [] === $items ) : ?>
					<p><?php esc_html_e( 'Nothing fetched yet. Use Fetch from Padlet now above.', 'dgl-platform' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:960px">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Post', 'dgl-platform' ); ?></th>
							<th scope="col" style="width:280px"><?php esc_html_e( 'Group', 'dgl-platform' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<?php
								$key     = (string) $item['key'];
								$current = $grouping['assign'][ $key ] ?? Board::DEFAULT_GROUP;
								?>
								<tr>
									<td>
										<label for="dgl-a-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( (string) $item['title'] ); ?></strong></label>
										<?php if ( ! isset( $grouping['assign'][ $key ] ) ) : ?>
											<span class="dgl-badge" style="margin-left:6px;padding:1px 8px;border-radius:10px;background:#fcf0c3;font-size:12px"><?php esc_html_e( 'New', 'dgl-platform' ); ?></span>
										<?php endif; ?>
										<br><a href="<?php echo esc_url( (string) $item['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $item['host'] ); ?></a>
									</td>
									<td>
										<select id="dgl-a-<?php echo esc_attr( $key ); ?>" name="assign[<?php echo esc_attr( $key ); ?>]">
											<?php foreach ( $choices as $value => $label ) : ?>
												<option value="<?php echo esc_attr( (string) $value ); ?>"<?php selected( $current, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php submit_button( __( 'Save groups', 'dgl-platform' ) ); ?>
			</form>

			<?php if ( [] !== $left_out ) : ?>
				<h2><?php esc_html_e( 'Left out', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'These posts are on the Padlet board but not shown here. To show one, it needs changing on Padlet: give it a title, or link it somewhere other than Amazon.', 'dgl-platform' ); ?></p>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Post', 'dgl-platform' ); ?></th>
						<th scope="col" style="width:280px"><?php esc_html_e( 'Why', 'dgl-platform' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $left_out as $post ) : ?>
							<tr>
								<td>
									<?php echo esc_html( '' !== (string) ( $post['title'] ?? '' ) ? (string) $post['title'] : __( '(no title)', 'dgl-platform' ) ); ?>
									<br><a href="<?php echo esc_url( (string) ( $post['link'] ?? '' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( Board::host_label( (string) ( $post['link'] ?? '' ) ) ); ?></a>
								</td>
								<td><?php echo esc_html( Board::LEFT_SHOP === ( $post['why'] ?? '' ) ? __( 'Links to Amazon', 'dgl-platform' ) : __( 'No title (a column introduction, or a link posted without one)', 'dgl-platform' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
