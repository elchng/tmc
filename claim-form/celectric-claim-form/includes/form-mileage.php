<?php
/**
 * Mileage Claim: rates, validation and totals.
 *
 * Each trip has its own vehicle (e.g. car today, motorcycle tomorrow).
 * The claim is paid per vehicle: total km for that vehicle × its rate.
 * Claims made before v2.2 had one vehicle for the whole claim; they are
 * still shown and printed in their original layout (layout 1).
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
 * Per-vehicle totals: [ "Car" => [ km, rate, amount ], … ] in the order used.
 * Works for both layouts (older claims have a single vehicle).
 */
function cel_claim_vehicle_breakdown( $c ) {
	if ( isset( $c['by_vehicle'] ) && is_array( $c['by_vehicle'] ) ) {
		return $c['by_vehicle'];
	}
	if ( ! empty( $c['vehicle'] ) ) {
		return array(
			$c['vehicle'] => array(
				'km'     => (float) ( $c['total_km'] ?? 0 ),
				'rate'   => (float) ( $c['rate'] ?? 0 ),
				'amount' => (float) ( $c['net_payable'] ?? 0 ),
			),
		);
	}
	return array();
}

/** "Car 91.2 km; Motorcycle 28.3 km" */
function cel_claim_vehicle_breakdown_text( $c ) {
	$parts = array();
	foreach ( cel_claim_vehicle_breakdown( $c ) as $name => $v ) {
		$parts[] = $name . ' ' . number_format( (float) $v['km'], 1 ) . ' km';
	}
	return implode( '; ', $parts );
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

	$claim = array(
		'type'          => 'mileage',
		'layout'        => 2,
		'claimant_name' => $text( 'claimant_name' ),
		'claim_period'  => $text( 'claim_period', 40 ),
		'rate_options'  => cel_claim_rate_options_text( $vehicles ),
		'trips'         => array(),
	);
	if ( '' === $claim['claimant_name'] ) {
		$errors[] = "Employee's Name is required.";
	}
	if ( '' === $claim['claim_period'] ) {
		$errors[] = 'Claim Period / Month is required.';
	}

	$km_by = array();
	$n     = 0;
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
		$vehicle  = sanitize_text_field( $row['vehicle'] ?? '' );
		// The vehicle alone (pre-filled on blank rows) doesn't make a trip.
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
		if ( ! isset( $vehicles[ $vehicle ] ) ) {
			$errors[] = "Trip {$n}: choose the vehicle used.";
			$vehicle  = (string) cel_claim_first_key( $vehicles );
		}
		$rate             = (float) ( $vehicles[ $vehicle ] ?? 0 );
		$amount           = round( $distance * $rate, 2 );
		$claim['trips'][] = compact( 'date', 'depart', 'arrive', 'from', 'to', 'vehicle', 'rate', 'distance', 'amount', 'purpose', 'remarks' );
		$km_by[ $vehicle ] = ( $km_by[ $vehicle ] ?? 0 ) + $distance;
	}
	if ( 0 === $n ) {
		$errors[] = 'Enter at least one trip.';
	}

	// Paid per vehicle: total km for the vehicle × its rate.
	$claim['by_vehicle'] = array();
	$total_km            = 0.0;
	$net                 = 0.0;
	foreach ( $km_by as $vehicle => $km ) {
		$km                               = round( $km, 1 );
		$rate                             = (float) $vehicles[ $vehicle ];
		$amount                           = round( $km * $rate, 2 );
		$claim['by_vehicle'][ $vehicle ] = compact( 'km', 'rate', 'amount' );
		$total_km                        += $km;
		$net                             += $amount;
	}
	$claim['total_km']    = round( $total_km, 1 );
	$claim['net_payable'] = round( $net, 2 );

	return array( $claim, $errors );
}

/** Values for the editable mileage sheet. */
function cel_claim_mileage_edit_values( $raw, $user ) {
	$vehicles = cel_claim_vehicle_rates();
	$v        = array(
		'type'            => 'mileage',
		'layout'          => 2,
		'claimant_name'   => $raw['claimant_name'] ?? $user->display_name,
		'submission_date' => current_time( 'Y-m-d' ),
		'claim_period'    => $raw['claim_period'] ?? current_time( 'Y-m' ),
		'rate_options'    => cel_claim_rate_options_text( $vehicles ),
		'trips'           => array(),
	);
	foreach ( cel_claim_request_rows( $raw, 't' ) as $r ) {
		$v['trips'][] = array(
			'date'     => $r['date'] ?? '',
			'depart'   => $r['depart'] ?? '',
			'arrive'   => $r['arrive'] ?? '',
			'from'     => $r['from'] ?? '',
			'to'       => $r['to'] ?? '',
			'vehicle'  => $r['vehicle'] ?? '',
			'distance' => $r['distance'] ?? '',
			'purpose'  => $r['purpose'] ?? '',
			'remarks'  => $r['remarks'] ?? '',
		);
	}
	return $v;
}
