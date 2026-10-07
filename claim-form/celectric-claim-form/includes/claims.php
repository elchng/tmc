<?php
/**
 * Claim storage. Each submitted claim is one private post of type
 * "cel_claim" in the WordPress database (wp_posts); the form data, the
 * rates used and the totals are kept in post meta "_cel_claim" (wp_postmeta).
 * Admins/Editors can view, export and delete them under Claims in wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The two claim forms. */
function cel_claim_forms() {
	return array(
		'travel'  => array(
			'label'  => 'Travel & Expense',
			'prefix' => 'CLM',
		),
		'mileage' => array(
			'label'  => 'Mileage',
			'prefix' => 'MIL',
		),
	);
}

add_action( 'init', 'cel_claim_register_post_type' );
function cel_claim_register_post_type() {
	register_post_type(
		CEL_CLAIM_CPT,
		array(
			'labels'          => array(
				'name'          => 'Claims',
				'singular_name' => 'Claim',
				'menu_name'     => 'Claims',
				'all_items'     => 'All Claims',
				'edit_item'     => 'Claim',
				'search_items'  => 'Search Claims',
				'not_found'     => 'No claims yet.',
				'not_found_in_trash' => 'No claims in Trash.',
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

/** Returns the stored claim array (with id, author_id, type), or null. */
function cel_claim_get( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || CEL_CLAIM_CPT !== $post->post_type || 'publish' !== $post->post_status ) {
		return null;
	}
	$data = get_post_meta( $post->ID, '_cel_claim', true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$data['id']        = $post->ID;
	$data['author_id'] = (int) $post->post_author;
	$data['type']      = isset( $data['type'] ) && isset( cel_claim_forms()[ $data['type'] ] ) ? $data['type'] : 'travel'; // v1 claims are travel.
	return $data;
}

function cel_claim_number( $post_id, $type ) {
	$forms = cel_claim_forms();
	return $forms[ $type ]['prefix'] . '-' . get_the_date( 'Y', $post_id ) . '-' . str_pad( (string) $post_id, 5, '0', STR_PAD_LEFT );
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

function cel_claim_clean_date( $v ) {
	$v = sanitize_text_field( (string) $v );
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
}

function cel_claim_clean_time( $v ) {
	$v = sanitize_text_field( (string) $v );
	return preg_match( '/^\d{2}:\d{2}$/', $v ) ? $v : '';
}

function cel_claim_display_date( $ymd ) {
	if ( ! $ymd ) {
		return '';
	}
	$ts = strtotime( $ymd );
	return $ts ? gmdate( 'j/n/Y', $ts ) : '';
}

/** "2026-09" → "September 2026"; anything else is shown as typed. */
function cel_claim_display_month( $v ) {
	if ( preg_match( '/^(\d{4})-(\d{2})$/', (string) $v, $m ) ) {
		return gmdate( 'F Y', gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] ) );
	}
	return (string) $v;
}

/** The headline amount of a claim, whatever its type. */
function cel_claim_total( $c ) {
	return 'mileage' === $c['type'] ? (float) $c['net_payable'] : (float) $c['grand_total'];
}

/** One-line description used in lists. */
function cel_claim_summary( $c ) {
	if ( 'mileage' === $c['type'] ) {
		return trim( cel_claim_display_month( $c['claim_period'] ) . ' · ' . $c['vehicle'] . ' · ' . number_format( (float) $c['total_km'], 1 ) . ' km', ' ·' );
	}
	return (string) $c['purpose'];
}

/**
 * The single row sent to Excel / Google Sheets / webhooks: header details
 * and totals only. Column order matches Claims_Register.xlsx.
 */
function cel_claim_payload( $post_id ) {
	$c = cel_claim_get( $post_id );
	if ( ! $c ) {
		return null;
	}
	$common = array(
		'form'            => $c['type'],
		'claim_no'        => $c['claim_no'],
		'submission_date' => cel_claim_display_date( $c['submission_date'] ),
		'submitted_at'    => $c['submitted_at'],
		'claimant_name'   => $c['claimant_name'],
		'staff_email'     => $c['staff_email'],
	);
	if ( 'mileage' === $c['type'] ) {
		return $common + array(
			'claim_period' => cel_claim_display_month( $c['claim_period'] ),
			'vehicle'      => $c['vehicle'],
			'rate_per_km'  => (float) $c['rate'],
			'total_km'     => (float) $c['total_km'],
			'net_payable'  => (float) $c['net_payable'],
			'print_url'    => cel_claim_print_url( $post_id ),
		);
	}
	return $common + array(
		'department'    => $c['department'],
		'purpose'       => $c['purpose'],
		'destination'   => $c['destination'],
		'travel_period' => $c['travel_period'],
		'subtotal_a'    => (float) $c['subtotal_a'],
		'subtotal_b'    => (float) $c['subtotal_b'],
		'grand_total'   => (float) $c['grand_total'],
		'print_url'     => cel_claim_print_url( $post_id ),
	);
}

/** Column headings for each form (Excel register, CSV export, Google Sheet). */
function cel_claim_columns_for( $type ) {
	if ( 'mileage' === $type ) {
		return array(
			'claim_no'        => 'Claim No',
			'submission_date' => 'Submission Date',
			'claimant_name'   => 'Employee Name',
			'staff_email'     => 'Staff Email',
			'claim_period'    => 'Claim Period',
			'vehicle'         => 'Vehicle Type',
			'rate_per_km'     => 'Rate (RM/km)',
			'total_km'        => 'Total Distance (KM)',
			'net_payable'     => 'Net Payable (RM)',
			'print_url'       => 'Print / PDF Link',
		);
	}
	return array(
		'claim_no'        => 'Claim No',
		'submission_date' => 'Submission Date',
		'claimant_name'   => 'Claimant Name',
		'staff_email'     => 'Staff Email',
		'department'      => 'Department',
		'purpose'         => 'Purpose',
		'destination'     => 'Destination',
		'travel_period'   => 'Travel Period',
		'subtotal_a'      => 'Subtotal A (RM)',
		'subtotal_b'      => 'Subtotal B (RM)',
		'grand_total'     => 'Grand Total (RM)',
		'print_url'       => 'Print / PDF Link',
	);
}

/** Payload values in register-column order. */
function cel_claim_row_values( $payload ) {
	$row = array();
	foreach ( array_keys( cel_claim_columns_for( $payload['form'] ) ) as $k ) {
		$row[] = $payload[ $k ] ?? '';
	}
	return $row;
}
