<?php
/**
 * Travel & Expense Claim: rates, validation and totals.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cel_claim_outstation_rates() {
	return cel_claim_rate_list( cel_claim_setting( 'travel_outstation' ) );
}

function cel_claim_meal_rates() {
	return cel_claim_rate_list( cel_claim_setting( 'travel_meals' ) );
}

function cel_claim_expense_categories() {
	return cel_claim_lines( cel_claim_setting( 'travel_categories' ) );
}

/** First option of a rate list (normally "None"). */
function cel_claim_first_key( $list ) {
	$keys = array_keys( $list );
	return $keys ? $keys[0] : '';
}

/**
 * Builds a clean travel claim from POST data. Amounts always come from the
 * current settings, never from the browser, and are stored with the claim
 * so later rate changes don't alter submitted claims.
 *
 * @return array{0: array, 1: string[]} [claim, errors]
 */
function cel_claim_travel_from_request( $raw ) {
	$out_rates  = cel_claim_outstation_rates();
	$meal_rates = cel_claim_meal_rates();
	$categories = cel_claim_expense_categories();
	$errors     = array();

	$text = function ( $key, $max = 200 ) use ( $raw ) {
		return mb_substr( isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '', 0, $max );
	};

	$claim = array(
		'type'          => 'travel',
		'claimant_name' => $text( 'claimant_name' ),
		'purpose'       => $text( 'purpose' ),
		'destination'   => $text( 'destination' ),
		'travel_period' => $text( 'travel_period' ),
		'department'    => $text( 'department' ),
		'guidelines'    => cel_claim_setting( 'travel_guidelines' ),
		'allowances'    => array(),
		'expenses'      => array(),
	);

	foreach ( array( 'claimant_name' => 'Claimant Name', 'purpose' => 'Purpose of Travel', 'department' => 'Department / Project' ) as $k => $label ) {
		if ( '' === $claim[ $k ] ) {
			$errors[] = $label . ' is required.';
		}
	}

	$sub_a = 0.0;
	$n     = 0;
	foreach ( cel_claim_request_rows( $raw, 'a' ) as $row ) {
		$date    = cel_claim_clean_date( $row['date'] ?? '' );
		$details = mb_substr( sanitize_text_field( $row['details'] ?? '' ), 0, 200 );
		$out     = sanitize_text_field( $row['outstation'] ?? '' );
		$meal    = sanitize_text_field( $row['meal'] ?? '' );
		$out     = isset( $out_rates[ $out ] ) ? $out : cel_claim_first_key( $out_rates );
		$meal    = isset( $meal_rates[ $meal ] ) ? $meal : cel_claim_first_key( $meal_rates );
		$out_amt = (float) ( $out_rates[ $out ] ?? 0 );
		$meal_amt = (float) ( $meal_rates[ $meal ] ?? 0 );
		$total   = round( $out_amt + $meal_amt, 2 );
		if ( '' === $date && '' === $details && 0.0 === $total ) {
			continue; // Empty row.
		}
		$n++;
		if ( '' === $date ) {
			$errors[] = "Section A row {$n}: date is required.";
		}
		$claim['allowances'][] = compact( 'date', 'details', 'out', 'out_amt', 'meal', 'meal_amt', 'total' );
		$sub_a                += $total;
	}

	$sub_b = 0.0;
	$m     = 0;
	foreach ( cel_claim_request_rows( $raw, 'b' ) as $row ) {
		$date     = cel_claim_clean_date( $row['date'] ?? '' );
		$category = sanitize_text_field( $row['category'] ?? '' );
		$category = in_array( $category, $categories, true ) ? $category : '';
		$merchant = mb_substr( sanitize_text_field( $row['merchant'] ?? '' ), 0, 200 );
		$receipt  = mb_substr( sanitize_text_field( $row['receipt'] ?? '' ), 0, 100 );
		$amount   = cel_claim_clean_amount( $row['amount'] ?? '' );
		if ( '' === $date && '' === $category && '' === $merchant && '' === $receipt && 0.0 === $amount ) {
			continue;
		}
		$m++;
		if ( '' === $date ) {
			$errors[] = "Section B row {$m}: date is required.";
		}
		if ( '' === $category ) {
			$errors[] = "Section B row {$m}: choose an expense category.";
		}
		if ( $amount <= 0 ) {
			$errors[] = "Section B row {$m}: enter an amount.";
		}
		$claim['expenses'][] = compact( 'date', 'category', 'merchant', 'receipt', 'amount' );
		$sub_b              += $amount;
	}

	if ( 0 === $n && 0 === $m ) {
		$errors[] = 'Enter at least one allowance (Section A) or expense (Section B).';
	}

	$claim['subtotal_a']  = round( $sub_a, 2 );
	$claim['subtotal_b']  = round( $sub_b, 2 );
	$claim['grand_total'] = round( $sub_a + $sub_b, 2 );

	return array( $claim, $errors );
}

/** Rows posted for one section, as arrays (max CEL_CLAIM_MAX_ROWS). */
function cel_claim_request_rows( $raw, $key ) {
	$rows = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? wp_unslash( $raw[ $key ] ) : array();
	return array_filter( array_slice( array_values( $rows ), 0, CEL_CLAIM_MAX_ROWS ), 'is_array' );
}

function cel_claim_clean_amount( $v, $decimals = 2 ) {
	$v = str_replace( array( ',', 'RM', ' ' ), '', (string) $v );
	return is_numeric( $v ) ? round( max( 0, (float) $v ), $decimals ) : 0.0;
}

/** Values for the editable travel sheet: blank, or what was typed before a failed submit. */
function cel_claim_travel_edit_values( $raw, $user ) {
	$v = array(
		'type'            => 'travel',
		'claimant_name'   => $raw['claimant_name'] ?? $user->display_name,
		'submission_date' => current_time( 'Y-m-d' ),
		'purpose'         => $raw['purpose'] ?? '',
		'destination'     => $raw['destination'] ?? '',
		'travel_period'   => $raw['travel_period'] ?? '',
		'department'      => $raw['department'] ?? '',
		'guidelines'      => cel_claim_setting( 'travel_guidelines' ),
		'allowances'      => array(),
		'expenses'        => array(),
	);
	$out_rates  = cel_claim_outstation_rates();
	$meal_rates = cel_claim_meal_rates();
	foreach ( cel_claim_request_rows( $raw, 'a' ) as $r ) {
		$out               = $r['outstation'] ?? '';
		$meal              = $r['meal'] ?? '';
		$v['allowances'][] = array(
			'date'    => $r['date'] ?? '',
			'details' => $r['details'] ?? '',
			'out'     => $out,
			'meal'    => $meal,
			'total'   => (float) ( $out_rates[ $out ] ?? 0 ) + (float) ( $meal_rates[ $meal ] ?? 0 ),
		);
	}
	foreach ( cel_claim_request_rows( $raw, 'b' ) as $r ) {
		$v['expenses'][] = array(
			'date'     => $r['date'] ?? '',
			'category' => $r['category'] ?? '',
			'merchant' => $r['merchant'] ?? '',
			'receipt'  => $r['receipt'] ?? '',
			'amount'   => $r['amount'] ?? '',
		);
	}
	return $v;
}
