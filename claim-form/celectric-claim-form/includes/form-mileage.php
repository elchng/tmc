<?php
/**
 * Mileage Claim: rates, validation and totals.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cel_claim_mileage_purposes() {
	return cel_claim_lines( cel_claim_setting( 'mileage_purposes' ) );
}

/** "Car: RM 0.70/km | Motorcycle: RM 0.40/km" */
function cel_claim_rate_options_text( $vehicles ) {
	$parts = array();
	foreach ( $vehicles as $name => $rate ) {
		$parts[] = $name . ': RM ' . number_format( (float) $rate, 2 ) . '/km';
	}
	return implode( ' | ', $parts );
}

/**
 * Builds a clean mileage claim from POST data.
 *
 * @return array{0: array, 1: string[]} [claim, errors]
 */
function cel_claim_mileage_from_request( $raw ) {
	$vehicles = cel_claim_vehicle_rates();
	$purposes = cel_claim_mileage_purposes();
	$errors   = array();

	$text = function ( $key, $max = 200 ) use ( $raw ) {
		return mb_substr( isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '', 0, $max );
	};

	$vehicle = $text( 'vehicle' );
	if ( ! isset( $vehicles[ $vehicle ] ) ) {
		$errors[] = 'Choose a Vehicle Type.';
		$vehicle  = (string) cel_claim_first_key( $vehicles );
	}
	$rate = (float) ( $vehicles[ $vehicle ] ?? 0 );

	$claim = array(
		'type'          => 'mileage',
		'claimant_name' => $text( 'claimant_name' ),
		'claim_period'  => $text( 'claim_period', 40 ),
		'vehicle'       => $vehicle,
		'rate'          => $rate,
		'rate_options'  => cel_claim_rate_options_text( $vehicles ),
		'trips'         => array(),
	);
	if ( '' === $claim['claimant_name'] ) {
		$errors[] = "Employee's Name is required.";
	}
	if ( '' === $claim['claim_period'] ) {
		$errors[] = 'Claim Period / Month is required.';
	}

	$km = 0.0;
	$n  = 0;
	foreach ( cel_claim_request_rows( $raw, 't' ) as $row ) {
		$date     = cel_claim_clean_date( $row['date'] ?? '' );
		$depart   = cel_claim_clean_time( $row['depart'] ?? '' );
		$arrive   = cel_claim_clean_time( $row['arrive'] ?? '' );
		$from     = mb_substr( sanitize_text_field( $row['from'] ?? '' ), 0, 120 );
		$to       = mb_substr( sanitize_text_field( $row['to'] ?? '' ), 0, 120 );
		$distance = cel_claim_clean_amount( $row['distance'] ?? '', 1 );
		$purpose  = sanitize_text_field( $row['purpose'] ?? '' );
		$purpose  = in_array( $purpose, $purposes, true ) ? $purpose : '';
		$remarks  = mb_substr( sanitize_text_field( $row['remarks'] ?? '' ), 0, 120 );
		if ( '' === $date . $depart . $arrive . $from . $to . $purpose . $remarks && 0.0 === $distance ) {
			continue;
		}
		$n++;
		if ( '' === $date ) {
			$errors[] = "Trip {$n}: date is required.";
		}
		if ( '' === $from || '' === $to ) {
			$errors[] = "Trip {$n}: enter From and To.";
		}
		if ( $distance <= 0 ) {
			$errors[] = "Trip {$n}: enter the distance in KM.";
		}
		$claim['trips'][] = compact( 'date', 'depart', 'arrive', 'from', 'to', 'distance', 'purpose', 'remarks' );
		$km              += $distance;
	}
	if ( 0 === $n ) {
		$errors[] = 'Enter at least one trip.';
	}

	$claim['total_km']    = round( $km, 1 );
	$claim['net_payable'] = round( $claim['total_km'] * $rate, 2 );

	return array( $claim, $errors );
}

/** Values for the editable mileage sheet. */
function cel_claim_mileage_edit_values( $raw, $user ) {
	$vehicles = cel_claim_vehicle_rates();
	$vehicle  = isset( $raw['vehicle'] ) && isset( $vehicles[ $raw['vehicle'] ] ) ? $raw['vehicle'] : (string) cel_claim_first_key( $vehicles );
	$v        = array(
		'type'            => 'mileage',
		'claimant_name'   => $raw['claimant_name'] ?? $user->display_name,
		'submission_date' => current_time( 'Y-m-d' ),
		'claim_period'    => $raw['claim_period'] ?? current_time( 'Y-m' ),
		'vehicle'         => $vehicle,
		'rate'            => (float) ( $vehicles[ $vehicle ] ?? 0 ),
		'rate_options'    => cel_claim_rate_options_text( $vehicles ),
		'trips'           => array(),
		'total_km'        => 0,
	);
	foreach ( cel_claim_request_rows( $raw, 't' ) as $r ) {
		$v['trips'][]   = array(
			'date'     => $r['date'] ?? '',
			'depart'   => $r['depart'] ?? '',
			'arrive'   => $r['arrive'] ?? '',
			'from'     => $r['from'] ?? '',
			'to'       => $r['to'] ?? '',
			'distance' => $r['distance'] ?? '',
			'purpose'  => $r['purpose'] ?? '',
			'remarks'  => $r['remarks'] ?? '',
		);
		$v['total_km'] += cel_claim_clean_amount( $r['distance'] ?? '', 1 );
	}
	$v['net_payable'] = round( $v['total_km'] * $v['rate'], 2 );
	return $v;
}
