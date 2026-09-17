<?php
/**
 * Every meta key the plugin owns, in one place.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * Named constants rather than string literals scattered through the codebase,
 * so a rename is a single edit and a typo is a fatal rather than a silent null.
 */
final class Meta {

	/* User meta. */

	/** Organisation post ID the user belongs to. */
	public const USER_ORG = 'dgl_org_id';

	/** UserContext::ORG_OWNER or ORG_CONTRIBUTOR. */
	public const USER_ORG_ROLE = 'dgl_org_role';

	/** One of UserContext::ACCOUNT_*. */
	public const USER_ACCOUNT_STATUS = 'dgl_account_status';

	/* Organisation post meta. */

	/** Whether the DGLP team has verified the organisation. */
	public const ORG_STATUS = 'dgl_org_status';

	/** One of Org\Trust::*. */
	public const ORG_TRUST = 'dgl_trust_level';

	/** Wall-clock datetime of the next occurrence that has not finished; a one-off's start. Mirrored into the index. */
	public const ITEM_NEXT_AT = 'dgl_next_at';

	/** One row per email domain an organisation uses. Lower-case, exact. */
	public const ORG_DOMAIN = 'dgl_org_domain';

	/** '1' when the organisation has chosen to appear in the public directory. */
	public const ORG_IN_DIRECTORY = 'dgl_org_in_directory';

	/* Facts carried over from Forum Central's records by the import. Read-only in the member area. */
	public const ORG_FC_ID          = 'dgl_org_fc_id';
	public const ORG_FC_VOLITION    = 'dgl_org_fc_volition';
	public const ORG_FC_LOPF        = 'dgl_org_fc_lopf';
	public const ORG_FC_PERMISSION  = 'dgl_org_fc_permission';
	public const ORG_AGE_FRIENDLY   = 'dgl_org_age_friendly';
	public const ORG_LAT            = 'dgl_org_lat';
	public const ORG_LNG            = 'dgl_org_lng';
	public const ORG_IMPORTED_AT    = 'dgl_org_imported_at';

	/* Submission post meta. */

	/** Owning organisation post ID. Mirrored into the items index. */
	public const ITEM_ORG = 'dgl_org';

	/** UTC datetime the item last went to a moderator. */
	public const ITEM_SUBMITTED_AT = 'dgl_submitted_at';

	/** UTC datetime the item was last approved. */
	public const ITEM_APPROVED_AT = 'dgl_approved_at';

	/** UTC datetime the item comes off the listings, if it has an end date. */
	public const ITEM_EXPIRES_AT = 'dgl_expires_at';

	/** Post ID of the live item this revision would replace. */
	public const REVISION_TARGET = 'dgl_revision_target';

	/** Organisation verification states. */
	public const ORG_PENDING   = 'pending';
	public const ORG_APPROVED  = 'approved';
	public const ORG_SUSPENDED = 'suspended';
}
