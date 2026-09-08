<?php
/**
 * Plugin Name: AQM Reviews
 * Description: Stores client reviews in their own table, with an admin screen to maintain them and a shortcode to display them. No third-party service, no vendor cache, no API required. Converted from a must-use plugin on 8 Sep 2026 so it can update itself from GitHub releases like every other AQM plugin.
 * Version:     1.12.0
 * Author:      A. Q. Mufti
 * Plugin URI:  https://github.com/AQMufti/aqm-reviews
 * License:     GPL-2.0-or-later
 *
 * WHY THIS EXISTS
 * ===============
 *
 * Written 2 Sep 2026, to replace two review plugins and a WPCode snippet.
 *
 * The site showed "47 Google reviews" while Google's public listing said 49,
 * and had done for at least four days - probably months. Two plugins from
 * different vendors both said 47, because both display their own SERVER-SIDE
 * COLLECTED COPY of the reviews rather than reading Google. When that copy goes
 * stale there is nothing on the site that can correct it.
 *
 * The structural cause: Google's Places API returns a maximum of FIVE reviews.
 * So any plugin showing more than five is caching them on its own
 * infrastructure. You are always looking at a vendor's snapshot.
 *
 * This takes the opposite approach. The reviews live in AQ's own table, on
 * AQ's own server, editable by AQ. Nothing outside the site decides what it
 * shows or when it changes.
 *
 * THE VERIFIED FLAG, AND WHY IT MATTERS MORE THAN ANYTHING ELSE HERE
 * -----------------------------------------------------------------
 *
 * These are real quotes attributed to named clients on a public page. If a word
 * is wrong, the site misquotes a real person in advertising.
 *
 * The existing library in the toolkit (content/reviews.json) was gathered on
 * 30 Aug 2026 through a tool that passes a page through a summarising model.
 * That model can normalise wording without saying so. The text is therefore
 * PROBABLY accurate and NOT PROVABLY accurate, and those are different things.
 *
 * So every row carries `verified`, defaulting to 0, and **the front end renders
 * only verified rows.** Importing 24 reviews displays none of them until
 * someone has sat with the Google profile and confirmed each word for word.
 * That friction is the point: it is the only thing standing between a
 * transcription slip and a public misquote.
 *
 * WHY THE COUNT IS SEPARATE FROM THE ROWS
 * --------------------------------------
 *
 * The badge says "49 Google reviews" while the table may hold 24, because ~16
 * of the 49 are a star rating with no text and a few are excluded on judgement.
 * So the headline total and average are stored as profile settings read from
 * Google, and are NOT derived by counting rows. Counting rows would silently
 * understate the profile.
 *
 * MUST-USE PLUGIN NOTE
 * --------------------
 *
 * Activation hooks never fire for mu-plugins, so there is no install moment.
 * The schema is versioned in an option and checked on admin_init instead - see
 * aqm_rev_maybe_install().
 */

defined( 'ABSPATH' ) || exit;

/*
 * THE UPDATER IS CONSTRUCTED FIRST, DELIBERATELY.
 *
 * In 1.1.0 and 1.2.0 the conversion guard ran BEFORE this block and returned
 * early, so AQM_Updater was never constructed - which removed the "Check for
 * updates" link from this plugin's row and left no way to update it except a
 * manual zip upload. A guard that disables the thing that would have fixed the
 * guard is a trap. Registering the updater first costs nothing (it only adds
 * filters) and keeps the plugin repairable however badly the rest goes wrong.
 */
define( 'AQM_REV_FILE', __FILE__ );
define( 'AQM_REV_VERSION', '1.12.0' );
define( 'AQM_REV_GITHUB_REPO', 'AQMufti/aqm-reviews' );

// Shared GitHub-release updater - identical mechanism in every AQM plugin.
require_once __DIR__ . '/aqm-updater.php';
new AQM_Updater(
	__FILE__,
	AQM_REV_VERSION,
	AQM_REV_GITHUB_REPO,
	'AQM Reviews',
	'Client reviews in their own table, with an admin screen and the [aqm_reviews] shortcode.'
);

/*
 * THE CONVERSION GUARDS ARE GONE - 8 Sep 2026, and they are not coming back.
 *
 * This file carried two of them: one testing whether the old must-use copy was
 * still on disk, and one testing function_exists( 'aqm_rev_table' ) before
 * loading on. The second could NEVER be false, and it broke the plugin.
 *
 * PHP hoists unconditional top-level function and class declarations when a
 * file is included - they exist before the file's first statement runs. So by
 * the time that guard was evaluated, aqm_rev_table was already
 * defined BY THIS FILE, a few lines below. The guard returned every single
 * time, and nothing after it ever executed: no add_action, no add_shortcode,
 * no admin screen. The functions existed; none of them were ever hooked.
 *
 * That is why the RECO brokerage line was missing from the site, and why the
 * reviews stopped rendering, from the moment these plugins were converted.
 *
 * The file_exists() guard went too, because it cannot help either: if a
 * must-use copy declared these same symbols, PHP would fatal on the redeclare
 * as this file was included, long before any runtime check could return. The
 * only guard that would work is wrapping the whole file in
 * if ( ! function_exists( 'aqm_rev_table' ) ) - which is what AQM Form Spam
 * Guard does. The must-use copies are deleted and archived, so nothing here
 * needs guarding at all.
 */


// Now that this is a regular plugin it HAS an activation moment, so the table
// is created the instant it is activated rather than on the next admin page
// load. aqm_rev_maybe_install() is idempotent - it returns immediately once the
// stored schema version matches - so keeping the admin_init call below as well
// is harmless and still covers upgrades.
register_activation_hook( __FILE__, 'aqm_rev_maybe_install' );

// Bumped to run the status repair in aqm_rev_maybe_install(). Raise it whenever
// the table or its stored values need a one-off pass.
define( 'AQM_REV_SCHEMA', '1.0.1' );

/** The reviews table name, prefix and all. */
function aqm_rev_table() {
	global $wpdb;
	return $wpdb->prefix . 'aqm_reviews';
}

/** Profile-level facts, read from Google by hand. Not derived from the rows. */
function aqm_rev_profile() {
	$defaults = array(
		'url'           => 'https://www.google.com/maps/place/AQ+Mufti+Realty/',
		'place_id'      => 'ChIJWyI6Pm5BK4gRRbcI9DkiTmE',
		'total'         => 49,
		'average'       => '5.0',
		'heading'       => '',
		'avatar'        => '',
		'checked'       => '2026-09-02',
		'float'         => 0,
		'float_pos'     => 'bottom-left',
		'float_exclude' => '',
		// One number drives both placements; see the admin screen's
		// "Review cards" field. Kept as two keys because the corner and an
		// in-page badge are still rendered by different callers.
		'scroll_cards'  => 3,
		'scroll_speed'  => 6,
		'scroll_limit'  => 12,
		'scroll_words'  => 28,
		'float_cards'   => 3,
		// Box size, in pixels. 0 means "fit the content", which is what the
		// badge did before any of this was adjustable.
		'box_width'     => 0,
		'box_height'    => 0,
		'box_font'      => 15,
		'box_photo'     => 88,
		/*
		 * Which element the floating badge must never cover.
		 *
		 * The float is position:fixed, so it sits over whatever happens to be
		 * at the bottom of the viewport - and at the end of a page that is the
		 * footer. On 8 Sep 2026 it was covering the RECO brokerage
		 * identification line, which is a compliance problem, not a cosmetic
		 * one. A positioned element with a higher z-index paints OVER a fixed
		 * one, so raising the footer is enough; no JavaScript, no scroll
		 * listener, and nothing that can silently stop working.
		 *
		 * Empty disables the rule entirely.
		 */
		'footer_sel'    => 'footer, .elementor-location-footer, .site-footer, .aqm-brokerage-id',
		// 'solid' or 'transparent'. Solid is the default: a review sitting on
		// top of page content needs to be readable, and glass over arbitrary
		// content is not.
		'box_bg'        => 'solid',
		// Hide the floating bar once the footer is on screen. Costs a few lines
		// of JavaScript; see aqm_rev_float_hide_js() for why that is worth it.
		'float_hide'    => 1,
		/*
		 * MINIMUM distance from the corner, in pixels.
		 *
		 * 90 rather than 20 because a floating chat button - WhatsApp, Messenger,
		 * a live-chat bubble - sits in a bottom corner on most sites, and landing
		 * on top of one is the common case rather than the exception. The script
		 * measures whatever is actually there and raises this if it needs to, so
		 * this number is a floor, not the final value.
		 */
		'float_gap'     => 90,
	);
	$saved   = get_option( 'aqm_reviews_profile', array() );
	$profile = array_merge( $defaults, is_array( $saved ) ? $saved : array() );

	// Generic by design. With no heading set the badge uses the site's own
	// name, so the plugin carries no site-specific text and there is one
	// source of truth for how the business is named. Setting a heading here
	// overrides it.
	if ( '' === trim( (string) $profile['heading'] ) ) {
		$profile['heading'] = get_bloginfo( 'name' );
	}

	return $profile;
}

/**
 * Where "read all reviews on Google" should actually go.
 *
 * A /maps/place/ URL opens the map with the business pinned, and the reviews
 * are several taps away - which is not what a link promising reviews should
 * do. Google's own reviews view is keyed on Place ID, so use that whenever
 * one is set and fall back to the plain profile URL when it is not.
 */
function aqm_rev_reviews_url( $profile ) {
	$place = isset( $profile['place_id'] ) ? trim( (string) $profile['place_id'] ) : '';
	return $place
		? 'https://search.google.com/local/reviews?placeid=' . rawurlencode( $place )
		: $profile['url'];
}

/**
 * Create or upgrade the table.
 *
 * mu-plugins get no activation hook, so this runs on admin_init and does
 * nothing at all once the stored schema version matches.
 */
function aqm_rev_maybe_install() {

	if ( get_option( 'aqm_reviews_schema' ) === AQM_REV_SCHEMA ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = aqm_rev_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta is fussy: one field per line, two spaces after PRIMARY KEY, and
	// key names spelled identically every time.
	dbDelta(
		"CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		external_id varchar(191) NOT NULL DEFAULT '',
		reviewer varchar(191) NOT NULL DEFAULT '',
		attribution varchar(191) NOT NULL DEFAULT '',
		rating tinyint(1) unsigned NOT NULL DEFAULT 5,
		review_text longtext NOT NULL,
		posted_on date DEFAULT NULL,
		posted_label varchar(60) NOT NULL DEFAULT '',
		source varchar(40) NOT NULL DEFAULT 'google',
		status varchar(20) NOT NULL DEFAULT 'draft',
		verified tinyint(1) NOT NULL DEFAULT 0,
		verified_at datetime DEFAULT NULL,
		sort_order int(11) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY live (status,verified),
		KEY ordering (sort_order)
	) {$collate};"
	);

	// Repair, 3 Sep 2026. The bulk action sent the literal dropdown value, so
	// "Publish" wrote status = 'publish' while everything that READS the table
	// looks for 'published'. Those rows were live nowhere and displayed as
	// drafts. Harmless to run again: after the first pass it matches nothing.
	$wpdb->query( // phpcs:ignore WordPress.DB
		"UPDATE {$table} SET status = 'published' WHERE status = 'publish'" // phpcs:ignore WordPress.DB
	);

	update_option( 'aqm_reviews_schema', AQM_REV_SCHEMA, false );
}
add_action( 'admin_init', 'aqm_rev_maybe_install' );

/**
 * Fetch reviews.
 *
 * Defaults to what the public should see: published AND verified.
 *
 * @param array $args status ('published'|'draft'|'any'), verified (1|0|'any'), limit.
 */
function aqm_rev_get( $args = array() ) {

	global $wpdb;

	$a = wp_parse_args(
		$args,
		array(
			'status'   => 'published',
			'verified' => 1,
			'limit'    => 0,
		)
	);

	$table = aqm_rev_table();
	$where = array( '1=1' );
	$vals  = array();

	if ( 'any' !== $a['status'] ) {
		$where[] = 'status = %s';
		$vals[]  = $a['status'];
	}
	if ( 'any' !== $a['verified'] ) {
		$where[] = 'verified = %d';
		$vals[]  = (int) $a['verified'];
	}

	$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
		. ' ORDER BY sort_order ASC, posted_on DESC, id DESC';

	if ( (int) $a['limit'] > 0 ) {
		$sql   .= ' LIMIT %d';
		$vals[] = (int) $a['limit'];
	}

	// prepare() refuses an empty argument list, so branch rather than pass one.
	return $vals
		? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) )
		: $wpdb->get_results( $sql );
}

/** How many rows match. */
function aqm_rev_count( $status = 'any', $verified = 'any' ) {
	return count( aqm_rev_get( array( 'status' => $status, 'verified' => $verified ) ) );
}

/* =====================================================================
 * ADMIN
 * ================================================================== */

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			'AQM Reviews',
			'AQM Reviews',
			'manage_options',
			'aqm-reviews',
			'aqm_rev_screen',
			'dashicons-star-filled',
			26
		);
	}
);

/** Router for the admin page. */
function aqm_rev_screen() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}

	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

	echo '<div class="wrap"><h1 class="wp-heading-inline">AQM Reviews</h1>';

	if ( 'list' === $view ) {
		printf(
			' <a href="%s" class="page-title-action">Add review</a>'
			. ' <a href="%s" class="page-title-action">Import</a>'
			. ' <a href="%s" class="page-title-action">Settings</a>',
			esc_url( admin_url( 'admin.php?page=aqm-reviews&view=edit' ) ),
			esc_url( admin_url( 'admin.php?page=aqm-reviews&view=import' ) ),
			esc_url( admin_url( 'admin.php?page=aqm-reviews&view=profile' ) )
		);
	} else {
		printf(
			' <a href="%s" class="page-title-action">&larr; All reviews</a>',
			esc_url( admin_url( 'admin.php?page=aqm-reviews' ) )
		);
	}

	echo '<hr class="wp-header-end">';

	if ( isset( $_GET['msg'] ) ) {

		$key = sanitize_key( wp_unslash( $_GET['msg'] ) );

		if ( 'imported' === $key ) {

			$added   = isset( $_GET['added'] ) ? absint( $_GET['added'] ) : 0;
			$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
			$wiped   = isset( $_GET['wiped'] ) ? absint( $_GET['wiped'] ) : 0;

			$line = sprintf( '<strong>%d</strong> review%s imported.', $added, 1 === $added ? '' : 's' );
			if ( $wiped ) {
				$line = sprintf( '<strong>%d</strong> existing row%s deleted. ', $wiped, 1 === $wiped ? '' : 's' ) . $line;
			}
			if ( $skipped ) {
				$line .= sprintf(
					' %d skipped as already imported (matched on their own id, never on name).',
					$skipped
				);
			}
			$line .= ' Everything imported is an <strong>unverified draft</strong> and will '
				. 'not appear on the site until you check it and tick Verified.';

			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $line ); // phpcs:ignore WordPress.Security.EscapeOutput

		} elseif ( 'bulk' === $key ) {

			$n    = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
			$what = isset( $_GET['bulk'] ) ? sanitize_key( wp_unslash( $_GET['bulk'] ) ) : '';

			// A bulk action that could not run says why. Silence here is what
			// let the duplicate-dropdown bug go unnoticed.
			if ( 'nochoice' === $what || 'norows' === $what ) {

				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					'nochoice' === $what // phpcs:ignore WordPress.Security.EscapeOutput
						? 'Nothing happened &mdash; no bulk action was chosen. Pick one from the '
							. '<strong>Bulk actions</strong> dropdown beside the Apply button you used.'
						: 'Nothing happened &mdash; no reviews were ticked. Tick the boxes on the '
							. 'left, or the box in the table header to take everything in this view.'
				);

			} else {

				$verb = array(
					'delete'   => 'deleted',
					'publish'  => 'published',
					'draft'    => 'moved to draft',
					'verify'   => 'marked verified',
					'unverify' => 'marked not verified, and hidden from the site again',
				);
				printf(
					'<div class="notice notice-success is-dismissible"><p>%d review%s %s.</p></div>',
					$n,
					1 === $n ? '' : 's',
					esc_html( isset( $verb[ $what ] ) ? $verb[ $what ] : 'updated' )
				);
			}

		} else {

			$messages = array(
				'saved'   => 'Review saved.',
				'deleted' => 'Review deleted.',
				'profile' => 'Settings saved.',
			);
			if ( isset( $messages[ $key ] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $messages[ $key ] )
				);
			}
		}
	}

	switch ( $view ) {
		case 'edit':
			aqm_rev_screen_edit();
			break;
		case 'import':
			aqm_rev_screen_import();
			break;
		case 'profile':
			aqm_rev_screen_profile();
			break;
		default:
			aqm_rev_screen_list();
	}

	echo '</div>';
}

function aqm_rev_screen_list() {

	$profile = aqm_rev_profile();
	$filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';

	$total     = aqm_rev_count( 'any', 'any' );
	$live      = aqm_rev_count( 'published', 1 );
	$unchecked = aqm_rev_count( 'any', 0 );
	$checked   = aqm_rev_count( 'any', 1 );
	$drafts    = aqm_rev_count( 'draft', 'any' );

	// Filters exist for the multi-sitting workflow: verify a batch, come back
	// to "Not checked" tomorrow, and the list is already the work that is left.
	$views = array(
		'all'       => array( 'All', $total, 'any', 'any' ),
		'unchecked' => array( 'Not checked', $unchecked, 'any', 0 ),
		'checked'   => array( 'Verified', $checked, 'any', 1 ),
		'live'      => array( 'Showing on the site', $live, 'published', 1 ),
		'draft'     => array( 'Drafts', $drafts, 'draft', 'any' ),
	);

	if ( ! isset( $views[ $filter ] ) ) {
		$filter = 'all';
	}

	list( , , $f_status, $f_verified ) = $views[ $filter ];
	$rows = aqm_rev_get(
		array(
			'status'   => $f_status,
			'verified' => $f_verified,
		)
	);

	printf(
		'<p><strong>%d</strong> stored &middot; <strong>%d</strong> showing on the site '
		. '(published and verified) &middot; <strong>%d</strong> awaiting verification.'
		. '<br>Badge reads <strong>%s</strong> from <strong>%d</strong> Google reviews, '
		. 'last checked %s.</p>',
		$total,
		$live,
		$unchecked,
		esc_html( $profile['average'] ),
		(int) $profile['total'],
		esc_html( $profile['checked'] )
	);

	$links = array();
	foreach ( $views as $key => $view ) {
		$links[] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'admin.php?page=aqm-reviews&filter=' . $key ) ),
			$key === $filter ? ' class="current" style="font-weight:600"' : '',
			esc_html( $view[0] ),
			(int) $view[1]
		);
	}
	echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) // phpcs:ignore WordPress.Security.EscapeOutput
		. '</li></ul><br class="clear">';

	// Raw values, straight from the column. Added 3 Sep 2026: Publish appeared to
	// do nothing twice, and each time the argument was about what the screen
	// SAID rather than what the table held. This ends that - if a row is stored
	// as anything other than 'published' or 'draft', it is named here.
	global $wpdb;
	$raw = $wpdb->get_results( // phpcs:ignore WordPress.DB
		'SELECT status, verified, COUNT(*) AS n FROM ' . aqm_rev_table()
		. ' GROUP BY status, verified ORDER BY status, verified' // phpcs:ignore WordPress.DB
	);
	if ( $raw ) {
		$bits = array();
		$odd  = false;
		foreach ( $raw as $r ) {
			if ( ! in_array( $r->status, array( 'published', 'draft' ), true ) ) {
				$odd = true;
			}
			$bits[] = sprintf(
				'<code>%s</code> + %s &times; <strong>%d</strong>',
				esc_html( $r->status ),
				$r->verified ? 'verified' : 'not verified',
				(int) $r->n
			);
		}
		printf(
			'<div class="notice notice-%s inline"><p><strong>Stored values:</strong> %s%s</p></div>',
			$odd ? 'error' : 'info',
			implode( ' &middot; ', $bits ), // phpcs:ignore WordPress.Security.EscapeOutput
			$odd
				? '<br>A status other than <code>published</code> or <code>draft</code> is a bug. '
					. 'Send this line to Claude.'
				: '<br>Only <code>published</code> + verified rows appear on the site.'
		);
	}

	if ( $unchecked ) {
		echo '<div class="notice notice-warning"><p><strong>Unverified reviews are hidden '
			. 'from the site.</strong> Compare each one word for word with the review on '
			. 'Google before ticking Verified. These are real people quoted in advertising '
			. '&mdash; a near-enough quote is a misquote.</p></div>';
	}

	if ( ! $rows ) {
		echo '<p>' . ( $total
			? 'Nothing in this view.'
			: 'No reviews stored yet. Use <em>Import</em> or <em>Add review</em>.' )
			. '</p>';
		return;
	}

	/**
	 * One bulk-action bar.
	 *
	 * The bar is drawn twice, above and below the table, and the two copies MUST
	 * NOT share a field name. They did until 3 Sep 2026, and bulk actions
	 * silently did nothing: the browser submits BOTH dropdowns, PHP keeps the
	 * LAST value for a repeated name, and the lower dropdown was always still on
	 * "Bulk actions" - so the handler saw an empty choice and returned quietly.
	 * The top dropdown appeared to be ignored, because it was.
	 *
	 * Hence the suffix, on both the name and the id. This is exactly why core
	 * calls its own pair `action` and `action2`.
	 *
	 * @param string $pos 'top' or 'bottom'.
	 */
	$bulk_nav = function ( $pos ) use ( $rows ) {
		$sfx = ( 'bottom' === $pos ) ? '2' : '';
		return '<div class="tablenav ' . $pos . '"><div class="alignleft actions bulkactions">'
			. '<label class="screen-reader-text" for="aqm_rev_bulk' . $sfx . '">Bulk action</label>'
			. '<select name="bulk' . $sfx . '" id="aqm_rev_bulk' . $sfx . '">'
			. '<option value="">Bulk actions</option>'
			. '<option value="verify">Mark verified</option>'
			. '<option value="unverify">Mark NOT verified</option>'
			. '<option value="publish">Publish</option>'
			. '<option value="draft">Move to draft</option>'
			. '<option value="delete">Delete permanently</option>'
			. '</select> '
			. '<input type="submit" class="button action" value="Apply">'
			. '</div><div class="tablenav-pages"><span class="displaying-num">'
			. count( $rows ) . ' item' . ( 1 === count( $rows ) ? '' : 's' )
			. '</span></div><br class="clear"></div>';
	};

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aqm_rev_bulk' );
	echo '<input type="hidden" name="action" value="aqm_rev_bulk">';
	// The current filter rides along, so verifying a batch from "Not checked"
	// returns to "Not checked" with the batch gone, instead of dumping you back
	// on the full list to find your place again.
	echo '<input type="hidden" name="filter" value="' . esc_attr( $filter ) . '">';
	echo $bulk_nav( 'top' ); // phpcs:ignore WordPress.Security.EscapeOutput

	echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
		. '<td class="manage-column column-cb check-column"><input type="checkbox" '
		. 'onclick="var c=this.checked;this.closest(\'table\').querySelectorAll('
		. '\'tbody input[type=checkbox]\').forEach(function(b){b.checked=c;});"></td>'
		. '<th style="width:60px">Order</th><th>Reviewer</th>'
		. '<th style="width:80px">Rating</th><th style="width:120px">Posted</th>'
		. '<th style="width:100px">Status</th><th style="width:110px">Verified</th>'
		. '<th>Opening words</th></tr></thead><tbody>';

	foreach ( $rows as $row ) {

		$edit   = admin_url( 'admin.php?page=aqm-reviews&view=edit&id=' . (int) $row->id );
		$delete = wp_nonce_url(
			admin_url( 'admin-post.php?action=aqm_rev_delete&id=' . (int) $row->id ),
			'aqm_rev_delete_' . (int) $row->id
		);

		// A rating with no words is a real review. Say so, rather than showing a
		// blank cell that reads like missing data.
		$excerpt = '' === trim( $row->review_text )
			? '<em style="color:#666">rating only, no text</em>'
			: esc_html( wp_trim_words( $row->review_text, 12, '...' ) );

		printf(
			'<tr><th scope="row" class="check-column">'
			. '<input type="checkbox" name="ids[]" value="%d"></th>'
			. '<td>%d</td>'
			. '<td><strong><a href="%s">%s</a></strong>'
			. '<div class="row-actions"><span class="edit"><a href="%s">Edit</a> | </span>'
			. '<span class="delete"><a href="%s" style="color:#b32d2e">Delete</a></span></div></td>'
			. '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			(int) $row->id,
			(int) $row->sort_order,
			esc_url( $edit ),
			esc_html( $row->reviewer ? $row->reviewer : '(no name)' ),
			esc_url( $edit ),
			esc_url( $delete ),
			esc_html( str_repeat( "\xE2\x98\x85", (int) $row->rating ) ),
			esc_html( $row->posted_label ? $row->posted_label : (string) $row->posted_on ),
			'published' === $row->status
				? '<span style="color:#1a7f37">Published</span>'
				: '<span style="color:#8a6d3b">Draft</span>',
			$row->verified
				? '<span style="color:#1a7f37">Verified</span>'
				: '<strong style="color:#b32d2e">Not checked</strong>',
			$excerpt // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	echo '</tbody></table>';
	echo $bulk_nav( 'bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</form>';

	echo '<p class="description"><strong>Verifying across several sittings:</strong> tick '
		. 'the header box to select everything in the current view, mark it verified, then '
		. 'un-tick the ones you have not actually reached yet and mark those NOT verified. '
		. 'Or work the other way &mdash; filter to <em>Not checked</em>, do a batch, and it '
		. 'is the only list you see next time. Un-verifying hides a review from the site '
		. 'again immediately, so nothing goes public while it is still in the pile.</p>';
}

function aqm_rev_screen_edit() {

	global $wpdb;

	$id  = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
	$row = null;

	if ( $id ) {
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . aqm_rev_table() . ' WHERE id = %d', $id )
		);
	}

	$val = function ( $field, $default = '' ) use ( $row ) {
		return ( $row && isset( $row->$field ) && null !== $row->$field ) ? $row->$field : $default;
	};

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aqm_rev_save' );
	echo '<input type="hidden" name="action" value="aqm_rev_save">';
	printf( '<input type="hidden" name="id" value="%d">', $id );

	echo '<table class="form-table"><tbody>';

	printf(
		'<tr><th><label for="reviewer">Reviewer name</label></th><td>'
		. '<input name="reviewer" id="reviewer" type="text" class="regular-text" value="%s" required>'
		. '<p class="description">Exactly as posted publicly on Google. Only letter case '
		. 'is ever normalised.</p></td></tr>',
		esc_attr( $val( 'reviewer' ) )
	);

	printf(
		'<tr><th><label for="attribution">Shown as</label></th><td>'
		. '<input name="attribution" id="attribution" type="text" class="regular-text" value="%s">'
		. '<p class="description">Leave blank to use the reviewer name. Shorten to initials '
		. 'if a client asks.</p></td></tr>',
		esc_attr( $val( 'attribution' ) )
	);

	echo '<tr><th><label for="rating">Rating</label></th><td><select name="rating" id="rating">';
	for ( $i = 5; $i >= 1; $i-- ) {
		printf(
			'<option value="%d"%s>%d star%s</option>',
			$i,
			selected( (int) $val( 'rating', 5 ), $i, false ),
			$i,
			1 === $i ? '' : 's'
		);
	}
	echo '</select><p class="description">Record what they actually gave. Showing a four '
		. 'as a five on a credibility graphic is a small lie.</p></td></tr>';

	printf(
		'<tr><th><label for="posted_on">Date posted</label></th><td>'
		. '<input name="posted_on" id="posted_on" type="date" value="%s"> '
		. '<input name="posted_label" type="text" class="regular-text" value="%s" '
		. 'placeholder="or a label such as 2 years ago">'
		. '<p class="description">Google often shows only a relative date. Either is fine; '
		. 'the label wins when both are set.</p></td></tr>',
		esc_attr( $val( 'posted_on' ) ),
		esc_attr( $val( 'posted_label' ) )
	);

	printf(
		'<tr><th><label for="review_text">Review</label></th><td>'
		. '<textarea name="review_text" id="review_text" rows="10" class="large-text">%s</textarea>'
		. '<p class="description">The reviewer&rsquo;s words, exactly as Google shows them, '
		. 'typos and all. Never tidied. <strong>Leave blank for a rating-only review</strong> '
		. '&mdash; roughly a third of the profile is exactly that, and those count.</p>'
		. '</td></tr>',
		esc_textarea( $val( 'review_text' ) )
	);

	printf(
		'<tr><th>Verified</th><td><label><input type="checkbox" name="verified" value="1"%s> '
		. 'I have compared this against the review on Google, word for word</label>'
		. '<p class="description"><strong>The site shows only verified reviews.</strong> '
		. 'This is not a formality &mdash; it is the check that stops a transcription slip '
		. 'becoming a public misquote.</p></td></tr>',
		checked( (int) $val( 'verified', 0 ), 1, false )
	);

	echo '<tr><th>Status</th><td><select name="status">';
	foreach ( array(
		'published' => 'Published',
		'draft'     => 'Draft',
	) as $key => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $key ),
			selected( $val( 'status', 'draft' ), $key, false ),
			esc_html( $label )
		);
	}
	echo '</select></td></tr>';

	printf(
		'<tr><th><label for="sort_order">Order</label></th><td>'
		. '<input name="sort_order" id="sort_order" type="number" value="%d" class="small-text">'
		. '<p class="description">Lower shows first. Ties fall back to date.</p></td></tr>',
		(int) $val( 'sort_order', 0 )
	);

	echo '</tbody></table>';

	submit_button( $id ? 'Update review' : 'Add review' );
	echo '</form>';

	if ( $id ) {
		$delete = wp_nonce_url(
			admin_url( 'admin-post.php?action=aqm_rev_delete&id=' . $id ),
			'aqm_rev_delete_' . $id
		);
		printf(
			'<p><a href="%s" style="color:#b32d2e">Delete this review</a></p>',
			esc_url( $delete )
		);
	}
}

function aqm_rev_screen_import() {

	$template = wp_nonce_url(
		admin_url( 'admin-post.php?action=aqm_rev_template' ),
		'aqm_rev_template'
	);

	echo '<h2>Import</h2>'
		. '<p>Upload a <strong>CSV</strong>, <strong>Excel (.xlsx)</strong> or '
		. '<strong>JSON</strong> file, or paste JSON below.</p>';

	printf(
		'<p><a href="%s" class="button">Download the CSV template</a> &nbsp;'
		. '<span class="description">Headed and ready to fill in. Excel opens it '
		. 'directly; save as .xlsx or .csv, either uploads.</span></p>',
		esc_url( $template )
	);

	echo '<p><strong>Columns recognised</strong> (header row, any order, case and '
		. 'punctuation ignored): <code>Reviewer</code>, <code>Rating</code>, '
		. '<code>Review</code>, <code>Date</code>, <code>When</code>, '
		. '<code>Attribution</code>. Only <em>Reviewer</em> is required &mdash; a row '
		. 'with a name and a rating but no words is a valid review and will be stored '
		. 'as one.</p>';

	echo '<div class="notice notice-warning inline"><p>Everything imported arrives '
		. '<strong>unverified and as a draft</strong>, and will not appear on the site '
		. 'until you check it and tick Verified.</p>'
		. '<p><strong>Nothing is skipped or merged.</strong> Two people with the same '
		. 'name are two reviews; the same client reviewing twice is two reviews. The '
		. 'only rows ignored are ones this exact file has already brought in before, '
		. 'matched on their own id. So importing the same file twice is safe, but '
		. 'importing overlapping files is not &mdash; delete any duplicate from the '
		. 'list afterwards.</p></div>';

	echo '<form method="post" enctype="multipart/form-data" action="'
		. esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aqm_rev_import' );
	echo '<input type="hidden" name="action" value="aqm_rev_import">';

	$stored = aqm_rev_count( 'any', 'any' );

	echo '<table class="form-table"><tbody>'

		. '<tr><th>Mode</th><td>'
		. '<label><input type="radio" name="mode" value="append" checked> '
		. '<strong>Append</strong> &mdash; add these to what is already stored</label><br>'
		. '<label><input type="radio" name="mode" value="replace"> '
		. '<strong>Replace</strong> &mdash; delete everything first, then import</label>'
		. '<p class="description">Append is almost always what you want.</p>'
		. '<p><label><input type="checkbox" name="confirm_replace" value="1"> '
		. 'Yes, I mean Replace: <strong>permanently delete the '
		. (int) $stored . ' stored review' . ( 1 === $stored ? '' : 's' )
		. '</strong>, including any already verified</label></p>'
		. '<p class="description">Replace does nothing unless this is ticked. There is '
		. 'no undo &mdash; verification work is lost with the rows.</p>'
		. '</td></tr>'

		. '<tr><th><label for="aqm_rev_file">Upload a file</label></th><td>'
		. '<input type="file" name="payload_file" id="aqm_rev_file" '
		. 'accept=".csv,.xlsx,.json,text/csv,application/json,'
		. 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">'
		. '<p class="description">.csv, .xlsx or .json, up to 2&nbsp;MB.</p></td></tr>'
		. '<tr><th><label for="aqm_rev_paste">or paste JSON</label></th><td>'
		. '<textarea name="payload" id="aqm_rev_paste" rows="12" class="large-text code" '
		. 'spellcheck="false"></textarea>'
		. '<p class="description">Ignored if a file is chosen above.</p></td></tr>'
		. '</tbody></table>';

	submit_button( 'Import' );
	echo '</form>';
}

/**
 * A blank CSV with the right headers, so nobody has to guess the column names.
 */
function aqm_rev_send_template() {

	check_admin_referer( 'aqm_rev_template' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=aqm-reviews-template.csv' );

	$out = fopen( 'php://output', 'w' );

	// A UTF-8 BOM, so Excel opens accented names correctly instead of as mojibake.
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, array( 'Id', 'Reviewer', 'Rating', 'Review', 'Date', 'When', 'Attribution' ) );
	fputcsv(
		$out,
		array(
			'rev-jane-s',
			'Jane Smith',
			'5',
			'Paste the review exactly as Google shows it, typos and all.',
			'2026-04-18',
			'4 months ago',
			'Jane S.',
		)
	);
	fputcsv(
		$out,
		array( '', 'A rating with no words is fine', '5', '', '', '2 years ago', '' )
	);
	fputcsv(
		$out,
		array(
			'',
			'Id is optional but useful',
			'5',
			'Give a row an Id and re-importing the same file will not duplicate it. '
			. 'Leave Id blank and the row is always added - which is what you want for '
			. 'a new review, and never what you want for one already stored.',
			'',
			'',
			'',
		)
	);

	fclose( $out );
	exit;
}
add_action( 'admin_post_aqm_rev_template', 'aqm_rev_send_template' );

/** Spreadsheet column letters to a zero-based index: A=0, B=1, AA=26. */
function aqm_rev_col_index( $letters ) {
	$n       = 0;
	$letters = strtoupper( preg_replace( '/[^A-Za-z]/', '', $letters ) );
	$len     = strlen( $letters );
	for ( $i = 0; $i < $len; $i++ ) {
		$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
	}
	return $n - 1;
}

/**
 * Read the first worksheet of an .xlsx into rows of strings.
 *
 * An .xlsx is a zip of XML, so this needs no library - just ZipArchive and
 * SimpleXML, both of which are ordinarily present. Text usually lives in a
 * shared-strings table with the cells referring to it by index, which is why
 * that file is read first.
 *
 * @return array|WP_Error
 */
function aqm_rev_parse_xlsx( $path ) {

	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error(
			'aqm_no_zip',
			'This server has no ZipArchive extension, so .xlsx cannot be read here. '
			. 'Save the sheet as CSV and upload that instead - it imports identically.'
		);
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'aqm_bad_xlsx', 'That .xlsx could not be opened.' );
	}

	$shared = array();
	$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( $ss ) {
		$xml = simplexml_load_string( $ss );
		if ( $xml ) {
			foreach ( $xml->si as $si ) {
				// Rich text splits one string across several <t> runs.
				$text = '';
				foreach ( $si->xpath( './/*[local-name()="t"]' ) as $t ) {
					$text .= (string) $t;
				}
				$shared[] = $text;
			}
		}
	}

	$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();

	if ( ! $sheet ) {
		return new WP_Error(
			'aqm_bad_xlsx',
			'No first worksheet found in that .xlsx. Only the first sheet is read.'
		);
	}

	$xml = simplexml_load_string( $sheet );
	if ( ! $xml || ! isset( $xml->sheetData ) ) {
		return new WP_Error( 'aqm_bad_xlsx', 'That .xlsx could not be parsed.' );
	}

	$rows = array();

	foreach ( $xml->sheetData->row as $r ) {

		$cells = array();

		foreach ( $r->c as $c ) {

			$index = aqm_rev_col_index( (string) $c['r'] );
			$type  = (string) $c['t'];
			$value = '';

			if ( 'inlineStr' === $type ) {
				foreach ( $c->xpath( './/*[local-name()="t"]' ) as $t ) {
					$value .= (string) $t;
				}
			} elseif ( 's' === $type ) {
				$i     = (int) $c->v;
				$value = isset( $shared[ $i ] ) ? $shared[ $i ] : '';
			} else {
				$value = (string) $c->v;
			}

			$cells[ $index ] = $value;
		}

		// Excel omits empty cells entirely, so fill the gaps or the columns shift.
		if ( $cells ) {
			$max   = max( array_keys( $cells ) );
			$dense = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$dense[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$rows[] = $dense;
		} else {
			$rows[] = array();
		}
	}

	return $rows;
}

/** Match a header row to the fields we store. Forgiving about wording. */
function aqm_rev_map_header( $cells ) {

	$map = array();

	foreach ( $cells as $i => $label ) {

		$key = strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', ' ', (string) $label ) ) );
		$key = preg_replace( '/\s+/', ' ', $key );

		switch ( $key ) {
			case 'id':
			case 'review id':
			case 'external id':
			case 'ref':
				$map['id'] = $i;
				break;
			case 'reviewer':
			case 'name':
			case 'reviewer name':
			case 'client':
			case 'author':
				$map['reviewer'] = $i;
				break;
			case 'attribution':
			case 'shown as':
			case 'display name':
				$map['attribution'] = $i;
				break;
			case 'rating':
			case 'stars':
			case 'score':
				$map['rating'] = $i;
				break;
			case 'review':
			case 'review text':
			case 'text':
			case 'raw':
			case 'comment':
			case 'body':
				$map['review_text'] = $i;
				break;
			case 'date':
			case 'posted':
			case 'posted on':
			case 'date posted':
				$map['posted_on'] = $i;
				break;
			case 'when':
			case 'posted label':
			case 'relative date':
			case 'label':
				$map['posted_label'] = $i;
				break;
		}
	}

	return $map;
}

/** Turn header-plus-rows into the same shape the JSON importer produces. */
function aqm_rev_rows_to_items( $rows ) {

	// Skip leading blank lines, then treat the first row with content as headers.
	$header = array();
	while ( $rows ) {
		$candidate = array_shift( $rows );
		if ( array_filter( array_map( 'trim', (array) $candidate ) ) ) {
			$header = $candidate;
			break;
		}
	}

	$map = aqm_rev_map_header( $header );

	if ( ! isset( $map['reviewer'] ) ) {
		return new WP_Error(
			'aqm_no_header',
			'No "Reviewer" column found in the header row. Download the CSV template '
			. 'to see the expected columns - the header must be the first row with '
			. 'anything in it.'
		);
	}

	$items = array();

	foreach ( $rows as $row ) {

		$row = (array) $row;
		if ( ! array_filter( array_map( 'trim', $row ) ) ) {
			continue;   // wholly blank line
		}

		$get = function ( $field ) use ( $row, $map ) {
			return isset( $map[ $field ], $row[ $map[ $field ] ] )
				? trim( (string) $row[ $map[ $field ] ] )
				: '';
		};

		$items[] = array(
			'id'              => $get( 'id' ),
			'reviewer'        => $get( 'reviewer' ),
			'attribution'     => $get( 'attribution' ),
			'rating'          => $get( 'rating' ),
			'review_text'     => $get( 'review_text' ),
			'posted_on'       => $get( 'posted_on' ),
			'posted_relative' => $get( 'posted_label' ),
		);
	}

	return $items;
}

function aqm_rev_screen_profile() {

	$profile = aqm_rev_profile();

	echo '<h2>Settings</h2>'
		. '<p>The headline figures on the badge. Read these from your Google listing '
		. '<strong>while logged out</strong> &mdash; the owner dashboard and the public '
		. 'listing can differ. They are deliberately <em>not</em> counted from the stored '
		. 'rows, because most of your reviews are a star rating with no text and would be '
		. 'missed.</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'aqm_rev_profile' );
	echo '<input type="hidden" name="action" value="aqm_rev_profile">';
	echo '<table class="form-table"><tbody>';
	printf(
		'<tr><th>Total reviews on Google</th><td><input name="total" type="number" value="%d" class="small-text"></td></tr>',
		(int) $profile['total']
	);
	printf(
		'<tr><th>Average rating</th><td><input name="average" type="text" value="%s" class="small-text"></td></tr>',
		esc_attr( $profile['average'] )
	);
	$saved_raw     = get_option( 'aqm_reviews_profile', array() );
	$saved_heading = is_array( $saved_raw ) && isset( $saved_raw['heading'] ) ? (string) $saved_raw['heading'] : '';
	printf(
		'<tr><th>Heading</th><td><input name="heading" type="text" value="%s" placeholder="%s" class="large-text">'
			. '<p class="description">Leave empty to use the Site Title (%s).</p></td></tr>',
		esc_attr( $saved_heading ),
		esc_attr( get_bloginfo( 'name' ) ),
		esc_html( get_bloginfo( 'name' ) )
	);
	// Show which file the badge actually serves. If this still says the original
	// upload, the URL is not a Media Library file this site can resize, and the
	// visitor is downloading the full-size picture to draw an 88px thumbnail.
	$face_note = '';
	$face_img  = aqm_rev_avatar_src( $profile );
	if ( $face_img ) {
		$face_note = '<p><img src="' . esc_url( $face_img['url'] ) . '" alt="" '
			. 'style="height:88px;width:auto;border:1px solid #ddd"></p>'
			. '<p class="description">Served to visitors: <code>'
			. esc_html( wp_basename( $face_img['url'] ) ) . '</code>'
			. ( $face_img['w'] ? ' &mdash; ' . (int) $face_img['w'] . '&times;' . (int) $face_img['h'] . 'px' : '' )
			. ( $face_img['url'] === $profile['avatar']
				? '<br><strong>This is the original upload.</strong> Re-copy the URL from '
					. '<strong>Media</strong> so a smaller copy can be used &mdash; a full-size '
					. 'photo makes the badge slow to appear on a visitor&rsquo;s first page view.'
				: '' )
			. '</p>';
	}

	printf(
		'<tr><th>Headshot URL</th><td><input name="avatar" type="url" value="%s" class="large-text">'
		. '<p class="description">Upload your headshot to <strong>Media</strong>, copy the file '
		. 'URL, paste it here. The whole picture is shown, at its own proportions &mdash; '
		. 'nothing is cropped. Leave blank and the badge shows your initials instead '
		. '&mdash; which is tidy, but a face is why anyone trusts the box.</p>'
		. '%s</td></tr>',
		esc_attr( $profile['avatar'] ),
		$face_note // phpcs:ignore WordPress.Security.EscapeOutput
	);
	printf(
		'<tr><th>Profile URL</th><td><input name="url" type="url" value="%s" class="large-text">'
		. '<p class="description">Fallback only, used when no Place ID is set below.</p></td></tr>',
		esc_attr( $profile['url'] )
	);
	printf(
		'<tr><th>Google Place ID</th><td><input name="place_id" type="text" value="%s" class="large-text">'
		. '<p class="description">With this set, &ldquo;Read all reviews on Google&rdquo; opens '
		. 'Google&rsquo;s <strong>reviews list</strong> directly. Without it the link opens the '
		. 'map with your business pinned, and the reviews are several taps away.<br>'
		. 'Current link: <code>%s</code></p></td></tr>',
		esc_attr( $profile['place_id'] ),
		esc_html( aqm_rev_reviews_url( $profile ) )
	);
	printf(
		'<tr><th>Last checked</th><td><input name="checked" type="date" value="%s"></td></tr>',
		esc_attr( $profile['checked'] )
	);
	echo '</tbody></table>';

	echo '<h2>Reviews bar</h2>'
		. '<p>The bar in a bottom corner of <strong>every</strong> page: a '
		. '<strong>Total reviews</strong> box, with review cards beside it.</p>';

	echo '<table class="form-table"><tbody>';
	printf(
		'<tr><th>Reviews</th><td><label><input name="float" type="checkbox" value="1" %s> '
		. 'Show the reviews on every page</label>'
		. '<p class="description">One switch for the whole bar &mdash; the Total reviews box '
		. '<em>and</em> the cards beside it. Off means nothing appears in the corner at all.</p>'
		. '</td></tr>',
		checked( ! empty( $profile['float'] ), true, false )
	);
	/*
	 * ONE NUMBER, NOT TWO.
	 *
	 * Until 1.12.0 this screen had "Cards visible - in a page" and "Cards
	 * visible - in the corner", which is a distinction the person setting it
	 * does not have in their head: they are looking at one bar and counting
	 * the boxes in it. A scan of all 22 pages on the site on 8 Sep 2026 found
	 * no in-page [aqm_reviews] badge anywhere, so the second field was
	 * configuring something that does not exist here. Both keys are still
	 * written, from this one field, so an in-page badge - if one is ever
	 * placed - matches the corner instead of silently differing. Per page,
	 * [aqm_reviews cards="2"] still overrides it.
	 */
	printf(
		'<tr><th>Review cards</th><td><select name="cards">%s</select>'
		. '<p class="description">How many review cards sit <strong>beside</strong> the Total '
		. 'reviews box. <strong>3</strong> means one Total reviews box plus three review cards. '
		. '<strong>0</strong> leaves the Total reviews box on its own.</p>'
		. '<p class="description">The cards run inward from the corner &mdash; to the right of '
		. 'the box in the bottom-left corner, to its left in the bottom-right one &mdash; so '
		. 'they never run off the screen edge. On a phone the box appears alone, because it '
		. 'already fills the corner.</p></td></tr>',
		aqm_rev_options(
			range( 0, 5 ),
			(int) $profile['float_cards'],
			array( 0 => '0 — Total reviews box only' )
		)
	);
	printf(
		'<tr><th>Corner</th><td><select name="float_pos">'
		. '<option value="bottom-left" %s>Bottom left</option>'
		. '<option value="bottom-right" %s>Bottom right</option></select>'
		. '<p class="description">The WhatsApp button sits bottom right, so bottom left '
		. 'keeps the two apart.</p></td></tr>',
		selected( 'bottom-right' !== $profile['float_pos'], true, false ),
		selected( 'bottom-right' === $profile['float_pos'], true, false )
	);
	printf(
		'<tr><th>Except on these pages</th><td>'
		. '<input name="float_exclude" type="text" value="%s" class="large-text">'
		. '<p class="description">Page or post <strong>IDs</strong>, comma separated. Use this '
		. 'when the badge lands on top of something &mdash; no code change needed. The ID is '
		. 'in the address bar when you edit a page: <code>post=<strong>103</strong></code>.</p>'
		. '</td></tr>',
		esc_attr( $profile['float_exclude'] )
	);
	echo '</tbody></table>';

	echo '<p class="description">On phones the floating badge drops the long heading line and '
		. 'shows the photo, stars and count only, so it cannot cover the page. A badge placed '
		. 'in page content with <code>[aqm_reviews]</code> keeps its heading.</p>';

	$verified_live = aqm_rev_count( 'published', 1 );

	echo '<h2>How the cards read</h2>'
		. '<p>The review cards counted above show <strong>published and verified</strong> '
		. 'reviews only, and every card links to the same place the Total reviews box does '
		. '&mdash; your Google reviews list. Nothing here changes how many are shown.</p>';

	if ( ! $verified_live ) {
		echo '<div class="notice notice-warning inline"><p><strong>Nothing to show yet.</strong> '
			. 'No review is both published and verified, so the window renders as nothing at all. '
			. 'Verify some reviews and publish them and it will appear on its own.</p></div>';
	}

	echo '<table class="form-table"><tbody>';
	printf(
		'<tr><th>Speed</th><td><select name="scroll_speed">%s</select>'
		. '<p class="description">Seconds each card takes to cross. Higher is slower. It pauses '
		. 'while the pointer is over it, so nobody has to chase a moving quote.</p></td></tr>',
		aqm_rev_options( array( 3, 4, 5, 6, 8, 10, 14 ), (int) $profile['scroll_speed'] )
	);
	printf(
		'<tr><th>How many reviews</th><td>'
		. '<input name="scroll_limit" type="number" min="0" max="200" value="%d" class="small-text">'
		. '<p class="description">The newest this many, by sort order. 0 means all of them. '
		. 'Currently <strong>%d</strong> are published and verified.</p></td></tr>',
		(int) $profile['scroll_limit'],
		$verified_live
	);
	printf(
		'<tr><th>Words per card</th><td>'
		. '<input name="scroll_words" type="number" min="6" max="120" value="%d" class="small-text">'
		. '<p class="description">Longer reviews are cut here and end in an ellipsis, so the card '
		. 'shows plainly that there is more to read &mdash; clicking opens the full review on '
		. 'Google. A rating with no words shows as &ldquo;Rated 5 out of 5&rdquo; rather than a '
		. 'blank card.</p></td></tr>',
		(int) $profile['scroll_words']
	);
	echo '</tbody></table>';

	echo '<h2>Box size</h2>'
		. '<p>These apply to <strong>both</strong> the badge and every review card, so the two '
		. 'read as one component rather than two boxes that happen to sit together.</p>'
		. '<p class="description"><strong>Why pixels.</strong> Centimetres have to be converted by '
		. 'the browser against an assumed screen DPI that is wrong on most screens, and a '
		. '&ldquo;character&rdquo; is the width of a zero in whatever font your theme applies '
		. '&mdash; so the same number would give a different box on a different page. A pixel '
		. 'means the same thing everywhere, and is what every other size on the site is set in. '
		. 'For a rough feel: <strong>380px</strong> is about 10&nbsp;cm on a typical laptop.</p>';

	echo '<table class="form-table"><tbody>';
	printf(
		'<tr><th>Width</th><td>'
		. '<input name="box_width" type="number" min="0" max="900" step="10" value="%d" '
		. 'class="small-text"> px'
		. '<p class="description"><strong>0 = fit the content</strong>, which is how the badge '
		. 'behaves now &mdash; it ends where the name ends. Set a number and the badge and every '
		. 'card take exactly that width.</p></td></tr>',
		(int) $profile['box_width']
	);
	printf(
		'<tr><th>Height</th><td>'
		. '<input name="box_height" type="number" min="0" max="600" step="10" value="%d" '
		. 'class="small-text"> px'
		. '<p class="description"><strong>0 = fit the content.</strong> A number sets a minimum for a '
		. 'badge placed in page content. <strong>For the floating corner bar it is exact</strong> &mdash; the '
		. 'badge and every card are set to this height and the photo shrinks to fit, so they always match. '
		. 'The float will not go below 56px, since the stars stop being readable. About 120px suits three '
		. 'cards with a line or two of review text. '
		. 'so a short review and a long one make boxes the same size. Set it too low and a long '
		. 'review is clipped &mdash; lower <em>Words per card</em> above rather than fighting it '
		. 'here.</p></td></tr>',
		(int) $profile['box_height']
	);
	printf(
		'<tr><th>Box background</th><td>'
		. '<select name="box_bg">'
		. '<option value="solid"%1$s>Solid &mdash; white, readable over anything</option>'
		. '<option value="transparent"%2$s>Transparent &mdash; frosted, shows the page through</option>'
		. '</select>'
		. '<p class="description">Applies to the badge and every review card. Transparent keeps the '
		. 'border and shadow, so the box still has an edge, and blurs what is behind it where the '
		. 'browser supports that. The text colour is left as it is &mdash; over a dark background a '
		. 'transparent box will be hard to read, so check it before you keep it.</p></td></tr>',
		selected( 'solid', $profile['box_bg'], false ),
		selected( 'transparent', $profile['box_bg'], false )
	);
	printf(
		'<tr><th>Distance from the corner</th><td>'
		. '<input name="float_gap" type="number" min="0" max="400" step="2" value="%d" '
		. 'class="small-text"> px'
		. '<p class="description">The <strong>minimum</strong> distance from the left or right edge. '
		. '<strong>90</strong> by default, which clears a floating chat bubble on its own.<br>'
		. 'You should not normally need to change this: the bar measures whatever else is pinned in the '
		. 'same corner &mdash; a WhatsApp or Messenger button, a support widget &mdash; and moves outside '
		. 'it automatically, on whichever side it is on. This number is the floor it never goes below. '
		. 'The distance from the bottom is left alone, because lifting the bar would push it over the '
		. 'page content instead.</p></td></tr>',
		(int) $profile['float_gap']
	);
	printf(
		'<tr><th>Hide at the footer</th><td>'
		. '<label><input type="checkbox" name="float_hide" value="1"%s> '
		. 'Get out of the way when the footer comes into view</label>'
		. '<p class="description">The floating bar is pinned to the bottom of the screen, so at the end '
		. 'of a page it sits on the footer &mdash; and that is where the RECO brokerage line is. With '
		. 'this on, the bar fades out as soon as the footer appears and comes back when you scroll up.'
		. '<br>This is the only JavaScript the plugin uses, and it is why: CSS cannot ask whether '
		. 'another element is on screen. It watches for the element below, so that box must be filled '
		. 'in. If the browser is too old to support it the bar simply never hides &mdash; nothing '
		. 'breaks.</p></td></tr>',
		checked( 1, (int) $profile['float_hide'], false )
	);
	printf(
		'<tr><th>Keep above the badge</th><td>'
		. '<input name="footer_sel" type="text" value="%s" class="regular-text" style="max-width:520px">'
		. '<p class="description">The floating badge is pinned to the bottom of the screen, so at the '
		. 'end of a page it lands on the footer &mdash; which is where the RECO brokerage line lives. '
		. 'Anything matching this selector is painted <strong>above</strong> the badge instead, so the '
		. 'badge tucks behind it. No JavaScript is involved.<br>'
		. 'If the badge still shows through, that element has no background of its own &mdash; give the '
		. 'footer a solid background colour in Elementor. Empty this box to switch the rule off.<br>'
		. '<em>Child combinators (&gt;) are stripped for safety, since they could close the style block. '
		. 'Use a descendant selector instead &mdash; <code>.site-footer div</code> rather than '
		. '<code>.site-footer &gt; div</code>.</em></p>'
		. '</td></tr>',
		esc_attr( $profile['footer_sel'] )
	);
	printf(
		'<tr><th>Text size</th><td>'
		. '<input name="box_font" type="number" min="10" max="30" value="%d" class="small-text"> px'
		. '<p class="description">15 is the default. Stars and the Google mark scale with it, so '
		. 'raising this does not leave a large name beside tiny stars.</p></td></tr>',
		(int) $profile['box_font']
	);
	printf(
		'<tr><th>Photo height</th><td>'
		. '<input name="box_photo" type="number" min="24" max="240" value="%d" class="small-text"> px'
		. '<p class="description">Your headshot in the badge. The width follows the picture&rsquo;s '
		. 'own proportions &mdash; nothing is cropped. 88 matches the widget this replaced.</p>'
		. '</td></tr>',
		(int) $profile['box_photo']
	);
	echo '</tbody></table>';

	submit_button( 'Save settings' );
	echo '</form>';

	echo '<hr><h2>Where it appears</h2>'
		. '<p><strong>The window goes wherever the badge goes.</strong> Every badge on the site '
		. '&mdash; the floating one and any placed in a page &mdash; carries the scrolling window '
		. 'with it. Nothing has to be placed separately and there is no shortcode to remember. '
		. 'Setting <strong>Review cards</strong> to <strong>0</strong> is what turns it off, '
		. 'everywhere at once.</p>'
		. '<p class="description">On phones the corner shows the Total reviews box alone, '
		. 'whatever is set above &mdash; a corner strip three cards across would cover the '
		. 'page. The box stays either way.</p>'
		. '<h3>Shortcodes, for the exceptions</h3>'
		. '<table class="widefat striped" style="max-width:820px"><thead><tr>'
		. '<th style="width:320px">Paste this into a page</th><th>What appears</th>'
		. '</tr></thead><tbody>'
		. '<tr><td><code>[aqm_reviews]</code></td>'
		. '<td>Badge with the scrolling window under it. <strong>The normal one.</strong></td></tr>'
		. '<tr><td><code>[aqm_reviews layout="badge"]</code></td>'
		. '<td>The badge alone, window suppressed just here.</td></tr>'
		. '<tr><td><code>[aqm_reviews layout="scroll"]</code></td>'
		. '<td>The scrolling window alone, no badge.</td></tr>'
		. '<tr><td><code>[aqm_reviews layout="list"]</code></td>'
		. '<td>Every verified review, read straight down the page. No scrolling.</td></tr>'
		. '<tr><td><code>[aqm_reviews cards="4"]</code></td>'
		. '<td>Overrides <strong>Review cards</strong> on that page only.</td></tr>'
		. '</tbody></table>';

	// A preview, so "I cannot see it" can be told apart from "it is broken"
	// without going to hunt for it on the front end first.
	echo '<h2>Preview</h2>';

	$preview = aqm_rev_scroll_html( $profile );

	if ( $preview ) {
		echo '<p class="description">Exactly what <code>[aqm_reviews layout="scroll"]</code> '
			. 'renders, with the settings above. Your theme may size the text differently on the '
			. 'live page.</p>'
			. '<div style="max-width:1000px;background:#fff;border:1px solid #dcdcde;'
			. 'border-radius:8px;padding:20px">';
		aqm_rev_styles();
		echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
	} else {
		echo '<div class="notice notice-warning inline"><p><strong>The window would render as '
			. 'nothing at all.</strong> ';
		if ( (int) $profile['scroll_cards'] < 1 ) {
			echo 'Review cards is set to <strong>0</strong>, which switches it off.';
		} else {
			echo 'No review is both <strong>published and verified</strong>. Verifying a review '
				. 'is not enough on its own &mdash; it also has to be published. On the reviews '
				. 'list, tick the rows and apply <em>Mark verified</em>, then tick them again and '
				. 'apply <em>Publish</em>.';
		}
		echo '</p></div>';
	}
}

/** Option tags for a plain list of numbers, with optional custom labels. */
function aqm_rev_options( $values, $current, $labels = array() ) {
	$out = '';
	foreach ( $values as $value ) {
		$out .= sprintf(
			'<option value="%d"%s>%s</option>',
			(int) $value,
			selected( (int) $value, (int) $current, false ),
			esc_html( isset( $labels[ $value ] ) ? $labels[ $value ] : (string) $value )
		);
	}
	return $out;
}

/* =====================================================================
 * WRITE HANDLERS
 * ================================================================== */

add_action(
	'admin_post_aqm_rev_save',
	function () {

		check_admin_referer( 'aqm_rev_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		global $wpdb;

		$id        = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$now       = current_time( 'mysql' );
		$posted_on = isset( $_POST['posted_on'] ) ? sanitize_text_field( wp_unslash( $_POST['posted_on'] ) ) : '';
		$verified  = empty( $_POST['verified'] ) ? 0 : 1;
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';

		$data = array(
			'reviewer'     => isset( $_POST['reviewer'] ) ? sanitize_text_field( wp_unslash( $_POST['reviewer'] ) ) : '',
			'attribution'  => isset( $_POST['attribution'] ) ? sanitize_text_field( wp_unslash( $_POST['attribution'] ) ) : '',
			'rating'       => isset( $_POST['rating'] ) ? max( 1, min( 5, absint( wp_unslash( $_POST['rating'] ) ) ) ) : 5,
			// wp_kses_post rather than sanitize_text_field: reviews contain line
			// breaks and apostrophes, and flattening them would alter the quote.
			'review_text'  => isset( $_POST['review_text'] ) ? wp_kses_post( wp_unslash( $_POST['review_text'] ) ) : '',
			'posted_on'    => $posted_on ? $posted_on : null,
			'posted_label' => isset( $_POST['posted_label'] ) ? sanitize_text_field( wp_unslash( $_POST['posted_label'] ) ) : '',
			'status'       => in_array( $status, array( 'published', 'draft' ), true ) ? $status : 'draft',
			'verified'     => $verified,
			'verified_at'  => $verified ? $now : null,
			'sort_order'   => isset( $_POST['sort_order'] ) ? intval( wp_unslash( $_POST['sort_order'] ) ) : 0,
			'updated_at'   => $now,
		);

		if ( '' === $data['attribution'] ) {
			$data['attribution'] = $data['reviewer'];
		}

		if ( $id ) {
			$wpdb->update( aqm_rev_table(), $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( aqm_rev_table(), $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=aqm-reviews&msg=saved' ) );
		exit;
	}
);

add_action(
	'admin_post_aqm_rev_delete',
	function () {

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'aqm_rev_delete_' . $id );

		if ( ! current_user_can( 'manage_options' ) || ! $id ) {
			wp_die( 'Not allowed.' );
		}

		global $wpdb;
		$wpdb->delete( aqm_rev_table(), array( 'id' => $id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=aqm-reviews&msg=deleted' ) );
		exit;
	}
);

add_action(
	'admin_post_aqm_rev_bulk',
	function () {

		check_admin_referer( 'aqm_rev_bulk' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		// Two dropdowns, above and below the table, so whichever one was used is
		// the one that counts. Both are submitted every time; the unused one is
		// empty. Reading only `bulk` meant the lower, untouched dropdown could
		// overwrite the choice - the bug that made bulk actions do nothing.
		$bulk = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
		if ( '' === $bulk && isset( $_POST['bulk2'] ) ) {
			$bulk = sanitize_key( wp_unslash( $_POST['bulk2'] ) );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );

		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';

		// Say which of the two it was, rather than leaving the screen looking as
		// though the click was ignored - which is how the original bug hid.
		if ( '' === $bulk || ! $ids ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'aqm-reviews',
						'filter' => $filter,
						'msg'    => 'bulk',
						'bulk'   => '' === $bulk ? 'nochoice' : 'norows',
						'n'      => 0,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		global $wpdb;
		$table  = aqm_rev_table();
		$holder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$done   = 0;

		if ( 'delete' === $bulk ) {

			$done = (int) $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$holder})", $ids ) // phpcs:ignore WordPress.DB
			);

		} elseif ( 'publish' === $bulk || 'draft' === $bulk ) {

			// The dropdown says "publish"; the COLUMN says "published". Sending
			// the dropdown value straight through wrote a status nothing reads,
			// so the row went live nowhere and still displayed as a draft.
			$status = ( 'publish' === $bulk ) ? 'published' : 'draft';

			$args = array_merge( array( $status, current_time( 'mysql' ) ), $ids );
			$done = (int) $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( // phpcs:ignore WordPress.DB
					"UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$holder})",
					$args
				)
			);

		} elseif ( 'verify' === $bulk ) {

			$now  = current_time( 'mysql' );
			$args = array_merge( array( $now, $now ), $ids );
			$done = (int) $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( // phpcs:ignore WordPress.DB
					"UPDATE {$table} SET verified = 1, verified_at = %s, updated_at = %s "
					. "WHERE id IN ({$holder})",
					$args
				)
			);

		} elseif ( 'unverify' === $bulk ) {

			// verified_at is cleared too, so the timestamp never outlives the
			// claim it was recording.
			$args = array_merge( array( current_time( 'mysql' ) ), $ids );
			$done = (int) $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( // phpcs:ignore WordPress.DB
					"UPDATE {$table} SET verified = 0, verified_at = NULL, updated_at = %s "
					. "WHERE id IN ({$holder})",
					$args
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'aqm-reviews',
					'filter' => $filter,
					'msg'    => 'bulk',
					'bulk'   => $bulk,
					'n'      => $done,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
);

add_action(
	'admin_post_aqm_rev_profile',
	function () {

		check_admin_referer( 'aqm_rev_profile' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		// The resized-headshot lookup is cached for a week and keyed on the URL,
		// so both the outgoing and the incoming one are cleared here. Otherwise
		// changing the photo would appear to do nothing.
		$was = aqm_rev_profile();
		delete_transient( 'aqm_rev_face_' . md5( trim( (string) $was['avatar'] ) ) );
		if ( isset( $_POST['avatar'] ) ) {
			delete_transient( 'aqm_rev_face_' . md5( trim( esc_url_raw( wp_unslash( $_POST['avatar'] ) ) ) ) );
		}

		update_option(
			'aqm_reviews_profile',
			array(
				'total'   => isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0,
				'average' => isset( $_POST['average'] ) ? sanitize_text_field( wp_unslash( $_POST['average'] ) ) : '',
				'heading' => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '',
				'avatar'  => isset( $_POST['avatar'] ) ? esc_url_raw( wp_unslash( $_POST['avatar'] ) ) : '',
				'url'      => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				'place_id' => isset( $_POST['place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['place_id'] ) ) : '',
				'checked'  => isset( $_POST['checked'] ) ? sanitize_text_field( wp_unslash( $_POST['checked'] ) ) : '',

				'float'         => empty( $_POST['float'] ) ? 0 : 1,
				'float_pos'     => ( isset( $_POST['float_pos'] ) && 'bottom-right' === $_POST['float_pos'] )
					? 'bottom-right'
					: 'bottom-left',
				// Kept as the typed string so the field reads back as entered;
				// it is parsed to integers at render time, where a stray
				// character is ignored rather than guessed at.
				'float_exclude' => isset( $_POST['float_exclude'] )
					? sanitize_text_field( wp_unslash( $_POST['float_exclude'] ) )
					: '',

				// 0 is a real choice here - it switches the cards off - so the
				// bounds are applied rather than falling back to a default.
				// One posted field, both keys: see the "Review cards" comment
				// on the admin screen for why they were merged.
				'scroll_cards'  => isset( $_POST['cards'] )
					? max( 0, min( 5, absint( wp_unslash( $_POST['cards'] ) ) ) )
					: 3,
				'scroll_speed'  => isset( $_POST['scroll_speed'] )
					? max( 2, min( 60, absint( wp_unslash( $_POST['scroll_speed'] ) ) ) )
					: 6,
				'scroll_limit'  => isset( $_POST['scroll_limit'] )
					? min( 200, absint( wp_unslash( $_POST['scroll_limit'] ) ) )
					: 12,
				'scroll_words'  => isset( $_POST['scroll_words'] )
					? max( 6, min( 120, absint( wp_unslash( $_POST['scroll_words'] ) ) ) )
					: 28,
				'float_cards'   => isset( $_POST['cards'] )
					? max( 0, min( 5, absint( wp_unslash( $_POST['cards'] ) ) ) )
					: 3,

				// 0 is meaningful for width and height - it means "fit the
				// content" - so these are clamped, never defaulted away.
				'box_width'     => isset( $_POST['box_width'] )
					? min( 900, absint( wp_unslash( $_POST['box_width'] ) ) )
					: 0,
				'box_height'    => isset( $_POST['box_height'] )
					? min( 600, absint( wp_unslash( $_POST['box_height'] ) ) )
					: 0,
				'box_font'      => isset( $_POST['box_font'] )
					? max( 10, min( 30, absint( wp_unslash( $_POST['box_font'] ) ) ) )
					: 15,
				'box_photo'     => isset( $_POST['box_photo'] )
					? max( 24, min( 240, absint( wp_unslash( $_POST['box_photo'] ) ) ) )
					: 88,
				'footer_sel'    => isset( $_POST['footer_sel'] )
					? aqm_rev_safe_selector( wp_unslash( $_POST['footer_sel'] ) )
					: '',
				'box_bg'        => ( isset( $_POST['box_bg'] ) && 'transparent' === $_POST['box_bg'] )
					? 'transparent'
					: 'solid',
				'float_hide'    => isset( $_POST['float_hide'] ) ? 1 : 0,
				'float_gap'     => isset( $_POST['float_gap'] )
					? min( 400, absint( wp_unslash( $_POST['float_gap'] ) ) )
					: 20,
			),
			false
		);

		wp_safe_redirect( admin_url( 'admin.php?page=aqm-reviews&msg=profile' ) );
		exit;
	}
);

add_action(
	'admin_post_aqm_rev_import',
	function () {

		check_admin_referer( 'aqm_rev_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}

		global $wpdb;

		$fail = function ( $message ) {
			wp_die(
				esc_html( $message ),
				'Import',
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		};

		$payload = null;

		// An uploaded file wins over the textarea. is_uploaded_file() is the
		// check that matters: it confirms PHP put the file there, so a crafted
		// tmp_name cannot be used to read an arbitrary path off the server.
		if ( ! empty( $_FILES['payload_file']['tmp_name'] )
			&& is_uploaded_file( $_FILES['payload_file']['tmp_name'] ) ) {

			if ( ! empty( $_FILES['payload_file']['error'] ) ) {
				$fail(
					'The upload failed before it reached WordPress (PHP error code '
					. (int) $_FILES['payload_file']['error'] . '). Nothing was imported.'
				);
			}

			if ( (int) $_FILES['payload_file']['size'] > 2 * MB_IN_BYTES ) {
				$fail( 'That file is larger than 2 MB. Nothing was imported.' );
			}

			$tmp  = $_FILES['payload_file']['tmp_name'];
			$name = isset( $_FILES['payload_file']['name'] )
				? sanitize_file_name( $_FILES['payload_file']['name'] ) : '';
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( 'xlsx' === $ext ) {

				$rows = aqm_rev_parse_xlsx( $tmp );
				if ( is_wp_error( $rows ) ) {
					$fail( $rows->get_error_message() );
				}
				$payload = aqm_rev_rows_to_items( $rows );

			} elseif ( 'csv' === $ext || 'txt' === $ext ) {

				$rows   = array();
				$handle = fopen( $tmp, 'r' );
				if ( ! $handle ) {
					$fail( 'That file could not be opened.' );
				}
				// Strip a UTF-8 BOM, which Excel writes and which would otherwise
				// become part of the first header name and break the match.
				$first = fgets( $handle );
				if ( false !== $first ) {
					rewind( $handle );
					if ( "\xEF\xBB\xBF" === substr( $first, 0, 3 ) ) {
						fseek( $handle, 3 );
					}
				}
				while ( false !== ( $line = fgetcsv( $handle ) ) ) {
					$rows[] = $line;
				}
				fclose( $handle );

				$payload = aqm_rev_rows_to_items( $rows );

			} else {

				$payload = json_decode( (string) file_get_contents( $tmp ), true );
			}
		} elseif ( isset( $_POST['payload'] ) && '' !== trim( wp_unslash( $_POST['payload'] ) ) ) {

			$payload = json_decode( wp_unslash( $_POST['payload'] ), true );

		} else {
			$fail( 'Nothing was supplied - no file chosen and nothing pasted.' );
		}

		if ( is_wp_error( $payload ) ) {
			$fail( $payload->get_error_message() );
		}

		// Accept either a bare array or the whole reviews.json object.
		if ( is_array( $payload ) && isset( $payload['reviews'] ) ) {
			$payload = $payload['reviews'];
		}

		if ( ! is_array( $payload ) || ! $payload ) {
			$fail( 'Nothing usable was found in that file. Nothing was imported.' );
		}

		$now   = current_time( 'mysql' );
		$table = aqm_rev_table();
		$mode  = ( isset( $_POST['mode'] ) && 'replace' === $_POST['mode'] ) ? 'replace' : 'append';

		$wiped = 0;

		if ( 'replace' === $mode ) {

			// Destructive, so it needs its own deliberate tick. Verified rows are
			// hours of somebody's careful checking; losing them to a mis-click
			// would be the worst thing this screen could do.
			if ( empty( $_POST['confirm_replace'] ) ) {
				$fail(
					'Replace was selected but the confirmation box was not ticked. '
					. 'Nothing was changed.'
				);
			}

			$wiped = (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		// Order continues from what is already there, so an append lands after
		// the existing rows rather than interleaving with them.
		$order = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$table}" ); // phpcs:ignore WordPress.DB

		// The ONLY thing that suppresses a row is its own id having been imported
		// before, so re-running the same file is safe. Names are never used for
		// this: two people can share a name, and one client can review twice
		// after two transactions. Both are real reviews and both are kept.
		$seen_ids = $wpdb->get_col( "SELECT external_id FROM {$table} WHERE external_id <> ''" ); // phpcs:ignore WordPress.DB
		$seen_ids = array_flip( $seen_ids );

		$added   = 0;
		$skipped = 0;

		foreach ( $payload as $item ) {

			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = isset( $item['reviewer'] ) ? sanitize_text_field( $item['reviewer'] ) : '';
			if ( '' === trim( $name ) ) {
				continue;   // a review with nobody attached is not a review
			}

			$external = isset( $item['id'] ) ? sanitize_text_field( $item['id'] ) : '';
			if ( '' !== $external && isset( $seen_ids[ $external ] ) ) {
				++$skipped;
				continue;
			}

			// Empty text is legitimate: about a third of the reviews on the
			// profile are a star rating and nothing else. They count, they are
			// shown, and they are stored like any other.
			$text = '';
			if ( isset( $item['raw'] ) ) {
				$text = (string) $item['raw'];
			} elseif ( isset( $item['review_text'] ) ) {
				$text = (string) $item['review_text'];
			}

			$label = '';
			if ( isset( $item['posted_relative'] ) && '' !== $item['posted_relative'] ) {
				$label = (string) $item['posted_relative'];
			} elseif ( isset( $item['posted'] ) ) {
				$label = (string) $item['posted'];
			}

			// A four-digit year in the label is a year, not a caption.
			$date = '';
			if ( isset( $item['posted_on'] ) ) {
				$date = trim( (string) $item['posted_on'] );
			}
			if ( '' === $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $label ) ) {
				$date  = $label;
				$label = '';
			}

			$rating = isset( $item['rating'] ) ? absint( $item['rating'] ) : 5;
			$rating = $rating ? max( 1, min( 5, $rating ) ) : 5;

			$wpdb->insert(
				$table,
				array(
					'external_id'  => $external,
					'reviewer'     => $name,
					'attribution'  => ( isset( $item['attribution'] ) && '' !== trim( (string) $item['attribution'] ) )
						? sanitize_text_field( $item['attribution'] ) : $name,
					'rating'       => $rating,
					'review_text'  => wp_kses_post( $text ),
					'posted_on'    => $date ? $date : null,
					'posted_label' => sanitize_text_field( $label ),
					'source'       => 'google',
					'status'       => 'draft',   // never live on import
					'verified'     => 0,         // never trusted on import
					'sort_order'   => $order,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);

			if ( '' !== $external ) {
				$seen_ids[ $external ] = true;
			}

			++$order;
			++$added;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'aqm-reviews',
					'msg'     => 'imported',
					'added'   => $added,
					'skipped' => $skipped,
					'wiped'   => $wiped,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
);

/* =====================================================================
 * FRONT END
 * ================================================================== */

/** Five stars, filled to $n. */
function aqm_rev_stars( $n ) {
	$out = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$out .= '<span class="aqm-rev-star' . ( $i <= $n ? '' : ' is-empty' ) . '">'
			. "\xE2\x98\x85" . '</span>';
	}
	return $out;
}

/**
 * Google's G, inline.
 *
 * Inline SVG rather than a hosted image: nothing to 404, no request, and it
 * cannot be broken by a plugin that rewrites image URLs. It sits beside a link
 * that goes to the real Google reviews for this business, which is what the
 * mark is there to signal.
 */
function aqm_rev_google_mark() {
	return '<svg class="aqm-rev-g" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
		. '<path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-3.2-.4-4.7H24v8.9h11.8c-.5 2.7-2 5-4.4 6.6v5.5h7.1c4.2-3.8 6.6-9.5 6.6-16.3z"/>'
		. '<path fill="#34A853" d="M24 46c6 0 11-2 14.6-5.3l-7.1-5.5c-2 1.3-4.5 2.1-7.5 2.1-5.8 0-10.7-3.9-12.4-9.1H4.3v5.7C7.9 41.1 15.4 46 24 46z"/>'
		. '<path fill="#FBBC05" d="M11.6 28.2c-.4-1.3-.7-2.7-.7-4.2s.3-2.9.7-4.2v-5.7H4.3C2.8 17 2 20.4 2 24s.8 7 2.3 9.9l7.3-5.7z"/>'
		. '<path fill="#EA4335" d="M24 10.7c3.3 0 6.2 1.1 8.5 3.3l6.3-6.3C35 4.1 30 2 24 2 15.4 2 7.9 6.9 4.3 14.1l7.3 5.7c1.7-5.2 6.6-9.1 12.4-9.1z"/>'
		. '</svg>';
}

/** A round avatar: the reviewer's photo if we had one, otherwise their initials. */
function aqm_rev_initials( $name ) {
	$parts = preg_split( '/\s+/', trim( (string) $name ) );
	$out   = '';
	foreach ( $parts as $part ) {
		$part = preg_replace( '/[^\p{L}]/u', '', $part );
		if ( '' !== $part ) {
			$out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}
		if ( mb_strlen( $out ) >= 2 ) {
			break;
		}
	}
	return $out ? $out : '?';
}

/** The reviews themselves, already escaped. */
function aqm_rev_render_list( $reviews, $profile ) {

	$html = '';

	// The panel gets its own header. Without one it reads as a stray box of
	// text; with it, it reads as "this is the Google record for this business".
	$html .= '<div class="aqm-rev-panelhead">'
		. aqm_rev_google_mark()
		. '<div><span class="aqm-rev-panelname">' . esc_html( $profile['heading'] ) . '</span>'
		. '<span class="aqm-rev-panelmeta">'
		. aqm_rev_stars( (int) round( (float) $profile['average'] ) )
		. ' <strong>' . esc_html( $profile['average'] ) . '</strong> from '
		. (int) $profile['total'] . ' Google reviews</span></div></div>';

	if ( $reviews ) {

		$html .= '<div class="aqm-rev-list">';

		foreach ( $reviews as $review ) {

			$name = $review->attribution ? $review->attribution : $review->reviewer;
			$when = $review->posted_label;
			if ( '' === $when && $review->posted_on ) {
				$when = date_i18n( 'F Y', strtotime( $review->posted_on ) );
			}

			$html .= '<article class="aqm-rev-item">'
				. '<div class="aqm-rev-who">'
				. '<span class="aqm-rev-initials" aria-hidden="true">'
				. esc_html( aqm_rev_initials( $name ) ) . '</span>'
				. '<div class="aqm-rev-whotext">'
				. '<span class="aqm-rev-itemname">' . esc_html( $name ) . '</span>'
				. '<span class="aqm-rev-itemmeta">' . aqm_rev_stars( (int) $review->rating )
				. ( $when ? ' <span class="aqm-rev-when">' . esc_html( $when ) . '</span>' : '' )
				. '</span></div></div>';

			// A rating with no words is shown as exactly that - a name and stars.
			// Inventing a caption for it would be putting words in their mouth.
			if ( '' !== trim( $review->review_text ) ) {
				$html .= '<p class="aqm-rev-text">' . nl2br( esc_html( $review->review_text ) ) . '</p>';
			}

			$html .= '</article>';
		}

		$html .= '</div>';

	}

	// When nothing is verified yet the panel shows its header and the link to
	// Google, and nothing in between. It deliberately does NOT say "reviews are
	// being verified": that is a fact about this website's internal state, and a
	// visitor reading it beside a Google logo would reasonably think GOOGLE'S
	// reviews were under review. Saying nothing is honest; saying that is not.

	$html .= '<div class="aqm-rev-foot">'
		. sprintf(
			'<a class="aqm-rev-cta" href="%s" target="_blank" rel="noopener">Read all %d reviews on Google</a>',
			esc_url( aqm_rev_reviews_url( $profile ) ),
			(int) $profile['total']
		);

	if ( ! empty( $profile['place_id'] ) ) {
		$html .= sprintf(
			'<a class="aqm-rev-write" href="%s" target="_blank" rel="noopener">Write a review</a>',
			esc_url( 'https://search.google.com/local/writereview?placeid=' . rawurlencode( $profile['place_id'] ) )
		);
	}

	$html .= '</div>';

	return $html;
}

/**
 * [aqm_reviews]                the badge WITH the scrolling window under it
 * [aqm_reviews layout=badge]   the badge on its own, no window
 * [aqm_reviews layout=scroll]  the scrolling window on its own, no badge
 * [aqm_reviews layout=list]    every verified review, read straight down the page
 * [aqm_reviews cards=4]        override how many cards the window shows (0-5)
 * [aqm_reviews limit=6]        cap how many reviews are rendered (list layout)
 *
 * The window travels with the badge by default, everywhere the badge appears,
 * including the sitewide float. Setting "Review cards" to 0 is what switches it
 * off - there is no shortcode to remember and nothing to place separately.
 * `layout=badge` remains for the odd spot that genuinely wants the box alone.
 */
function aqm_rev_shortcode( $atts ) {

	$a = shortcode_atts(
		array(
			'layout' => 'full',
			'limit'  => 0,
			'cards'  => '',
		),
		$atts,
		'aqm_reviews'
	);

	$profile = aqm_rev_profile();

	// A per-placement override, for the odd spot that wants a different width
	// from the site default. Left alone, Profile settings decide.
	if ( '' !== $a['cards'] ) {
		$profile['scroll_cards'] = max( 0, min( 5, (int) $a['cards'] ) );
	}

	ob_start();
	aqm_rev_styles();

	if ( 'list' === $a['layout'] ) {
		$reviews = aqm_rev_get( array( 'limit' => (int) $a['limit'] ) );  // published + verified only
		echo '<div class="aqm-rev">' . aqm_rev_render_list( $reviews, $profile ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return ob_get_clean();
	}

	if ( 'scroll' === $a['layout'] ) {
		echo aqm_rev_scroll_html( $profile ); // phpcs:ignore WordPress.Security.EscapeOutput
		return ob_get_clean();
	}

	echo aqm_rev_badge_html( $profile ); // phpcs:ignore WordPress.Security.EscapeOutput

	if ( 'badge' !== $a['layout'] ) {
		echo aqm_rev_scroll_html( $profile ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return ob_get_clean();
}
add_shortcode( 'aqm_reviews', 'aqm_rev_shortcode' );

/**
 * The headshot, at a sensible size.
 *
 * Written 3 Sep 2026, because the picture took visibly long to appear on first
 * load. The badge displays the photo 88 pixels tall; the URL pasted into
 * settings is normally the ORIGINAL upload, which can be a couple of thousand
 * pixels and a megabyte or more. Every first-time visitor was downloading that
 * to draw a thumbnail, and only looked fast afterwards because their browser
 * had cached it.
 *
 * WordPress already generated smaller copies when the file was uploaded, so the
 * fix is to ask for one rather than to re-process anything. `medium` is capped
 * at 300px on the long edge, keeps the portrait's proportions, and is roughly
 * a tenth of the bytes - still sharp at 88px on a retina screen.
 *
 * Looking the attachment up is a database query, so the answer is cached for a
 * week. Saving the profile clears it.
 *
 * @return array|false url, w, h - or false when no headshot is set.
 */
function aqm_rev_avatar_src( $profile ) {

	$url = isset( $profile['avatar'] ) ? trim( (string) $profile['avatar'] ) : '';
	if ( '' === $url ) {
		return false;
	}

	$key    = 'aqm_rev_face_' . md5( $url );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	// Fall back to exactly what was pasted. An externally hosted headshot, or
	// one whose attachment cannot be resolved, is still shown - just not
	// resized, because there is nothing here to resize.
	$out = array(
		'url' => $url,
		'w'   => 0,
		'h'   => 0,
	);

	$id = attachment_url_to_postid( $url );
	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'medium' );
		if ( $src && ! empty( $src[0] ) ) {
			$out = array(
				'url' => $src[0],
				'w'   => (int) $src[1],
				'h'   => (int) $src[2],
			);
		}
	}

	set_transient( $key, $out, WEEK_IN_SECONDS );
	return $out;
}

/**
 * The badge itself. One builder, used by both the shortcode and the sitewide
 * float, so the two can never drift apart.
 *
 * One box, one click: the badge IS the link and goes straight to the Google
 * reviews list, so there is no panel to open and nothing to toggle. Layout
 * follows the widget this replaces - headshot, name, a big gold star row, then
 * the count - with the Google mark added to the left of the stars, where it
 * cannot sit over the name.
 *
 * @param array  $profile Profile settings.
 * @param string $extra   Extra class on the wrapper, e.g. the float wrapper.
 */
function aqm_rev_badge_html( $profile, $extra = '' ) {

	$img = aqm_rev_avatar_src( $profile );

	$face = $img
		? sprintf(
			'<img class="aqm-rev-avatar" src="%s"%s alt="" decoding="async"'
			. ' fetchpriority="high" data-no-lazy="1" data-skip-lazy="1">',
			esc_url( $img['url'] ),
			// Intrinsic size, so the browser reserves the space before the file
			// arrives instead of reflowing the badge around it when it lands.
			( $img['w'] && $img['h'] )
				? sprintf( ' width="%d" height="%d"', (int) $img['w'], (int) $img['h'] )
				: ''
		)
		: '<span class="aqm-rev-avatar aqm-rev-avatar-fallback" aria-hidden="true">'
			. esc_html( aqm_rev_initials( $profile['heading'] ) ) . '</span>';

	return sprintf(
		'<div class="aqm-rev aqm-rev-badgewrap%s">'
		. '<a class="aqm-rev-badge" href="%s" target="_blank" rel="noopener"'
		. ' aria-label="%s">'
		. '%s'
		. '<span class="aqm-rev-body">'
		. '<span class="aqm-rev-name">%s</span>'
		. '<span class="aqm-rev-starsrow">%s<span class="aqm-rev-stars">%s</span></span>'
		. '<span class="aqm-rev-count">%d Google reviews</span>'
		. '</span>'
		. '</a></div>',
		$extra ? ' ' . esc_attr( $extra ) : '',
		esc_url( aqm_rev_reviews_url( $profile ) ),
		esc_attr(
			sprintf(
				/* translators: 1: number of reviews, 2: profile heading */
				'Read all %1$d Google reviews for %2$s',
				(int) $profile['total'],
				$profile['heading']
			)
		),
		$face,
		esc_html( $profile['heading'] ),
		aqm_rev_google_mark(),
		aqm_rev_stars( (int) round( (float) $profile['average'] ) ),
		(int) $profile['total']
	);
}

/**
 * The scrolling window of verified reviews.
 *
 * Sits beside the badge and does the same thing on click: it goes to the Google
 * reviews list. Every card is its own link, so wherever the visitor clicks in
 * the window, they land in the same place.
 *
 * Built with CSS alone - a flex track, twice the cards, translated by half its
 * width on a loop. No library, no JavaScript, nothing to load. It pauses when
 * the pointer or keyboard focus is inside it, so a review can actually be read,
 * and it stops entirely for anyone whose system asks for reduced motion, where
 * the window becomes a plain sideways scroller instead.
 *
 * The arithmetic is done here rather than in CSS `calc()`: dividing by a
 * custom property is a newer feature than this needs to depend on, and plain
 * percentages work everywhere.
 *
 * @param array $profile Profile settings.
 * @return string Empty when switched off, or when nothing is verified yet.
 */
function aqm_rev_scroll_html( $profile ) {

	$cards = (int) $profile['scroll_cards'];
	if ( $cards < 1 ) {
		return '';   // 0 = no scrolling window at all.
	}
	$cards = min( 5, $cards );

	// Published AND verified only - the same gate as everywhere else. Nothing
	// reaches this window until it has been checked against Google by hand.
	$reviews = aqm_rev_get( array( 'limit' => max( 0, (int) $profile['scroll_limit'] ) ) );
	$count   = count( $reviews );
	if ( ! $count ) {
		return '';
	}

	$cards = min( $cards, $count );
	$words = max( 6, (int) $profile['scroll_words'] );
	$speed = max( 2, (int) $profile['scroll_speed'] );
	$href  = aqm_rev_reviews_url( $profile );

	// A card is the same width as the badge when a width is set, so the two read
	// as one component. With width left at 0 the strip divides its container
	// into equal shares instead, which is what it did before it was adjustable.
	$fixed = max( 0, (int) $profile['box_width'] );

	// With fewer reviews than slots there is nothing to scroll past, so the
	// track sits still rather than sliding a half-empty strip about.
	$loop  = $count > $cards;
	$total = $loop ? $count * 2 : $count;

	$slides = '';
	for ( $pass = 0; $pass < ( $loop ? 2 : 1 ); $pass++ ) {
		foreach ( $reviews as $review ) {

			$name = $review->attribution ? $review->attribution : $review->reviewer;
			$when = $review->posted_label;
			if ( '' === $when && $review->posted_on ) {
				$when = date_i18n( 'F Y', strtotime( $review->posted_on ) );
			}

			// Trimmed, with the ellipsis left visible, and the whole card links
			// to Google. A shortened quote that SAYS it is shortened is fair;
			// one silently tidied into a neater sentence is not.
			$text = trim( $review->review_text );
			$body = '' === $text
				? '<span class="aqm-rev-cardnote">Rated ' . (int) $review->rating . ' out of 5</span>'
				: '<span class="aqm-rev-cardtext">'
					. esc_html( wp_trim_words( $text, $words, '…' ) ) . '</span>';

			$slides .= sprintf(
				'<div class="aqm-rev-slide" style="width:%s"%s>'
				. '<a class="aqm-rev-card" href="%s" target="_blank" rel="noopener"%s>'
				. '<span class="aqm-rev-cardtop">'
				. '<span class="aqm-rev-initials" aria-hidden="true">%s</span>'
				. '<span class="aqm-rev-cardwho"><span class="aqm-rev-cardname">%s</span>'
				. '<span class="aqm-rev-cardmeta">%s%s</span></span>'
				. '%s</span>%s</a></div>',
				esc_attr(
					$fixed
						? $fixed . 'px'
						: number_format( 100 / $total, 4, '.', '' ) . '%'
				),
				// The second pass exists only to make the loop seamless. It is
				// the same reviews again, so it is hidden from screen readers
				// rather than read out twice.
				( $pass ? ' aria-hidden="true"' : '' ),
				esc_url( $href ),
				( $pass ? ' tabindex="-1"' : '' ),
				esc_html( aqm_rev_initials( $name ) ),
				esc_html( $name ),
				aqm_rev_stars( (int) $review->rating ),
				$when ? ' <span class="aqm-rev-when">' . esc_html( $when ) . '</span>' : '',
				aqm_rev_google_mark(),
				$body
			);
		}
	}

	// With a fixed card width the strip is capped to exactly the number of cards
	// asked for; otherwise it fills whatever it is dropped into.
	return sprintf(
		'<div class="aqm-rev aqm-rev-scroll"%s><div class="aqm-rev-strip">'
		. '<div class="aqm-rev-track%s" style="width:%s;animation-duration:%ds">%s</div>'
		. '</div></div>',
		$fixed ? ' style="max-width:' . ( $fixed * $cards ) . 'px"' : '',
		$loop ? ' is-looping' : '',
		esc_attr(
			$fixed
				? ( $fixed * $total ) . 'px'
				: number_format( ( $total / $cards ) * 100, 4, '.', '' ) . '%'
		),
		$count * $speed,
		$slides
	);
}

/**
 * The sitewide floating badge, printed into the footer.
 *
 * This is what retires WPCode snippet 1037. That snippet worked by CLONING a
 * container out of the page and re-positioning the copy, which is why it only
 * ever half-worked and why it broke whenever the source container moved. This
 * renders the badge once, directly, from the same settings as the shortcode.
 *
 * Excluded pages are listed by ID in Profile settings, so the homepage - or any
 * page where the badge lands on top of something - can be handled without
 * touching this file.
 */
function aqm_rev_float() {

	$profile = aqm_rev_profile();

	if ( empty( $profile['float'] ) || is_admin() || is_feed() || is_embed() ) {
		return;
	}

	// A page can opt out by ID. Anything that is not a number is ignored rather
	// than guessed at.
	$exclude = array_filter( array_map( 'absint', preg_split( '/[^0-9]+/', (string) $profile['float_exclude'] ) ) );
	$here    = (int) get_queried_object_id();
	if ( $here && in_array( $here, $exclude, true ) ) {
		return;
	}

	$pos = ( 'bottom-right' === $profile['float_pos'] ) ? 'aqm-rev-float-right' : 'aqm-rev-float-left';

	// The window follows the badge here too, but with its own card count: the
	// corner has less room than a page does, and how much of it to spend is
	// AQ's call, not a number chosen in this file.
	$corner                 = $profile;
	$corner['scroll_cards'] = (int) $profile['float_cards'];

	// The cards run INWARD from the corner, never off the edge of the screen:
	// badge on the left with cards to its right in the left-hand corner, and
	// mirrored in the right-hand one. That is what row-reverse does below.
	aqm_rev_styles();
	printf(
		// The corner is written into the markup as well as the class, so which
		// setting produced the bar can be read off the page in one look.
		'<div class="aqm-rev-floatwrap %s" data-aqm-corner="%s">%s%s</div>',
		esc_attr( $pos ),
		esc_attr( 'bottom-right' === $profile['float_pos'] ? 'right' : 'left' ),
		aqm_rev_badge_html( $corner ), // phpcs:ignore WordPress.Security.EscapeOutput
		aqm_rev_scroll_html( $corner ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
add_action( 'wp_footer', 'aqm_rev_float', 20 );

/**
 * Styles and the one line of behaviour, printed once per page.
 *
 * Inline rather than enqueued files: nothing to 404, nothing for LiteSpeed to
 * combine wrongly, and no second request for forty lines of CSS.
 */
function aqm_rev_styles() {

	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style>
	/* This widget is dropped into somebody else's theme, so every rule here is
	   written to survive that. Lessons learned the hard way on 2-3 Sep 2026:

	   1. The stylesheet originally followed prefers-color-scheme, so the badge
	      turned dark inside a light page whenever the VIEWER's OS was in dark
	      mode. What matters is the page it sits in, not the operating system,
	      and this site is light - so the widget is light, full stop.

	   2. The badge is an <a>, and themes style links hard: colour, underline,
	      and sometimes display. Every one of those is pinned below. */

	.aqm-rev{font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327}
	.aqm-rev *{box-sizing:border-box}

	/* ---- the badge ----
	   The card floats to its content: inline-flex with width:auto means the box
	   ends where the longest line ends, with no dead white space between the
	   name and the right-hand border. max-width keeps it inside narrow columns. */
	.aqm-rev .aqm-rev-badge{display:inline-flex!important;align-items:center;gap:14px;
		width:auto;max-width:100%;text-align:left;cursor:pointer;background:#fff;
		color:#1d2327!important;text-decoration:none!important;
		border:1px solid #e4e6e8;border-radius:12px;padding:14px 18px;font:inherit;
		box-shadow:0 4px 18px rgba(15,23,42,.12);transition:box-shadow .15s,transform .15s}
	.aqm-rev .aqm-rev-badge:hover,
	.aqm-rev .aqm-rev-badge:focus{box-shadow:0 8px 26px rgba(15,23,42,.18);
		transform:translateY(-1px);color:#1d2327!important;text-decoration:none!important}

	/* The headshot is shown exactly as the old widget showed it: the WHOLE
	   portrait, its own proportions kept, sized by HEIGHT with the width left to
	   follow. Squaring it into a 58px tile cropped the top and bottom off the
	   picture and made it look like a different photo, which is what the earlier
	   width+height+object-fit:cover version did. Nothing is imposed on it here -
	   no crop, no rounding, no frame - because the image file carries its own
	   red border. */
	.aqm-rev .aqm-rev-avatar{height:88px;width:auto;max-width:none;flex:0 0 auto;
		display:block;object-fit:contain;border:0;border-radius:0;background:transparent}
	/* The initials stand-in has no picture to take its shape from, so it gets an
	   explicit square at the same height. */
	.aqm-rev .aqm-rev-avatar-fallback{width:88px;height:88px;flex:0 0 88px;
		display:flex;align-items:center;justify-content:center;background:#eef1f4;
		border-radius:8px;font-weight:700;font-size:30px;color:#5f6368;letter-spacing:1px}
	.aqm-rev .aqm-rev-body{flex:1 1 auto;min-width:0;display:block;overflow:hidden}

	/* Themes that set white-space:nowrap on spans made this run out of the card
	   and under the Google mark, so wrapping is forced rather than hoped for. */
	.aqm-rev .aqm-rev-name{display:block;font-weight:700;font-size:15px;line-height:1.3;
		color:#1d2327;margin-bottom:5px;white-space:normal!important;
		overflow-wrap:anywhere;word-break:break-word}

	/* Google mark first, then the stars. */
	.aqm-rev .aqm-rev-starsrow{display:flex;align-items:center;gap:10px;margin-bottom:4px}
	.aqm-rev .aqm-rev-stars{line-height:1}
	.aqm-rev .aqm-rev-count{display:block;color:#3c4043;font-size:15px}
	.aqm-rev .aqm-rev-g{width:24px;height:24px;flex:0 0 24px;display:block}

	/* Specificity plus !important: themes routinely set a colour on every span
	   inside a button, and a grey star row defeats the whole point of the box. */
	.aqm-rev .aqm-rev-star{color:#f5b301!important;letter-spacing:1px;font-size:30px;
		line-height:1}
	.aqm-rev .aqm-rev-star.is-empty{color:#dadce0!important}

	/* ---- the inline list (layout="list") ---- */
	.aqm-rev .aqm-rev-panelhead{display:flex;align-items:center;gap:12px;padding:18px 20px;
		border-bottom:1px solid #edeff1;background:#fafbfc;position:sticky;top:0}
	.aqm-rev .aqm-rev-panelhead>div{min-width:0}
	.aqm-rev .aqm-rev-panelname{display:block;font-weight:700;font-size:15px;color:#1d2327;
		white-space:normal!important;overflow-wrap:anywhere;word-break:break-word}
	.aqm-rev .aqm-rev-panelmeta{display:block;font-size:14px;color:#3c4043;margin-top:3px}
	.aqm-rev .aqm-rev-panelmeta .aqm-rev-star{font-size:17px;letter-spacing:2px}

	.aqm-rev .aqm-rev-list{padding:0 20px}
	.aqm-rev .aqm-rev-item{padding:18px 0;border-bottom:1px solid #edeff1}
	.aqm-rev .aqm-rev-item:last-child{border-bottom:0}
	.aqm-rev .aqm-rev-who{display:flex;align-items:center;gap:12px}
	.aqm-rev .aqm-rev-initials{width:40px;height:40px;flex:0 0 40px;border-radius:50%;
		display:flex;align-items:center;justify-content:center;background:#eef1f4;
		color:#5f6368;font-weight:700;font-size:15px}
	.aqm-rev .aqm-rev-whotext{min-width:0}
	.aqm-rev .aqm-rev-itemname{display:block;font-weight:600;font-size:15px;color:#1d2327}
	.aqm-rev .aqm-rev-itemmeta{display:block;line-height:1;margin-top:4px}
	.aqm-rev .aqm-rev-itemmeta .aqm-rev-star{font-size:16px;letter-spacing:2px}
	.aqm-rev .aqm-rev-when{color:#70757a;font-size:13px;margin-left:8px;letter-spacing:0}
	.aqm-rev .aqm-rev-text{margin:10px 0 0;color:#3c434a}

	.aqm-rev .aqm-rev-foot{display:flex;flex-wrap:wrap;gap:10px;align-items:center;
		padding:16px 20px;border-top:1px solid #edeff1;background:#fafbfc}
	.aqm-rev .aqm-rev-cta{display:inline-block;background:#1a73e8;color:#fff!important;
		text-decoration:none;font-weight:600;font-size:14px;padding:10px 16px;
		border-radius:8px;line-height:1.2}
	.aqm-rev .aqm-rev-cta:hover{background:#1667d4;color:#fff!important}
	.aqm-rev .aqm-rev-write{font-size:14px;color:#1a73e8;text-decoration:none;font-weight:600}
	.aqm-rev .aqm-rev-write:hover{text-decoration:underline}

	/* ---- the scrolling window ----
	   A flex track twice the width of its cards, slid left by half of itself on
	   a loop, so the second copy arrives exactly where the first began and the
	   join is invisible. Widths come from the server as plain percentages. */
	.aqm-rev.aqm-rev-scroll{margin-top:18px;max-width:100%}
	.aqm-rev .aqm-rev-strip{overflow:hidden;padding:4px 0}
	.aqm-rev .aqm-rev-track{display:flex;align-items:stretch}
	.aqm-rev .aqm-rev-track.is-looping{animation-name:aqm-rev-marquee;
		animation-timing-function:linear;animation-iteration-count:infinite}
	@keyframes aqm-rev-marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}

	/* Stop while it is being read - pointer inside, or keyboard focus on a card. */
	.aqm-rev .aqm-rev-strip:hover .aqm-rev-track,
	.aqm-rev .aqm-rev-strip:focus-within .aqm-rev-track{animation-play-state:paused}

	.aqm-rev .aqm-rev-slide{flex:0 0 auto;padding:0 8px;display:flex}
	.aqm-rev .aqm-rev-card{display:flex;flex-direction:column;gap:10px;width:100%;
		background:#fff;color:#1d2327!important;text-decoration:none!important;
		border:1px solid #e4e6e8;border-radius:12px;padding:16px 18px;
		box-shadow:0 4px 18px rgba(15,23,42,.10);transition:box-shadow .15s,transform .15s}
	.aqm-rev .aqm-rev-card:hover,
	.aqm-rev .aqm-rev-card:focus{box-shadow:0 8px 26px rgba(15,23,42,.18);
		transform:translateY(-1px);color:#1d2327!important;text-decoration:none!important}
	.aqm-rev .aqm-rev-cardtop{display:flex;align-items:center;gap:10px}
	.aqm-rev .aqm-rev-cardwho{min-width:0;flex:1 1 auto}
	.aqm-rev .aqm-rev-cardname{display:block;font-weight:600;font-size:15px;
		white-space:normal!important;overflow-wrap:anywhere}
	.aqm-rev .aqm-rev-cardmeta{display:block;line-height:1;margin-top:4px}
	.aqm-rev .aqm-rev-cardmeta .aqm-rev-star{font-size:16px;letter-spacing:2px}
	.aqm-rev .aqm-rev-cardtext{display:block;color:#3c434a;font-size:14px;line-height:1.55}
	.aqm-rev .aqm-rev-cardnote{display:block;color:#70757a;font-size:14px;font-style:italic}
	.aqm-rev .aqm-rev-card .aqm-rev-g{width:20px;height:20px;flex:0 0 20px}

	/* Anyone who has asked their system for less motion gets a still window they
	   can scroll by hand, which is the better experience anyway. */
	@media (prefers-reduced-motion:reduce){
		.aqm-rev .aqm-rev-track.is-looping{animation:none;width:auto!important}
		.aqm-rev .aqm-rev-strip{overflow-x:auto;scroll-snap-type:x mandatory}
		.aqm-rev .aqm-rev-slide{scroll-snap-align:start;width:auto!important;
			flex:0 0 clamp(240px,32%,340px)}
		/* The second copy of the cards exists only to make the animation loop.
		   With nothing animating it would just be every review twice. */
		.aqm-rev .aqm-rev-slide[aria-hidden="true"]{display:none}
	}

	/* On a phone, three or four cards side by side is unreadable at any size, so
	   the window becomes a swipeable one-at-a-time strip. */
	@media (max-width:700px){
		.aqm-rev .aqm-rev-track{width:auto!important;animation:none!important}
		.aqm-rev .aqm-rev-strip{overflow-x:auto;scroll-snap-type:x mandatory;
			-webkit-overflow-scrolling:touch}
		.aqm-rev .aqm-rev-slide{width:auto!important;flex:0 0 84%;scroll-snap-align:start}
		.aqm-rev .aqm-rev-slide[aria-hidden="true"]{display:none}
	}

	/* ---- the sitewide float ----
	   Left corner by default: the WhatsApp button owns the right one, and two
	   boxes stacked in the same corner compete for the same thumb. The wrapper
	   is what is pinned, so the badge and its one review card move together. */
	.aqm-rev-floatwrap{position:fixed;bottom:20px;z-index:9990;margin:0;
		display:flex;align-items:stretch;gap:12px;width:auto;
		max-width:calc(100% - 40px)}
	/* The opposite edge is cleared outright, not left unset: a stale rule from
	   an older version - or a theme that pins things in the other corner - has
	   to lose to the setting rather than fight it. */
	.aqm-rev-floatwrap.aqm-rev-float-left{left:20px;right:auto;flex-direction:row}
	/* Mirrored, so the cards still run inward and never off the screen edge. */
	.aqm-rev-floatwrap.aqm-rev-float-right{right:20px;left:auto;flex-direction:row-reverse}
	.aqm-rev-floatwrap .aqm-rev-scroll{margin-top:0;min-width:0;display:flex;
		align-items:center}
	.aqm-rev-floatwrap .aqm-rev-scroll .aqm-rev-strip{width:100%;padding:0}
	/* A corner card is short by design, so a long review is clipped rather than
	   allowed to set the height of the whole bar. Lower "Words per card" in
	   Settings if the cut is landing badly. */
	.aqm-rev-floatwrap .aqm-rev-cardtop{gap:8px}
	.aqm-rev-floatwrap .aqm-rev-initials{width:32px;height:32px;flex:0 0 32px;font-size:13px}
	@media print{.aqm-rev-floatwrap{display:none!important}}

	@media (max-width:480px){
		.aqm-rev .aqm-rev-badge{gap:12px;padding:14px}
		.aqm-rev .aqm-rev-avatar{height:72px}
		.aqm-rev .aqm-rev-avatar-fallback{width:72px;height:72px;flex:0 0 72px;font-size:26px}
		.aqm-rev .aqm-rev-name{font-size:14px}
		.aqm-rev .aqm-rev-count{font-size:14px}
		.aqm-rev .aqm-rev-star{font-size:24px;letter-spacing:0}
		.aqm-rev .aqm-rev-g{width:20px;height:20px;flex:0 0 20px}
	}

	/* Compact float on phones: photo, stars, count. The heading is the longest
	   line by far, and on a 360px screen it would push the box across most of
	   the display and sit over the content. It stays in the accessible name of
	   the link, so a screen reader still announces whose reviews these are.
	   Only the FLOAT is compacted - a badge placed in page content keeps its
	   heading, because there it has the width for it. */
	@media (max-width:767px){
		.aqm-rev-floatwrap{bottom:14px;max-width:calc(100% - 28px)}
		.aqm-rev-floatwrap.aqm-rev-float-left{left:14px;right:auto}
		.aqm-rev-floatwrap.aqm-rev-float-right{right:14px;left:auto}
		.aqm-rev-floatwrap .aqm-rev-name{display:none}
		.aqm-rev-floatwrap .aqm-rev-badge{gap:10px;padding:10px 14px}
		.aqm-rev-floatwrap .aqm-rev-avatar{height:58px}
		.aqm-rev-floatwrap .aqm-rev-avatar-fallback{width:58px;height:58px;
			flex:0 0 58px;font-size:22px}
		.aqm-rev-floatwrap .aqm-rev-starsrow{margin-bottom:2px}
		.aqm-rev-floatwrap .aqm-rev-star{font-size:22px;letter-spacing:0}
		.aqm-rev-floatwrap .aqm-rev-count{font-size:13px}
		.aqm-rev-floatwrap .aqm-rev-g{width:18px;height:18px;flex:0 0 18px}
		/* The corner card would cover the page on a phone. The badge stays. */
		.aqm-rev-floatwrap .aqm-rev-scroll{display:none}
	}
	</style>
	<?php
	aqm_rev_size_styles();
	aqm_rev_footer_stack_css();
	aqm_rev_float_hide_js();
	// No JavaScript is printed. The badge is a link, not a disclosure widget:
	// one click goes straight to Google, so there is nothing to toggle.
}

/**
 * The adjustable sizes, printed after the main stylesheet so they win.
 *
 * Everything is in PIXELS. Centimetres would have to be converted by the
 * browser against an assumed screen DPI that is wrong on most screens, and
 * "characters" (the `ch` unit) is the width of a zero in whatever font the
 * theme happens to apply - so the same number gives a different box on a
 * different page. A pixel is the one unit that means the same thing
 * everywhere here, and it is what every other size on a web page is set in.
 *
 * 0 for width or height means "fit the content", which is what the badge did
 * before any of it was adjustable.
 */
/**
 * A CSS selector list, safe to drop inside a <style> block.
 *
 * This value reaches the page unescaped by necessity - a selector cannot be
 * esc_attr'd and still work - so everything that could end the rule, end the
 * style element, or start a new declaration is removed rather than encoded.
 */
function aqm_rev_safe_selector( $raw ) {
	$sel = preg_replace( '/[^A-Za-z0-9 ,.\#_\-\[\]=":()>+~*]/', '', (string) $raw );
	$sel = str_replace( array( '{', '}', ';', '<', '>' ), '', $sel );
	return trim( preg_replace( '/\s+/', ' ', $sel ) );
}

/**
 * Keep the footer above the floating badge.
 *
 * Printed only when the float is actually on the page - there is nothing to
 * stack against otherwise.
 */
/**
 * Hide the floating bar once the footer is on screen.
 *
 * WHY THIS IS THE ONE PIECE OF JAVASCRIPT IN THE PLUGIN
 *
 * The bar is position:fixed, so at the end of a page it lands on the footer -
 * which is where the RECO brokerage identification line lives. Raising the
 * footer's z-index (see aqm_rev_footer_stack_css) puts the footer in front,
 * but only helps if the footer has a background of its own. On a transparent
 * footer the two simply overlap and both become unreadable.
 *
 * Getting out of the way entirely is the only approach that works regardless
 * of what the footer looks like, and it cannot be done in CSS: nothing in CSS
 * can ask "is that other element currently visible?".
 *
 * IntersectionObserver is used rather than a scroll handler - the browser
 * reports the crossing itself, so nothing runs while the page is scrolling.
 * If the API is missing the bar simply never hides, which is where we were
 * before, so an old browser loses the improvement but nothing breaks.
 */
function aqm_rev_float_hide_js() {

	$profile = aqm_rev_profile();

	$hide = ! empty( $profile['float_hide'] );
	$sel  = aqm_rev_safe_selector( $profile['footer_sel'] );
	$gap  = max( 0, (int) $profile['float_gap'] );

	if ( ! $hide || '' === $sel ) {
		$sel  = '';
		$hide = false;
	}

	?>
<style id="aqm-rev-float-hide">
.aqm-rev-floatwrap{transition:opacity .18s ease,transform .18s ease}
.aqm-rev-floatwrap.aqm-rev-tucked{opacity:0;transform:translateY(12px);
	pointer-events:none;visibility:hidden}
@media (prefers-reduced-motion:reduce){
	.aqm-rev-floatwrap{transition:none}
	.aqm-rev-floatwrap.aqm-rev-tucked{transform:none}
}
</style>
<script id="aqm-rev-float-hide-js">
(function(){
	var MIN = <?php echo (int) $gap; ?>;
	var SEL = <?php echo $sel ? wp_json_encode( $sel ) : 'null'; ?>;

	var bar = null;

	/*
	 * Move clear of whatever else is pinned in the same corner.
	 *
	 * A fixed chat bubble - WhatsApp, Messenger, a support widget - lives in a
	 * bottom corner on most sites, and a fixed number cannot know how wide it
	 * is. So measure: look at the elements pinned near the same corner, take
	 * the widest intrusion, and sit outside it. The saved setting is the floor,
	 * so the bar never ends up closer to the edge than intended.
	 *
	 * Only body-level children are examined. Floating widgets are appended to
	 * the body by every plugin that makes them, and walking the whole document
	 * to find them would cost far more than it is worth.
	 */
	var clear = function(){
		if(!bar){return;}
		var right = bar.classList.contains('aqm-rev-float-right');
		var side  = right ? 'right' : 'left';
		var vw = window.innerWidth, vh = window.innerHeight, need = MIN;

		Array.prototype.forEach.call(document.body.children, function(el){
			if(el === bar || el.contains(bar) || bar.contains(el)){return;}
			var cs = window.getComputedStyle(el);
			if(cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden'){return;}
			var r = el.getBoundingClientRect();
			// A button, not a banner: small, near the bottom, on our side.
			if(!r.width || !r.height || r.width > 240){return;}
			if(r.bottom < vh - 220){return;}
			if(right ? (r.right < vw - 240) : (r.left > 240)){return;}
			need = Math.max(need, (right ? (vw - r.left) : r.right) + 14);
		});

		// Clear the edge we are NOT using. Setting only one side leaves whatever
		// pinned the other one still in force.
		bar.style.setProperty(right ? 'left' : 'right', 'auto');
		bar.style.setProperty(side, Math.round(need) + 'px');
		bar.style.setProperty('max-width', 'calc(100% - ' + Math.round(need + 20) + 'px)');
	};

	var run = function(){
		bar = document.querySelector('.aqm-rev-floatwrap');
		if(!bar){return;}

		clear();

		if(SEL && window.IntersectionObserver){
			var marks = document.querySelectorAll(SEL);
			if(marks.length){
				var seen = 0;
				var io = new IntersectionObserver(function(entries){
					entries.forEach(function(e){ seen += e.isIntersecting ? 1 : -1; });
					if(seen < 0){ seen = 0; }
					bar.classList.toggle('aqm-rev-tucked', seen > 0);
				},{threshold:0});
				Array.prototype.forEach.call(marks, function(m){ io.observe(m); });
			}
		}
	};

	var t;
	window.addEventListener('resize', function(){ clearTimeout(t); t = setTimeout(clear, 150); });
	// Chat widgets often inject themselves late, so measure again once
	// everything has finished loading.
	window.addEventListener('load', function(){ setTimeout(clear, 400); });

	if(document.readyState === 'loading'){
		document.addEventListener('DOMContentLoaded', run);
	}else{
		run();
	}
})();
</script>
	<?php
}

function aqm_rev_footer_stack_css() {

	$profile = aqm_rev_profile();
	$sel     = aqm_rev_safe_selector( $profile['footer_sel'] );

	if ( '' === $sel ) {
		return;
	}

	/**
	 * Filter the selector the float must stay behind.
	 *
	 * @param string $sel Comma-separated CSS selector list.
	 */
	$sel = aqm_rev_safe_selector( apply_filters( 'aqm_rev_footer_selector', $sel ) );
	if ( '' === $sel ) {
		return;
	}

	printf(
		'<style id="aqm-rev-footer-stack">%s{position:relative;z-index:9991}</style>' . "\n",
		$sel // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised above; a selector cannot be escaped and still function.
	);
}

function aqm_rev_size_styles() {

	$profile = aqm_rev_profile();

	$width  = max( 0, (int) $profile['box_width'] );
	$height = max( 0, (int) $profile['box_height'] );
	$font   = max( 10, (int) $profile['box_font'] );
	$photo  = max( 24, (int) $profile['box_photo'] );

	$css = sprintf( '.aqm-rev{font-size:%dpx}', $font );

	/*
	 * Transparent boxes.
	 *
	 * The border and shadow stay - without them a transparent card has no edge
	 * at all and the text floats loose over whatever is behind it. The text
	 * colour is left alone deliberately: forcing white here would be a guess
	 * about the page behind, and a wrong guess is invisible text.
	 */
	if ( 'transparent' === $profile['box_bg'] ) {
		$css .= '.aqm-rev .aqm-rev-badge,.aqm-rev .aqm-rev-card{background:transparent;'
			. 'backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}';
	}

	// The photo is the badge's height driver, so it is set on its own.
	$css .= sprintf(
		'.aqm-rev .aqm-rev-avatar{height:%1$dpx}'
		. '.aqm-rev .aqm-rev-avatar-fallback{width:%1$dpx;height:%1$dpx;flex:0 0 %1$dpx;'
		. 'font-size:%2$dpx}',
		$photo,
		max( 12, (int) round( $photo / 2.9 ) )
	);

	// Width and height apply to the badge AND to every review card, so the two
	// read as one component rather than two boxes that happen to be adjacent.
	if ( $width ) {
		$css .= sprintf(
			'.aqm-rev .aqm-rev-badge{width:%1$dpx;max-width:100%%}'
			. '.aqm-rev .aqm-rev-slide{flex:0 0 %1$dpx}',
			$width
		);
	}
	if ( $height ) {
		$css .= sprintf(
			'.aqm-rev .aqm-rev-badge,.aqm-rev .aqm-rev-card{min-height:%1$dpx}'
			. '.aqm-rev .aqm-rev-card{overflow:hidden}',
			$height
		);
	}

	// Stars and the Google mark scale with the text, so raising the size does
	// not leave a large name beside tiny stars.
	$css .= sprintf(
		'.aqm-rev .aqm-rev-star{font-size:%dpx}'
		. '.aqm-rev .aqm-rev-g{width:%2$dpx;height:%2$dpx;flex:0 0 %2$dpx}',
		(int) round( $font * 2 ),
		(int) round( $font * 1.6 )
	);

	// In the corner the cards are pinned to the SAME HEIGHT as the badge beside
	// them, so the float reads as one bar rather than a small box next to three
	// tall ones. The badge's height is its photo plus its padding and border,
	// unless a height has been set, in which case that wins. CSS cannot measure
	// a sibling, so the number is worked out here and written in.
	/*
	 * In the corner, HEIGHT IS AUTHORITATIVE FOR BOTH BOXES.
	 *
	 * Until 1.7.0 the height was a fixed height on the review strip but only a
	 * min-height on the badge, and the badge's real height came from its photo
	 * plus padding. Setting a height below that left the badge tall and the
	 * cards short - they could only match by coincidence. The photo now shrinks
	 * to fit the height instead of dictating it, so the bar is one consistent
	 * band whatever the number.
	 *
	 * A floor of 56px applies to the float only. Below that the stars and text
	 * are unreadable and the badge stops being a link anyone can hit; an
	 * in-page badge is left alone, because there the height is a minimum and
	 * the content is allowed to win.
	 */
	$float_h = $height ? max( 56, $height ) : $photo + 30;

	// Padding (10px top and bottom) and the 1px border on each edge.
	$float_pad  = 22;
	$float_face = max( 24, $float_h - $float_pad );

	/*
	 * Distance from the corner.
	 *
	 * The bar defaults to the LEFT because a chat button usually owns the
	 * right. When it is put on the right anyway - which is a perfectly
	 * reasonable choice - the two land on top of each other, so the gap has to
	 * be adjustable rather than a fixed 20px. Applied to the horizontal side
	 * only: the bottom stays put, because raising the bar would push it over
	 * page content instead.
	 */
	$gap  = max( 0, (int) $profile['float_gap'] );
	$css .= sprintf(
		'.aqm-rev-floatwrap.aqm-rev-float-left{left:%1$dpx;right:auto}'
		. '.aqm-rev-floatwrap.aqm-rev-float-right{right:%1$dpx;left:auto}'
		. '.aqm-rev-floatwrap{max-width:calc(100%% - %2$dpx)}',
		$gap,
		max( 40, $gap + 20 )
	);

	$css .= sprintf(
		// Both children of the bar get the SAME explicit height.
		'.aqm-rev-floatwrap .aqm-rev-badge{height:%1$dpx;min-height:0;'
		. 'padding:10px 14px;box-sizing:border-box}'
		. '.aqm-rev-floatwrap .aqm-rev-strip{height:%1$dpx}'
		// The photo fits the band rather than setting it.
		. '.aqm-rev-floatwrap .aqm-rev-avatar{height:auto;max-height:100%%;width:auto}'
		. '.aqm-rev-floatwrap .aqm-rev-avatar-fallback{width:%2$dpx;height:%2$dpx;'
		. 'flex:0 0 %2$dpx;font-size:%3$dpx}'
		// Cards fill the band exactly, and clip rather than push it taller.
		. '.aqm-rev-floatwrap .aqm-rev-scroll{align-items:stretch}'
		. '.aqm-rev-floatwrap .aqm-rev-track{height:100%%}'
		. '.aqm-rev-floatwrap .aqm-rev-slide{align-items:stretch;height:100%%}'
		. '.aqm-rev-floatwrap .aqm-rev-card{height:100%%;box-sizing:border-box;'
		. 'overflow:hidden;gap:6px;padding:10px 14px}'
		. '.aqm-rev-floatwrap .aqm-rev-cardtext{font-size:%4$dpx;line-height:1.4;'
		. 'overflow:hidden}',
		$float_h,
		$float_face,
		max( 10, (int) round( $float_face / 2.9 ) ),
		max( 11, (int) round( $font * 0.88 ) )
	);

	/*
	 * NOTHING PAINTS OUTSIDE THE CORNER BADGE.
	 *
	 * The badge in the float has a fixed height, but until 1.11.0 its contents
	 * knew nothing about that height: the stars are text at twice the body
	 * size and the Google mark is 1.6x it, both sized from the text setting
	 * alone. Set a short band and the star row and the mark simply painted
	 * past the edge of the box - which is what AQ saw on 8 Sep 2026.
	 *
	 * The band is authoritative. What does not fit is made to fit, in this
	 * order:
	 *
	 *   1. Three rows at full size, if they fit. Nothing changes - the default
	 *      profile lands here, so an untouched site looks exactly as before.
	 *   2. Drop the long heading line. It is the tallest thing that is not the
	 *      rating, and the phone layout has always dropped it for the same
	 *      reason.
	 *   3. Shrink the stars, the mark and the count until the rest fits.
	 *
	 * overflow:hidden on the badge is the backstop, not the mechanism. At the
	 * 56px floor the arithmetic already fits; the clip only ever catches a
	 * font whose glyphs sit further outside their em box than the allowance
	 * below expects.
	 */
	$row_gap = 4;

	// The count line, sized from the text setting but never taller than about
	// a third of the band - a 15px line inside a 34px band leaves no room for
	// the rating it is meant to caption.
	$f_count = max( 10, min( (int) round( $font * 0.95 ), max( 10, (int) floor( $float_face * 0.32 ) ) ) );
	$count_h = (int) ceil( $f_count * 1.25 );

	// The heading line: body size, its own 5px margin included.
	$name_h = (int) round( $font * 1.3 ) + 5;

	/*
	 * A star glyph paints a little outside its em box, so the row it occupies
	 * is taller than its font-size. 1.15 is that allowance; it is why the
	 * budget is divided by 1.15 rather than used directly.
	 */
	$star_nat = (int) round( $font * 2 );
	$g_nat    = (int) round( $font * 1.6 );
	$stars_h  = (int) ceil( max( $star_nat, $g_nat ) * 1.15 );

	$show_name = ( $name_h + $stars_h + $count_h + ( 2 * $row_gap ) ) <= $float_face;

	$budget = $float_face - $count_h - $row_gap - ( $show_name ? $name_h + $row_gap : 0 );
	$f_star = max( 12, min( $star_nat, (int) floor( $budget / 1.15 ) ) );
	$f_g    = max( 12, min( $g_nat, $f_star ) );

	$css .= sprintf(
		// The box itself is the boundary. Nothing inside it may be drawn past
		// this line, whatever a theme or a font does.
		'.aqm-rev-floatwrap .aqm-rev-badge{overflow:hidden}'
		// The text column centres in the band instead of overflowing it.
		. '.aqm-rev-floatwrap .aqm-rev-body{display:flex;flex-direction:column;'
		. 'justify-content:center;max-height:100%%;overflow:hidden}'
		/*
		 * The heading line, in a min-width query for two reasons: display:none
		 * has to come after the display:-webkit-box that clamps it to one line
		 * or it loses to it, and the phone rule that already hides this line
		 * lives in an earlier style block, where a later rule of equal
		 * specificity would otherwise bring it back.
		 */
		. '@media (min-width:481px){.aqm-rev-floatwrap .aqm-rev-name{'
		. 'margin-bottom:%2$dpx;display:-webkit-box;-webkit-line-clamp:1;'
		. '-webkit-box-orient:vertical;overflow:hidden}%1$s}'
		. '.aqm-rev-floatwrap .aqm-rev-starsrow{margin-bottom:%2$dpx;'
		. 'flex:0 0 auto;line-height:1}'
		. '.aqm-rev-floatwrap .aqm-rev-stars{display:block;line-height:1;'
		. 'white-space:nowrap}'
		. '.aqm-rev-floatwrap .aqm-rev-star{font-size:%3$dpx;line-height:1;'
		. 'display:inline-block;vertical-align:top}'
		. '.aqm-rev-floatwrap .aqm-rev-g{width:%4$dpx;height:%4$dpx;flex:0 0 %4$dpx}'
		. '.aqm-rev-floatwrap .aqm-rev-count{font-size:%5$dpx;line-height:1.25;'
		. 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
		$show_name ? '' : '.aqm-rev-floatwrap .aqm-rev-name{display:none}',
		$row_gap,
		$f_star,
		$f_g,
		$f_count
	);

	printf( "<style>%s</style>\n", $css ); // phpcs:ignore WordPress.Security.EscapeOutput
}
