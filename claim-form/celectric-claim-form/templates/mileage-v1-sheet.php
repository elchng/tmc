<?php
/**
 * Mileage Claim sheet, laid out like the Excel "Mileage Claim" form
 * (columns A–I, same widths, colours and merged cells).
 *
 *  - $mode = 'edit'  : cells contain inputs (on phones the CSS restacks them).
 *  - $mode = 'print' : cells contain the saved values, rate and totals.
 *
 * Expects: $mode, $claim (array).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$edit     = ( 'edit' === $mode );
$vehicles = cel_claim_vehicle_rates();
$purposes = cel_claim_mileage_purposes();
$trips    = isset( $claim['trips'] ) ? array_values( $claim['trips'] ) : array();
$rows     = $edit ? max( (int) cel_claim_setting( 'mileage_rows' ), count( $trips ) ) : max( 16, count( $trips ) );
$rate     = (float) ( $claim['rate'] ?? 0 );
$km       = (float) ( $claim['total_km'] ?? 0 );
$net      = (float) ( $claim['net_payable'] ?? round( $km * $rate, 2 ) );

$km_text   = function ( $n ) {
	return number_format( (float) $n, 1 ) . ' KM';
};
$rate_text = function ( $n ) {
	return 'RM ' . number_format( (float) $n, 2 ) . '/km';
};
$field     = function ( $name, $value, $type = 'text', $extra = '' ) use ( $edit ) {
	if ( $edit ) {
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" ' . $extra . '>';
	}
	if ( 'date' === $type ) {
		return esc_html( cel_claim_display_date( $value ) );
	}
	if ( 'month' === $type ) {
		return esc_html( cel_claim_display_month( $value ) );
	}
	return esc_html( (string) $value );
};
$select    = function ( $name, $options, $value, $extra = '' ) use ( $edit ) {
	if ( ! $edit ) {
		return esc_html( (string) $value );
	}
	$h = '<select name="' . esc_attr( $name ) . '" ' . $extra . '>';
	foreach ( $options as $opt_value => $opt_label ) {
		$h .= '<option value="' . esc_attr( $opt_value ) . '"' . selected( (string) $value, (string) $opt_value, false ) . '>' . esc_html( $opt_label ) . '</option>';
	}
	return $h . '</select>';
};
$veh_opts = $vehicles ? array_combine( array_keys( $vehicles ), array_keys( $vehicles ) ) : array();
$pur_opts = array( '' => $edit ? '— choose —' : '' ) + ( $purposes ? array_combine( $purposes, $purposes ) : array() );
$config   = array( 'form' => 'mileage', 'vehicles' => $vehicles, 'max' => CEL_CLAIM_MAX_ROWS );
$trip_row = function ( $i, $r, $tpl = false ) use ( $edit, $field, $select, $pur_opts ) {
	$name = function ( $k ) use ( $i, $tpl ) {
		return $tpl ? '' : "t[$i][$k]";
	};
	$n    = function ( $k ) use ( $tpl ) {
		return $tpl ? ' data-n="' . $k . '"' : '';
	};
	$has  = ! empty( $r['date'] ) || ! empty( $r['from'] ) || ! empty( $r['to'] ) || ( isset( $r['distance'] ) && '' !== $r['distance'] );
	$dist = $r['distance'] ?? '';
	ob_start();
	?>
	<tr class="m20 row<?php echo ( $i % 2 ) ? ' stripe' : ''; ?><?php echo $has || $tpl ? '' : ' is-empty'; ?>">
		<td class="c num"><?php echo $tpl ? '' : (int) ( $i + 1 ); ?></td>
		<td class="c" data-label="Date"><?php echo $field( $name( 'date' ), $r['date'] ?? '', 'date', $n( 'date' ) ); ?></td>
		<td class="c" data-label="Time of Departure"><?php echo $field( $name( 'depart' ), $r['depart'] ?? '', 'time', $n( 'depart' ) ); ?></td>
		<td class="c" data-label="Time of Arrive"><?php echo $field( $name( 'arrive' ), $r['arrive'] ?? '', 'time', $n( 'arrive' ) ); ?></td>
		<td data-label="From"><?php echo $field( $name( 'from' ), $r['from'] ?? '', 'text', 'maxlength="120"' . $n( 'from' ) ); ?></td>
		<td data-label="To"><?php echo $field( $name( 'to' ), $r['to'] ?? '', 'text', 'maxlength="120"' . $n( 'to' ) ); ?></td>
		<td class="r" data-label="Distance (KM)">
			<?php
			if ( $edit ) {
				echo '<input type="number" min="0" step="0.1" inputmode="decimal" data-cel-km name="' . esc_attr( $name( 'distance' ) ) . '" value="' . esc_attr( (string) $dist ) . '"' . $n( 'distance' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( '' !== $dist ) {
				echo esc_html( number_format( (float) $dist, 1 ) );
			}
			?>
		</td>
		<td data-label="Purpose of Visit"><?php echo $select( $name( 'purpose' ), $pur_opts, $r['purpose'] ?? '', $n( 'purpose' ) ); ?></td>
		<td data-label="Remarks"><?php echo $field( $name( 'remarks' ), $r['remarks'] ?? '', 'text', 'maxlength="120"' . $n( 'remarks' ) ); ?></td>
	</tr>
	<?php
	return ob_get_clean();
};
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $field/$select/$trip_row escape their output.
?>
<div class="cel-frame cel-mileage">
<img class="cel-logo" src="<?php echo esc_url( cel_claim_logo_url() ); ?>" alt="Celectric" width="104" height="52" decoding="async">
<table class="cel-sheet cel-msheet<?php echo $edit ? ' is-edit' : ''; ?>" data-cel-sheet<?php echo $edit ? ' data-cel-config="' . esc_attr( wp_json_encode( $config ) ) . '"' : ''; ?>>
	<colgroup>
		<col style="width:40px"><col style="width:109px"><col style="width:130px"><col style="width:134px"><col style="width:155px">
		<col style="width:129px"><col style="width:89px"><col style="width:136px"><col style="width:101px">
	</colgroup>

	<tr class="m33 top"><td colspan="9" class="mtitle"><?php echo esc_html( cel_claim_setting( 'mileage_title' ) ); ?></td></tr>
	<tr class="m20 top datebar">
		<td></td><td></td><td></td><td></td><td></td><td></td><td></td>
		<td class="lbl r">Date:</td>
		<td class="lb c"><?php echo esc_html( cel_claim_display_date( $claim['submission_date'] ?? '' ) ); ?></td>
	</tr>
	<tr class="m29"><td colspan="9" class="msec">1. CLAIMANT &amp; VEHICLE DETAILS</td></tr>
	<tr class="m20 info">
		<td colspan="2" class="lbl">Employee's Name:</td>
		<td colspan="2" class="lb c in"><?php echo $field( 'claimant_name', $claim['claimant_name'] ?? '', 'text', 'required maxlength="200" autocomplete="name"' ); ?></td>
		<td class="lbl">Claim Period / Month:</td>
		<td class="lb in"><?php echo $field( 'claim_period', $claim['claim_period'] ?? '', 'month', 'required' ); ?></td>
		<td class="lbl">Vehicle Type:</td>
		<td colspan="2" class="veh b c in"><?php echo $select( 'vehicle', $veh_opts, $claim['vehicle'] ?? '', 'required data-cel-vehicle' ); ?></td>
	</tr>
	<tr class="m20 info">
		<td colspan="2" class="lbl">Applicable Rate Policy:</td>
		<td class="tot b r" data-cel-rate><?php echo esc_html( $rate_text( $rate ) ); ?></td>
		<td class="ropt-l">Rate Options:</td>
		<td colspan="2" class="ropt"><?php echo esc_html( $claim['rate_options'] ?? '' ); ?></td>
		<td class="pad"></td><td class="pad"></td><td class="pad"></td>
	</tr>
	<tr class="m20 blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
	<tr class="m32 mhead">
		<td>No.</td><td>Date</td><td>Time of Departure</td><td>Time of Arrive</td><td>From</td><td>To</td><td>Distance (KM)</td><td>Purpose of Visit</td><td>Remarks</td>
	</tr>
	<tbody data-cel-rows="t">
	<?php
	for ( $i = 0; $i < $rows; $i++ ) {
		echo $trip_row( $i, $trips[ $i ] ?? array() );
	}
	?>
	</tbody>
	<tr class="m29 subrow">
		<td colspan="6" class="mtot-l">TOTAL MILEAGE &amp; CLAIM AMOUNT</td>
		<td class="tot b r" data-cel-km-total><?php echo esc_html( $km_text( $km ) ); ?></td>
		<td class="mtot-l">Total Claim Amount:</td>
		<td class="mnet r" data-cel-net><?php echo esc_html( 'RM ' . cel_claim_money( $net ) ); ?></td>
	</tr>
	<tr class="m20 blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
	<tr class="m29"><td colspan="9" class="msec">2. CLAIM SUMMARY BREAKDOWN</td></tr>
	<tr class="m26 sumrow">
		<td colspan="2" class="lbl">Total Distance (KM):</td>
		<td class="tot b c" data-cel-km-total><?php echo esc_html( $km_text( $km ) ); ?></td>
		<td class="lbl">Claim Rate Used:</td>
		<td class="tot b c" data-cel-rate><?php echo esc_html( $rate_text( $rate ) ); ?></td>
		<td colspan="2" class="lbl r">Net Payable Claim:</td>
		<td colspan="2" class="mnet c" data-cel-net data-cel-grand><?php echo esc_html( 'RM ' . cel_claim_money( $net ) ); ?></td>
	</tr>
</table>
</div>
<?php if ( $edit ) : ?>
<template data-cel-tpl="t"><?php echo $trip_row( 0, array(), true ); ?></template>
<?php endif; ?>
<?php // phpcs:enable ?>
