<?php
/**
 * Plugin Name: Celectric Travel & Expense Claim Form
 * Description: Online version of the Celectric Travel & Expense Claim Form. Staff log in, fill in the form, and it is saved in WordPress. The subtotals and grand total are sent to a webhook (Make / Power Automate / Zapier) that adds a row to an Excel file on OneDrive. Each claim prints to PDF in the same layout as the Excel form. Add the form with the shortcode [celectric_claim_form] (works in the Elementor Shortcode widget).
 * Version: 1.0.0
 * Author: Celectric Sdn Bhd
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: celectric-claim-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CEL_CLAIM_VERSION', '1.0.0' );
define( 'CEL_CLAIM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CEL_CLAIM_URL', plugin_dir_url( __FILE__ ) );
define( 'CEL_CLAIM_CPT', 'cel_claim' );
define( 'CEL_CLAIM_OPTION', 'cel_claim_settings' );

/* -------------------------------------------------------------------------
 * Fixed internal schedule (Internal Guideline, September 2026).
 * The amounts are taken from these lists on the server, so staff cannot
 * change the rates by editing the page.
 * ---------------------------------------------------------------------- */

function cel_claim_outstation_rates() {
	return array(
		'None'             => 0,
		'Full Day (RM 80)' => 80,
		'Half Day (RM 40)' => 40,
	);
}

function cel_claim_meal_rates() {
	return array(
		'None'                     => 0,
		'Breakfast (RM 15)'        => 15,
		'Lunch (RM 20)'            => 20,
		'Dinner (RM 25)'           => 25,
		'Breakfast + Lunch (RM 35)' => 35,
		'Lunch + Dinner (RM 45)'   => 45,
		'Full Meals (RM 60)'       => 60,
	);
}

function cel_claim_expense_categories() {
	return array(
		'Hotel (Company-Approved)',
		'Toll Expenses',
		'Parking Fees',
		'Public Transport',
		'Grab / Taxi',
		'Flight / Train',
		'Equipment Transport',
		'Other',
	);
}

/** Minimum number of rows shown in each section (same as the Excel form). */
define( 'CEL_CLAIM_ROWS_A', 9 );
define( 'CEL_CLAIM_ROWS_B', 7 );
/** Upper limit on rows, to stop runaway submissions. */
define( 'CEL_CLAIM_MAX_ROWS', 40 );

function cel_claim_default_settings() {
	return array(
		'webhook_url'  => '',
		'notify_email' => '',
		'logo_url'     => '',
		'title'        => 'TRAVEL & EXPENSE CLAIM FORM',
		'subtitle'     => 'Internal Guideline Effective: September 2026',
		'guidelines'   => "Summary Guidelines & Compliance (Celectric Sept 2026):\n"
			. "• Meals & Incidentals: Max RM60/day (Breakfast: RM15, Lunch: RM20, Dinner: RM25,\n"
			. "  Breakfast+Lunch: RM35, Lunch+Dinner: RM45).\n"
			. "• Outstation Allowance: Full day = RM80; Half day = RM40 (compensates inconvenience).\n"
			. "• Receipts Requirement: Original invoices required for Hotel, Flight/Train, Grab, Toll & Parking.\n"
			. "• Strict Exclusions: Alcohol, traffic summons, personal expenses, and family costs are non-claimable.",
	);
}

function cel_claim_settings() {
	$saved = get_option( CEL_CLAIM_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), cel_claim_default_settings() );
}

/* -------------------------------------------------------------------------
 * Storage: one private custom post per claim, data kept in post meta.
 * ---------------------------------------------------------------------- */

add_action( 'init', 'cel_claim_register_post_type' );
function cel_claim_register_post_type() {
	register_post_type(
		CEL_CLAIM_CPT,
		array(
			'labels'          => array(
				'name'          => 'Expense Claims',
				'singular_name' => 'Expense Claim',
				'menu_name'     => 'Expense Claims',
				'all_items'     => 'All Claims',
				'edit_item'     => 'Claim',
				'search_items'  => 'Search Claims',
				'not_found'     => 'No claims yet.',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'menu_icon'       => 'dashicons-media-spreadsheet',
			'supports'        => array( 'title', 'author' ),
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
			'show_in_rest'    => false,
		)
	);
}

/** Returns the stored claim data array, or null. */
function cel_claim_get( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || CEL_CLAIM_CPT !== $post->post_type ) {
		return null;
	}
	$data = get_post_meta( $post->ID, '_cel_claim', true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$data['id']        = $post->ID;
	$data['author_id'] = (int) $post->post_author;
	return $data;
}

function cel_claim_number( $post_id ) {
	return 'CLM-' . get_the_date( 'Y', $post_id ) . '-' . str_pad( (string) $post_id, 5, '0', STR_PAD_LEFT );
}

/** Owner of the claim, or anyone who can manage other people's posts (Editor/Admin). */
function cel_claim_user_can_view( $claim ) {
	if ( ! is_user_logged_in() || ! $claim ) {
		return false;
	}
	return get_current_user_id() === (int) $claim['author_id'] || current_user_can( 'edit_others_posts' );
}

function cel_claim_print_url( $post_id ) {
	return add_query_arg( 'cel_claim_print', (int) $post_id, home_url( '/' ) );
}

function cel_claim_money( $n ) {
	return number_format( (float) $n, 2, '.', ',' );
}

/* -------------------------------------------------------------------------
 * Validation & totals (always recalculated on the server).
 * ---------------------------------------------------------------------- */

function cel_claim_clean_date( $v ) {
	$v = sanitize_text_field( (string) $v );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
}

function cel_claim_display_date( $ymd ) {
	if ( ! $ymd ) {
		return '';
	}
	$ts = strtotime( $ymd );
	return $ts ? gmdate( 'j/n/Y', $ts ) : '';
}

/**
 * Builds a clean claim array from raw POST data.
 *
 * @return array{0: array, 1: string[]} [claim, errors]
 */
function cel_claim_build_from_request( $raw ) {
	$out_rates  = cel_claim_outstation_rates();
	$meal_rates = cel_claim_meal_rates();
	$categories = cel_claim_expense_categories();
	$errors     = array();

	$text = function ( $key, $max = 200 ) use ( $raw ) {
		$v = isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';
		return mb_substr( $v, 0, $max );
	};

	$claim = array(
		'claimant_name' => $text( 'claimant_name' ),
		'purpose'       => $text( 'purpose' ),
		'destination'   => $text( 'destination' ),
		'travel_period' => $text( 'travel_period' ),
		'department'    => $text( 'department' ),
		'allowances'    => array(),
		'expenses'      => array(),
	);

	foreach ( array( 'claimant_name' => 'Claimant Name', 'purpose' => 'Purpose of Travel', 'department' => 'Department / Project' ) as $k => $label ) {
		if ( '' === $claim[ $k ] ) {
			$errors[] = $label . ' is required.';
		}
	}

	$a       = isset( $raw['a'] ) && is_array( $raw['a'] ) ? wp_unslash( $raw['a'] ) : array();
	$sub_a   = 0.0;
	$a_count = 0;
	foreach ( array_slice( array_values( $a ), 0, CEL_CLAIM_MAX_ROWS ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$date    = cel_claim_clean_date( $row['date'] ?? '' );
		$details = mb_substr( sanitize_text_field( $row['details'] ?? '' ), 0, 200 );
		$out     = sanitize_text_field( $row['outstation'] ?? 'None' );
		$meal    = sanitize_text_field( $row['meal'] ?? 'None' );
		$out     = isset( $out_rates[ $out ] ) ? $out : 'None';
		$meal    = isset( $meal_rates[ $meal ] ) ? $meal : 'None';
		$total   = $out_rates[ $out ] + $meal_rates[ $meal ];
		if ( '' === $date && '' === $details && 0 === $total ) {
			continue; // Empty row.
		}
		$a_count++;
		if ( '' === $date ) {
			$errors[] = "Section A row {$a_count}: date is required.";
		}
		$claim['allowances'][] = compact( 'date', 'details', 'out', 'meal', 'total' );
		$sub_a                += $total;
	}

	$b       = isset( $raw['b'] ) && is_array( $raw['b'] ) ? wp_unslash( $raw['b'] ) : array();
	$sub_b   = 0.0;
	$b_count = 0;
	foreach ( array_slice( array_values( $b ), 0, CEL_CLAIM_MAX_ROWS ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$date     = cel_claim_clean_date( $row['date'] ?? '' );
		$category = sanitize_text_field( $row['category'] ?? '' );
		$category = in_array( $category, $categories, true ) ? $category : '';
		$merchant = mb_substr( sanitize_text_field( $row['merchant'] ?? '' ), 0, 200 );
		$receipt  = mb_substr( sanitize_text_field( $row['receipt'] ?? '' ), 0, 100 );
		$amt_raw  = str_replace( array( ',', 'RM', ' ' ), '', (string) ( $row['amount'] ?? '' ) );
		$amount   = is_numeric( $amt_raw ) ? round( max( 0, (float) $amt_raw ), 2 ) : 0.0;
		if ( '' === $date && '' === $category && '' === $merchant && '' === $receipt && 0.0 === $amount ) {
			continue;
		}
		$b_count++;
		if ( '' === $date ) {
			$errors[] = "Section B row {$b_count}: date is required.";
		}
		if ( '' === $category ) {
			$errors[] = "Section B row {$b_count}: choose an expense category.";
		}
		if ( $amount <= 0 ) {
			$errors[] = "Section B row {$b_count}: enter an amount.";
		}
		$claim['expenses'][] = compact( 'date', 'category', 'merchant', 'receipt', 'amount' );
		$sub_b              += $amount;
	}

	if ( 0 === $a_count && 0 === $b_count ) {
		$errors[] = 'Enter at least one allowance (Section A) or expense (Section B).';
	}

	$claim['subtotal_a']  = round( $sub_a, 2 );
	$claim['subtotal_b']  = round( $sub_b, 2 );
	$claim['grand_total'] = round( $sub_a + $sub_b, 2 );

	return array( $claim, $errors );
}

/* -------------------------------------------------------------------------
 * Saving a submission.
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_cel_claim_save', 'cel_claim_handle_save' );
add_action( 'admin_post_nopriv_cel_claim_save', 'cel_claim_handle_save' );
function cel_claim_handle_save() {
	$back = isset( $_POST['_cel_back'] ) ? esc_url_raw( wp_unslash( $_POST['_cel_back'] ) ) : home_url( '/' );
	$back = wp_validate_redirect( $back, home_url( '/' ) );

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( $back ) );
		exit;
	}
	if ( ! isset( $_POST['_cel_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_cel_nonce'] ), 'cel_claim_save' ) ) {
		wp_die( 'Your session has expired. Please go back, refresh the page and submit again.', 'Claim not saved', array( 'back_link' => true ) );
	}

	list( $claim, $errors ) = cel_claim_build_from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.

	if ( $errors ) {
		// Keep what the staff member typed so they don't lose it.
		$key = 'cel_claim_draft_' . get_current_user_id();
		set_transient( $key, array( 'raw' => wp_unslash( $_POST ), 'errors' => $errors ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'cel_claim_error', 1, remove_query_arg( array( 'cel_claim_saved', 'cel_claim_error' ), $back ) ) . '#cel-claim' );
		exit;
	}

	$user                     = wp_get_current_user();
	$claim['submission_date'] = current_time( 'Y-m-d' );
	$claim['submitted_at']    = current_time( 'mysql' );
	$claim['staff_email']     = $user->user_email;
	$claim['staff_login']     = $user->user_login;

	$post_id = wp_insert_post(
		array(
			'post_type'   => CEL_CLAIM_CPT,
			'post_status' => 'publish',
			'post_author' => $user->ID,
			'post_title'  => $claim['claimant_name'] . ' – ' . $claim['purpose'],
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		wp_die( 'The claim could not be saved: ' . esc_html( $post_id->get_error_message() ), 'Claim not saved', array( 'back_link' => true ) );
	}
	wp_update_post( array( 'ID' => $post_id, 'post_title' => cel_claim_number( $post_id ) . ' – ' . $claim['claimant_name'] . ' – ' . $claim['purpose'] ) );

	$claim['claim_no'] = cel_claim_number( $post_id );
	update_post_meta( $post_id, '_cel_claim', $claim );
	update_post_meta( $post_id, '_cel_grand_total', $claim['grand_total'] );
	delete_transient( 'cel_claim_draft_' . $user->ID );

	cel_claim_send_webhook( $post_id );
	cel_claim_send_email( $post_id );

	wp_safe_redirect( add_query_arg( 'cel_claim_saved', $post_id, remove_query_arg( array( 'cel_claim_saved', 'cel_claim_error' ), $back ) ) . '#cel-claim' );
	exit;
}

/** The single row that goes to the Excel file on OneDrive: header info + totals only. */
function cel_claim_webhook_payload( $post_id ) {
	$c = cel_claim_get( $post_id );
	return array(
		'claim_no'        => $c['claim_no'],
		'submission_date' => cel_claim_display_date( $c['submission_date'] ),
		'submitted_at'    => $c['submitted_at'],
		'claimant_name'   => $c['claimant_name'],
		'staff_email'     => $c['staff_email'],
		'department'      => $c['department'],
		'purpose'         => $c['purpose'],
		'destination'     => $c['destination'],
		'travel_period'   => $c['travel_period'],
		'subtotal_a'      => $c['subtotal_a'],
		'subtotal_b'      => $c['subtotal_b'],
		'grand_total'     => $c['grand_total'],
		'print_url'       => cel_claim_print_url( $post_id ),
	);
}

function cel_claim_send_webhook( $post_id ) {
	$url = trim( cel_claim_settings()['webhook_url'] );
	if ( '' === $url ) {
		update_post_meta( $post_id, '_cel_webhook', 'Not sent – no webhook URL set' );
		return false;
	}
	$res = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( cel_claim_webhook_payload( $post_id ) ),
		)
	);
	if ( is_wp_error( $res ) ) {
		update_post_meta( $post_id, '_cel_webhook', 'Failed: ' . $res->get_error_message() );
		return false;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$ok   = $code >= 200 && $code < 300;
	update_post_meta( $post_id, '_cel_webhook', ( $ok ? 'Sent' : 'Failed: HTTP ' . $code ) . ' – ' . current_time( 'j/n/Y H:i' ) );
	return $ok;
}

function cel_claim_send_email( $post_id ) {
	$to = trim( cel_claim_settings()['notify_email'] );
	if ( '' === $to ) {
		return;
	}
	$p    = cel_claim_webhook_payload( $post_id );
	$body = "A new travel & expense claim has been submitted.\n\n"
		. "Claim No: {$p['claim_no']}\n"
		. "Claimant: {$p['claimant_name']} ({$p['staff_email']})\n"
		. "Department: {$p['department']}\n"
		. "Purpose: {$p['purpose']}\n"
		. 'Subtotal Section A (Allowances): RM ' . cel_claim_money( $p['subtotal_a'] ) . "\n"
		. 'Subtotal Section B (Actual Expenses): RM ' . cel_claim_money( $p['subtotal_b'] ) . "\n"
		. 'TOTAL AMOUNT CLAIMED: RM ' . cel_claim_money( $p['grand_total'] ) . "\n\n"
		. "View / print: {$p['print_url']}\n";
	wp_mail( $to, 'Expense claim ' . $p['claim_no'] . ' – ' . $p['claimant_name'], $body );
}

/* -------------------------------------------------------------------------
 * Front end: shortcode [celectric_claim_form]
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'cel_claim_register_assets' );
function cel_claim_register_assets() {
	wp_register_style( 'cel-claim', CEL_CLAIM_URL . 'assets/claim.css', array(), CEL_CLAIM_VERSION );
	wp_register_script( 'cel-claim', CEL_CLAIM_URL . 'assets/claim.js', array(), CEL_CLAIM_VERSION, true );
}

add_shortcode( 'celectric_claim_form', 'cel_claim_shortcode' );
function cel_claim_shortcode() {
	// Block themes and page builders can render shortcodes before wp_enqueue_scripts runs.
	if ( ! wp_script_is( 'cel-claim', 'registered' ) ) {
		cel_claim_register_assets();
	}
	wp_enqueue_style( 'cel-claim' );

	if ( ! is_user_logged_in() ) {
		$html  = '<div class="cel-claim-login"><h3>Staff login</h3><p>Please log in to submit a travel &amp; expense claim.</p>';
		$html .= wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) );
		$html .= '</div>';
		return $html;
	}

	wp_enqueue_script( 'cel-claim' ); // Rates for the live totals are passed on the sheet itself (data-cel-config).

	$user   = wp_get_current_user();
	$notice = '';
	$draft  = null;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags.
	if ( isset( $_GET['cel_claim_saved'] ) ) {
		$saved = cel_claim_get( absint( $_GET['cel_claim_saved'] ) );
		if ( $saved && cel_claim_user_can_view( $saved ) ) {
			$notice = '<div class="cel-notice cel-notice-ok"><strong>Claim ' . esc_html( $saved['claim_no'] ) . ' submitted.</strong> '
				. 'Total claimed: RM ' . esc_html( cel_claim_money( $saved['grand_total'] ) ) . '. '
				. '<a class="cel-btn" target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $saved['id'] ) ) . '">Print / Save as PDF</a></div>';
		}
	}
	if ( isset( $_GET['cel_claim_error'] ) ) {
		$draft = get_transient( 'cel_claim_draft_' . $user->ID );
		if ( is_array( $draft ) && ! empty( $draft['errors'] ) ) {
			$notice = '<div class="cel-notice cel-notice-err"><strong>Please fix the following and submit again:</strong><ul><li>'
				. implode( '</li><li>', array_map( 'esc_html', $draft['errors'] ) ) . '</li></ul></div>';
		}
	}
	// phpcs:enable

	$raw = is_array( $draft ) ? $draft['raw'] : array();
	$s   = cel_claim_settings();

	// Values for the editable sheet (from a failed submission, or blank).
	$v = array(
		'claimant_name'   => $raw['claimant_name'] ?? $user->display_name,
		'submission_date' => current_time( 'Y-m-d' ),
		'purpose'         => $raw['purpose'] ?? '',
		'destination'     => $raw['destination'] ?? '',
		'travel_period'   => $raw['travel_period'] ?? '',
		'department'      => $raw['department'] ?? '',
		'allowances'      => array(),
		'expenses'        => array(),
	);
	foreach ( ( isset( $raw['a'] ) && is_array( $raw['a'] ) ? array_values( $raw['a'] ) : array() ) as $r ) {
		$v['allowances'][] = array(
			'date'    => $r['date'] ?? '',
			'details' => $r['details'] ?? '',
			'out'     => $r['outstation'] ?? 'None',
			'meal'    => $r['meal'] ?? 'None',
		);
	}
	foreach ( ( isset( $raw['b'] ) && is_array( $raw['b'] ) ? array_values( $raw['b'] ) : array() ) as $r ) {
		$v['expenses'][] = array(
			'date'     => $r['date'] ?? '',
			'category' => $r['category'] ?? '',
			'merchant' => $r['merchant'] ?? '',
			'receipt'  => $r['receipt'] ?? '',
			'amount'   => $r['amount'] ?? '',
		);
	}

	$mode     = 'edit';
	$claim    = $v;
	$settings = $s;

	ob_start();
	echo '<div id="cel-claim" class="cel-claim-wrap">';
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cel-claim-form" novalidate>
		<input type="hidden" name="action" value="cel_claim_save">
		<input type="hidden" name="_cel_back" value="<?php echo esc_url( get_permalink() ); ?>">
		<?php wp_nonce_field( 'cel_claim_save', '_cel_nonce' ); ?>
		<div class="cel-sheet-scroll">
			<?php include CEL_CLAIM_DIR . 'templates/sheet.php'; ?>
		</div>
		<div class="cel-actions">
			<button type="button" class="cel-btn cel-btn-light" data-cel-add="a">+ Add allowance row</button>
			<button type="button" class="cel-btn cel-btn-light" data-cel-add="b">+ Add expense row</button>
			<span class="cel-spacer"></span>
			<button type="submit" class="cel-btn cel-btn-primary">Submit Claim</button>
		</div>
		<p class="cel-hint">Rates for Section A are fixed by the internal schedule. Once submitted, a claim cannot be changed — ask HR/Admin to delete it if you need to resubmit.</p>
	</form>
	<?php
	echo cel_claim_my_claims_table(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	echo '</div>';
	return ob_get_clean();
}

add_shortcode( 'celectric_my_claims', 'cel_claim_my_claims_shortcode' );
function cel_claim_my_claims_shortcode() {
	if ( ! wp_style_is( 'cel-claim', 'registered' ) ) {
		cel_claim_register_assets();
	}
	wp_enqueue_style( 'cel-claim' );
	return is_user_logged_in() ? cel_claim_my_claims_table() : '';
}

function cel_claim_my_claims_table() {
	$q = new WP_Query(
		array(
			'post_type'      => CEL_CLAIM_CPT,
			'post_status'    => 'publish',
			'author'         => get_current_user_id(),
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);
	if ( ! $q->have_posts() ) {
		return '';
	}
	$html = '<h3 class="cel-my-title">My submitted claims</h3><div class="cel-sheet-scroll"><table class="cel-my-claims"><thead><tr>'
		. '<th>Claim No.</th><th>Submitted</th><th>Purpose</th><th class="r">Section A (RM)</th><th class="r">Section B (RM)</th><th class="r">Total (RM)</th><th></th>'
		. '</tr></thead><tbody>';
	foreach ( $q->posts as $p ) {
		$c = cel_claim_get( $p->ID );
		if ( ! $c ) {
			continue;
		}
		$html .= '<tr><td>' . esc_html( $c['claim_no'] ) . '</td><td>' . esc_html( cel_claim_display_date( $c['submission_date'] ) ) . '</td>'
			. '<td>' . esc_html( $c['purpose'] ) . '</td>'
			. '<td class="r">' . esc_html( cel_claim_money( $c['subtotal_a'] ) ) . '</td>'
			. '<td class="r">' . esc_html( cel_claim_money( $c['subtotal_b'] ) ) . '</td>'
			. '<td class="r"><strong>' . esc_html( cel_claim_money( $c['grand_total'] ) ) . '</strong></td>'
			. '<td><a target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $p->ID ) ) . '">Print / PDF</a></td></tr>';
	}
	$html .= '</tbody></table></div>';
	return $html;
}

/* -------------------------------------------------------------------------
 * Print view: ?cel_claim_print=ID  →  same layout as the Excel sheet,
 * A4 landscape, opens the browser's Print dialog ("Save as PDF").
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'cel_claim_print_view' );
function cel_claim_print_view() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, access checked below.
	if ( ! isset( $_GET['cel_claim_print'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$claim = cel_claim_get( absint( $_GET['cel_claim_print'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! cel_claim_user_can_view( $claim ) ) {
		wp_die( 'You do not have permission to view this claim.', 'Not allowed', array( 'response' => 403 ) );
	}
	nocache_headers();
	$mode     = 'print';
	$settings = cel_claim_settings();
	include CEL_CLAIM_DIR . 'templates/print.php';
	exit;
}

/* -------------------------------------------------------------------------
 * Admin: settings, list columns, CSV export, resend.
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'cel_claim_admin_menu' );
function cel_claim_admin_menu() {
	add_submenu_page( 'edit.php?post_type=' . CEL_CLAIM_CPT, 'Claim Form Settings', 'Settings', 'manage_options', 'cel-claim-settings', 'cel_claim_settings_page' );
}

add_action( 'admin_init', 'cel_claim_register_settings' );
function cel_claim_register_settings() {
	register_setting(
		'cel_claim',
		CEL_CLAIM_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'cel_claim_sanitize_settings',
		)
	);
}

function cel_claim_sanitize_settings( $in ) {
	$d = cel_claim_default_settings();
	return array(
		'webhook_url'  => esc_url_raw( trim( $in['webhook_url'] ?? '' ), array( 'https', 'http' ) ),
		'notify_email' => sanitize_email( $in['notify_email'] ?? '' ),
		'logo_url'     => esc_url_raw( trim( $in['logo_url'] ?? '' ), array( 'https', 'http' ) ),
		'title'        => sanitize_text_field( $in['title'] ?? $d['title'] ),
		'subtitle'     => sanitize_text_field( $in['subtitle'] ?? $d['subtitle'] ),
		'guidelines'   => sanitize_textarea_field( $in['guidelines'] ?? $d['guidelines'] ),
	);
}

function cel_claim_settings_page() {
	$s = cel_claim_settings();
	$n = CEL_CLAIM_OPTION;
	?>
	<div class="wrap">
		<h1>Claim Form Settings</h1>
		<p>Put the form on any page with the shortcode <code>[celectric_claim_form]</code> (in Elementor, use the <em>Shortcode</em> widget).</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'cel_claim' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cel_wh">OneDrive webhook URL</label></th>
					<td><input id="cel_wh" type="url" class="large-text code" name="<?php echo esc_attr( $n ); ?>[webhook_url]" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" placeholder="https://hook.eu2.make.com/…">
					<p class="description">Each new claim is POSTed here as JSON (claim_no, submission_date, claimant_name, staff_email, department, purpose, destination, travel_period, subtotal_a, subtotal_b, grand_total, print_url). Your Make / Power Automate / Zapier scenario adds it as a row in the Excel file on OneDrive.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="cel_em">Notify email</label></th>
					<td><input id="cel_em" type="email" class="regular-text" name="<?php echo esc_attr( $n ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
					<p class="description">Optional. Gets an email with the totals and print link for every new claim.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="cel_logo">Logo URL</label></th>
					<td><input id="cel_logo" type="url" class="large-text" name="<?php echo esc_attr( $n ); ?>[logo_url]" value="<?php echo esc_attr( $s['logo_url'] ); ?>">
					<p class="description">Optional. Leave blank to use the Celectric logo from the Excel form.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="cel_t">Form title</label></th>
					<td><input id="cel_t" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cel_st">Subtitle</label></th>
					<td><input id="cel_st" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cel_g">Guidelines box</label></th>
					<td><textarea id="cel_g" class="large-text" rows="7" name="<?php echo esc_attr( $n ); ?>[guidelines]"><?php echo esc_textarea( $s['guidelines'] ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2>Test the webhook</h2>
		<p>Sends a sample row (claim_no <code>TEST-0000</code>) so you can map the fields in Make / Power Automate. Save the URL above first.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cel_claim_test_webhook">
			<?php wp_nonce_field( 'cel_claim_test_webhook' ); ?>
			<?php submit_button( 'Send test row', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_cel_claim_test_webhook', 'cel_claim_test_webhook' );
function cel_claim_test_webhook() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_test_webhook' );
	$url    = trim( cel_claim_settings()['webhook_url'] );
	$result = 'no webhook URL set';
	if ( $url ) {
		$res    = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'claim_no'        => 'TEST-0000',
						'submission_date' => current_time( 'j/n/Y' ),
						'submitted_at'    => current_time( 'mysql' ),
						'claimant_name'   => 'Test Staff',
						'staff_email'     => 'test@example.com',
						'department'      => 'Sales Department',
						'purpose'         => 'Webhook test',
						'destination'     => 'Kuala Lumpur',
						'travel_period'   => '1 Day',
						'subtotal_a'      => 100,
						'subtotal_b'      => 23.3,
						'grand_total'     => 123.3,
						'print_url'       => home_url( '/' ),
					)
				),
			)
		);
		$result = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
	}
	wp_safe_redirect( add_query_arg( array( 'post_type' => CEL_CLAIM_CPT, 'page' => 'cel-claim-settings', 'cel_test' => rawurlencode( $result ) ), admin_url( 'edit.php' ) ) );
	exit;
}

add_action( 'admin_notices', 'cel_claim_admin_notices' );
function cel_claim_admin_notices() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	if ( isset( $_GET['cel_test'] ) ) {
		echo '<div class="notice notice-info is-dismissible"><p>Webhook test result: <strong>' . esc_html( sanitize_text_field( wp_unslash( $_GET['cel_test'] ) ) ) . '</strong></p></div>';
	}
	if ( isset( $_GET['cel_resent'] ) ) {
		echo '<div class="notice notice-info is-dismissible"><p>Webhook resent: <strong>' . esc_html( sanitize_text_field( wp_unslash( $_GET['cel_resent'] ) ) ) . '</strong></p></div>';
	}
	// phpcs:enable
}

add_filter( 'manage_' . CEL_CLAIM_CPT . '_posts_columns', 'cel_claim_columns' );
function cel_claim_columns( $cols ) {
	return array(
		'cb'         => $cols['cb'],
		'title'      => 'Claim',
		'cel_a'      => 'Section A (RM)',
		'cel_b'      => 'Section B (RM)',
		'cel_total'  => 'Total (RM)',
		'cel_hook'   => 'OneDrive',
		'cel_print'  => 'PDF',
		'date'       => 'Submitted',
	);
}

add_action( 'manage_' . CEL_CLAIM_CPT . '_posts_custom_column', 'cel_claim_column_value', 10, 2 );
function cel_claim_column_value( $col, $post_id ) {
	$c = cel_claim_get( $post_id );
	if ( ! $c ) {
		return;
	}
	switch ( $col ) {
		case 'cel_a':
			echo esc_html( cel_claim_money( $c['subtotal_a'] ) );
			break;
		case 'cel_b':
			echo esc_html( cel_claim_money( $c['subtotal_b'] ) );
			break;
		case 'cel_total':
			echo '<strong>' . esc_html( cel_claim_money( $c['grand_total'] ) ) . '</strong>';
			break;
		case 'cel_hook':
			echo esc_html( (string) get_post_meta( $post_id, '_cel_webhook', true ) );
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=cel_claim_resend&claim=' . $post_id ), 'cel_claim_resend_' . $post_id );
			echo '<br><a href="' . esc_url( $url ) . '">Resend</a>';
			break;
		case 'cel_print':
			echo '<a target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $post_id ) ) . '">Print / PDF</a>';
			break;
	}
}

/* Claims are read-only records: the edit screen just shows the print view. */
add_action( 'load-post.php', 'cel_claim_redirect_edit_screen' );
function cel_claim_redirect_edit_screen() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
	$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	if ( $id && CEL_CLAIM_CPT === get_post_type( $id ) ) {
		wp_safe_redirect( cel_claim_print_url( $id ) . '&noprint=1' );
		exit;
	}
}

add_filter( 'post_row_actions', 'cel_claim_row_actions', 10, 2 );
function cel_claim_row_actions( $actions, $post ) {
	if ( CEL_CLAIM_CPT === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'], $actions['edit'] );
		$actions['view'] = '<a target="_blank" rel="noopener" href="' . esc_url( cel_claim_print_url( $post->ID ) . '&noprint=1' ) . '">View</a>';
	}
	return $actions;
}

add_action( 'admin_post_cel_claim_resend', 'cel_claim_resend' );
function cel_claim_resend() {
	$id = isset( $_GET['claim'] ) ? absint( $_GET['claim'] ) : 0;
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_resend_' . $id );
	$ok = cel_claim_get( $id ) ? cel_claim_send_webhook( $id ) : false;
	wp_safe_redirect( add_query_arg( array( 'post_type' => CEL_CLAIM_CPT, 'cel_resent' => $ok ? 'OK' : 'failed' ), admin_url( 'edit.php' ) ) );
	exit;
}

/* CSV export of the totals – a backup in case the webhook isn't set up yet. */
add_action( 'restrict_manage_posts', 'cel_claim_export_button' );
function cel_claim_export_button( $post_type ) {
	if ( CEL_CLAIM_CPT === $post_type ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=cel_claim_export' ), 'cel_claim_export' );
		echo '<a class="button" style="margin-left:6px" href="' . esc_url( $url ) . '">Export totals (CSV)</a>';
	}
}

add_action( 'admin_post_cel_claim_export', 'cel_claim_export_csv' );
function cel_claim_export_csv() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cel_claim_export' );
	$ids = get_posts(
		array(
			'post_type'      => CEL_CLAIM_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
		)
	);
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="expense-claims-' . current_time( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads it correctly.
	$head = array( 'Claim No', 'Submission Date', 'Claimant Name', 'Staff Email', 'Department', 'Purpose', 'Destination', 'Travel Period', 'Subtotal A (RM)', 'Subtotal B (RM)', 'Grand Total (RM)', 'Print URL' );
	fputcsv( $out, $head );
	foreach ( $ids as $id ) {
		$p = cel_claim_webhook_payload( $id );
		$row = array_values( array_diff_key( $p, array( 'submitted_at' => 1 ) ) );
		// Stop Excel treating text that starts with = + - @ as a formula.
		$row = array_map(
			function ( $v ) {
				return is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			},
			$row
		);
		fputcsv( $out, $row );
	}
	fclose( $out );
	exit;
}
