<?php
/**
 * The Organisations screen in wp-admin.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Meta;
use DGL\Org\Org;
use DGL\Org\Profile;
use DGL\Org\Trust;
use DGL\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Verifying organisations, setting trust, and deciding name and logo changes.
 *
 * A member can ask to rename their organisation or change its logo, and until
 * now nothing anywhere could say yes. {@see Profile::approve_pending()} existed
 * and was tested, and no screen called it: a request went into a meta row and
 * sat there. This is the other end of that.
 *
 * Trust is deliberately administrator-only, not moderator. Raising it is the
 * one action that decides whether a whole organisation's future work skips
 * review, so it is not a decision to leave beside the approve button somebody
 * presses forty times a day.
 */
final class Organisations {

	private const NONCE = 'dgl_org_admin';

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'register' ] );
		add_action( 'save_post_' . PostTypes::ORG, [ self::class, 'save' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'notices' ] );

		add_filter( 'manage_edit-' . PostTypes::ORG . '_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostTypes::ORG . '_posts_custom_column', [ self::class, 'cell' ], 10, 2 );
	}

	public static function register(): void {
		add_meta_box(
			'dgl-org-status',
			__( 'Verification and trust', 'dgl-platform' ),
			[ self::class, 'render' ],
			PostTypes::ORG,
			'side',
			'high'
		);

		add_meta_box(
			'dgl-org-fields',
			__( 'Organisation details', 'dgl-platform' ),
			[ self::class, 'render_fields' ],
			PostTypes::ORG,
			'normal',
			'default'
		);

		add_meta_box(
			'dgl-org-pending',
			__( 'Requested change', 'dgl-platform' ),
			[ self::class, 'render_pending' ],
			PostTypes::ORG,
			'normal',
			'high'
		);
	}

	public static function render( WP_Post $post ): void {
		$org_id = (int) $post->ID;
		$status = Org::status( $org_id );
		$trust  = Trust::normalise( get_post_meta( $org_id, Meta::ORG_TRUST, true ) );
		$admin  = Access::current_user_can( Policy::GRANT_TRUST );

		wp_nonce_field( self::NONCE, self::NONCE );

		echo '<p><label for="dgl-org-status-field"><strong>' . esc_html__( 'Verification', 'dgl-platform' ) . '</strong></label><br />';
		echo '<select id="dgl-org-status-field" name="dgl_org_status" class="widefat">';

		foreach (
			[
				Meta::ORG_PENDING   => __( 'Pending. Members cannot submit yet.', 'dgl-platform' ),
				Meta::ORG_APPROVED  => __( 'Approved. Members can submit.', 'dgl-platform' ),
				Meta::ORG_SUSPENDED => __( 'Suspended. Members cannot submit or edit.', 'dgl-platform' ),
			] as $value => $label
		) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $status, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';

		echo '<p><label for="dgl-org-trust-field"><strong>' . esc_html__( 'Trust level', 'dgl-platform' ) . '</strong></label><br />';

		if ( ! $admin ) {
			echo esc_html( Trust::label( $trust ) ) . '<br />';
			echo '<span class="description">' . esc_html__( 'Only a site administrator can change this.', 'dgl-platform' ) . '</span></p>';
			return;
		}

		echo '<select id="dgl-org-trust-field" name="dgl_org_trust" class="widefat">';

		foreach ( Trust::all() as $level ) {
			printf(
				'<option value="%d" %s>%s</option>',
				$level,
				selected( $trust, $level, false ),
				esc_html( Trust::label( $level ) )
			);
		}

		echo '</select>';
		echo '<span class="description">' . esc_html( Trust::description( $trust ) ) . '</span></p>';

		echo '<p class="description">'
			. esc_html__( 'Trust is dropped back to moderated automatically if anything from this organisation is refused or taken down.', 'dgl-platform' )
			. '</p>';
	}

	/**
	 * The organisation's own profile fields.
	 *
	 * Read-only here. A member owns their profile and edits it in the member
	 * area; DGLP's job on this screen is verification, trust and deciding the
	 * two fields that need agreeing. An editable copy would be a second way to
	 * change the same data with different rules, which is how the name ends up
	 * bypassing the review it exists to have.
	 */
	public static function render_fields( WP_Post $post ): void {
		$org_id = (int) $post->ID;
		$values = Profile::values( $org_id );

		echo '<table class="widefat striped"><tbody>';

		foreach ( \DGL\Org\Schema::fields() as $field ) {
			if ( 'org_name' === $field->key ) {
				// That is the title box at the top of this screen.
				continue;
			}

			$value = $values[ $field->key ] ?? '';

			echo '<tr><th style="width:220px">' . esc_html( $field->label ) . '</th><td>'
				. self::value( $field->key, $value ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '</td></tr>';
		}

		$members = Org::members( $org_id );

		echo '<tr><th>' . esc_html__( 'People', 'dgl-platform' ) . '</th><td>';

		if ( [] === $members ) {
			echo '<em>' . esc_html__( 'Nobody is linked to this organisation yet.', 'dgl-platform' ) . '</em>';
		} else {
			$names = [];

			foreach ( $members as $user_id ) {
				$person = get_userdata( $user_id );

				if ( ! $person ) {
					continue;
				}

				$names[] = sprintf(
					'%s (%s)',
					$person->display_name,
					\DGL\Access\UserContext::ORG_OWNER === Org::role_for_user( $user_id )
						? __( 'owner', 'dgl-platform' )
						: __( 'can submit', 'dgl-platform' )
				);
			}

			echo esc_html( implode( ', ', $names ) );
		}

		echo '</td></tr>';

		/*
		 * Editable here and nowhere else. Whoever signs up with an address on
		 * one of these domains is offered this organisation, so the list is
		 * the team's to keep, not the member's.
		 */
		echo '<tr><th><label for="dgl-org-domains">' . esc_html__( 'Email domains', 'dgl-platform' ) . '</label></th><td>'
			. '<textarea id="dgl-org-domains" name="dgl_org_domains" rows="3" class="large-text code">'
			. esc_textarea( implode( "\n", Org::domains( $org_id ) ) )
			. '</textarea>'
			. '<p class="description">'
			. esc_html__( 'One per line, for example leedsmind.org.uk. Somebody who signs up with an email address on one of these is offered this organisation. Public providers such as gmail.com are ignored even if listed.', 'dgl-platform' )
			. '</p></td></tr>';

		echo '</tbody></table>';

		echo '<p class="description">'
			. esc_html__( 'The organisation edits these themselves in the member area. Changes to the name and the logo come to you first.', 'dgl-platform' )
			. '</p>';
	}

	/**
	 * The name or logo change waiting on a decision.
	 */
	public static function render_pending( WP_Post $post ): void {
		$org_id  = (int) $post->ID;
		$changes = Profile::pending_changes( $org_id );

		if ( [] === $changes ) {
			echo '<p>' . esc_html__( 'Nothing waiting. This organisation has not asked to change its name or logo.', 'dgl-platform' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'This organisation has asked to change the details below. Their listings carry the current values until you decide.', 'dgl-platform' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Field', 'dgl-platform' ) . '</th>'
			. '<th>' . esc_html__( 'On the site now', 'dgl-platform' ) . '</th>'
			. '<th>' . esc_html__( 'Proposed', 'dgl-platform' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $changes as $change ) {
			echo '<tr><td>' . esc_html( (string) $change['label'] ) . '</td>';
			echo '<td>' . self::value( (string) $change['key'], $change['before'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><strong>' . self::value( (string) $change['key'], $change['after'] ) . '</strong></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</tbody></table>';

		/*
		 * Controls that post with WordPress's own form, not a form of their own.
		 *
		 * A meta box sits inside <form id="post">. Nested forms are invalid, the
		 * browser silently drops the inner one, and the buttons end up
		 * submitting the outer form instead — the decision looks like it worked
		 * and nothing happened. This was built wrong twice before it was built
		 * right, so: no <form> tag anywhere inside a meta box, ever.
		 *
		 * Safe to hang off the main save here because an organisation has no
		 * custom post status for WordPress's publish box to trample.
		 */
		echo '<p style="margin-top:12px"><label for="dgl-org-decision"><strong>'
			. esc_html__( 'Your decision', 'dgl-platform' ) . '</strong></label><br />';
		echo '<select id="dgl-org-decision" name="dgl_org_decision">';
		echo '<option value="">' . esc_html__( 'Leave it waiting', 'dgl-platform' ) . '</option>';
		echo '<option value="approve">' . esc_html__( 'Accept the change', 'dgl-platform' ) . '</option>';
		echo '<option value="reject">' . esc_html__( 'Refuse it', 'dgl-platform' ) . '</option>';
		echo '</select></p>';

		echo '<p><label for="dgl-org-note">' . esc_html__( 'If you are refusing, say why. They will read it.', 'dgl-platform' ) . '</label><br />';
		echo '<textarea id="dgl-org-note" name="dgl_org_note" rows="2" class="large-text"></textarea></p>';

		echo '<p class="description">'
			. esc_html__( 'Choose a decision then press Update. Nothing changes until you do.', 'dgl-platform' )
			. '</p>';
	}

	/**
	 * One proposed value, rendered for its kind.
	 */
	private static function value( string $key, mixed $value ): string {
		if ( 'org_logo' === $key ) {
			$id = is_numeric( $value ) ? (int) $value : 0;

			return $id > 0
				? (string) wp_get_attachment_image( $id, 'thumbnail' )
				: '<em>' . esc_html__( 'None', 'dgl-platform' ) . '</em>';
		}

		return '' === (string) $value
			? '<em>' . esc_html__( 'None', 'dgl-platform' ) . '</em>'
			: esc_html( (string) $value );
	}

	/**
	 * Save verification and trust.
	 */
	public static function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::decide( $post_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['dgl_org_domains'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$domains = \DGL\Joining\Domains::list( sanitize_textarea_field( wp_unslash( $_POST['dgl_org_domains'] ) ) );
			$before  = Org::domains( $post_id );

			if ( $domains !== $before ) {
				Org::set_domains( $post_id, $domains );
				\DGL\Audit\Log::record( 'org_domains_changed', 'org', $post_id, $post_id, '', [ 'domains' => [ implode( ', ', $before ), implode( ', ', $domains ) ] ] );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = isset( $_POST['dgl_org_status'] ) ? sanitize_key( wp_unslash( $_POST['dgl_org_status'] ) ) : '';

		if ( in_array( $status, [ Meta::ORG_PENDING, Meta::ORG_APPROVED, Meta::ORG_SUSPENDED ], true ) ) {
			$before = Org::status( $post_id );

			if ( $before !== $status ) {
				update_post_meta( $post_id, Meta::ORG_STATUS, $status );

				\DGL\Audit\Log::record(
					'org_status_changed',
					'org',
					$post_id,
					$post_id,
					'',
					[ 'status' => [ $before, $status ] ]
				);
			}
		}

		/*
		 * Trust is administrator-only and checked here as well as hidden in the
		 * form. A control that is not rendered is not a permission check: the
		 * field can still be posted.
		 */
		if ( ! Access::current_user_can( Policy::GRANT_TRUST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$trust = isset( $_POST['dgl_org_trust'] ) ? Trust::normalise( wp_unslash( $_POST['dgl_org_trust'] ) ) : null;

		if ( null === $trust ) {
			return;
		}

		$before = Trust::normalise( get_post_meta( $post_id, Meta::ORG_TRUST, true ) );

		if ( $before === $trust ) {
			return;
		}

		update_post_meta( $post_id, Meta::ORG_TRUST, $trust );

		\DGL\Audit\Log::record(
			'trust_changed',
			'org',
			$post_id,
			$post_id,
			Trust::description( $trust ),
			[ 'trust' => [ $before, $trust ] ]
		);
	}

	/**
	 * Accept or refuse a requested name or logo change.
	 *
	 * Called from {@see self::save()}, because the controls post with
	 * WordPress's own form rather than one of their own.
	 */
	private static function decide( int $org_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() verified it.
		$decision = isset( $_POST['dgl_org_decision'] ) ? sanitize_key( wp_unslash( $_POST['dgl_org_decision'] ) ) : '';

		if ( ! in_array( $decision, [ 'approve', 'reject' ], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- save() verified it.
		$note = isset( $_POST['dgl_org_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_org_note'] ) ) : '';

		$result = 'reject' === $decision
			? Profile::reject_pending( $org_id, get_current_user_id(), $note )
			: Profile::approve_pending( $org_id, get_current_user_id() );

		set_transient(
			'dgl_org_decided_' . $org_id,
			is_wp_error( $result ) ? [ 'error', $result->get_error_message() ] : [ $decision, '' ],
			60
		);
	}

	/**
	 * Say what actually happened, and no more than that.
	 *
	 * Since 0.9.5 the organisation's owners are emailed on both decisions,
	 * from Profile::approve_pending() and reject_pending(), so the notice
	 * can say so.
	 */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || PostTypes::ORG !== $screen->post_type ) {
			return;
		}

		$org_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stored = $org_id > 0 ? get_transient( 'dgl_org_decided_' . $org_id ) : false;

		if ( ! is_array( $stored ) ) {
			return;
		}

		delete_transient( 'dgl_org_decided_' . $org_id );

		[ $decided, $message ] = $stored;

		if ( 'error' === $decided ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( '' !== $message ? $message : __( 'That could not be applied.', 'dgl-platform' ) )
				. '</p></div>';
			return;
		}

		$said = 'reject' === $decided
			? __( 'Refused. The organisation keeps its current details, and its owners have been emailed your reason.', 'dgl-platform' )
			: __( 'Accepted. The new details are now on every listing this organisation has posted, and its owners have been emailed.', 'dgl-platform' );

		echo '<div class="notice notice-success"><p>' . esc_html( $said ) . '</p></div>';
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = [];

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['dgl_status']  = __( 'Verification', 'dgl-platform' );
				$out['dgl_trust']   = __( 'Trust', 'dgl-platform' );
				$out['dgl_waiting'] = __( 'Requested change', 'dgl-platform' );
			}
		}

		return $out;
	}

	public static function cell( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'dgl_status':
				echo esc_html( ucfirst( Org::status( $post_id ) ) );
				return;

			case 'dgl_trust':
				echo esc_html( Trust::label( Trust::normalise( get_post_meta( $post_id, Meta::ORG_TRUST, true ) ) ) );
				return;

			case 'dgl_waiting':
				if ( ! Profile::has_pending( $post_id ) ) {
					echo '—';
					return;
				}

				printf(
					'<strong><a href="%s">%s</a></strong>',
					esc_url( (string) get_edit_post_link( $post_id ) ),
					esc_html__( 'Waiting on you', 'dgl-platform' )
				);
				return;
		}
	}
}
