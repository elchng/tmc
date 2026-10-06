<?php
/**
 * The claim sheet, laid out cell-for-cell like the Excel form
 * (columns A–I, same widths, row heights, colours and merged cells).
 *
 * Used twice:
 *  - $mode = 'edit'  : on the shortcode page, cells contain inputs.
 *  - $mode = 'print' : on the print view, cells contain the saved values.
 *
 * Expects: $mode, $claim (array), $settings (array).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$edit       = ( 'edit' === $mode );
$out_rates  = cel_claim_outstation_rates();
$meal_rates = cel_claim_meal_rates();
$categories = cel_claim_expense_categories();

$allowances = isset( $claim['allowances'] ) ? array_values( $claim['allowances'] ) : array();
$expenses   = isset( $claim['expenses'] ) ? array_values( $claim['expenses'] ) : array();
$rows_a     = max( CEL_CLAIM_ROWS_A, count( $allowances ) );
$rows_b     = max( CEL_CLAIM_ROWS_B, count( $expenses ) );

$sub_a = 0;
foreach ( $allowances as $r ) {
	$sub_a += ( $out_rates[ $r['out'] ?? 'None' ] ?? 0 ) + ( $meal_rates[ $r['meal'] ?? 'None' ] ?? 0 );
}
$sub_b = 0;
foreach ( $expenses as $r ) {
	$sub_b += is_numeric( $r['amount'] ?? '' ) ? (float) $r['amount'] : 0;
}

/** Text input (edit) or the plain value (print). */
$field = function ( $name, $value, $type = 'text', $extra = '' ) use ( $edit ) {
	if ( $edit ) {
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" ' . $extra . '>';
	}
	return esc_html( 'date' === $type ? cel_claim_display_date( $value ) : (string) $value );
};

/** Dropdown (edit) or the chosen label (print). */
$select = function ( $name, $options, $value, $extra = '' ) use ( $edit ) {
	if ( ! $edit ) {
		return esc_html( 'None' === $value ? '' : (string) $value );
	}
	$h = '<select name="' . esc_attr( $name ) . '" ' . $extra . '>';
	foreach ( $options as $opt_value => $opt_label ) {
		$h .= '<option value="' . esc_attr( $opt_value ) . '"' . selected( (string) $value, (string) $opt_value, false ) . '>' . esc_html( $opt_label ) . '</option>';
	}
	return $h . '</select>';
};

$out_opts  = array_combine( array_keys( $out_rates ), array_keys( $out_rates ) );
$meal_opts = array_combine( array_keys( $meal_rates ), array_keys( $meal_rates ) );
$cat_opts  = array( '' => '' ) + array_combine( $categories, $categories );
?>
<div class="cel-frame">
<img class="cel-logo" src="<?php echo esc_url( $settings['logo_url'] ? $settings['logo_url'] : CEL_CLAIM_URL . 'assets/logo.png' ); ?>" alt="">
<table class="cel-sheet<?php echo $edit ? ' is-edit' : ''; ?>" data-cel-sheet
<?php
if ( $edit ) {
	echo ' data-cel-config="' . esc_attr( wp_json_encode( array( 'outstation' => $out_rates, 'meal' => $meal_rates, 'max' => CEL_CLAIM_MAX_ROWS ) ) ) . '"';
}
?>
>
	<colgroup>
		<col style="width:119px"><col style="width:110px"><col style="width:131px"><col style="width:131px"><col style="width:159px">
		<col style="width:159px"><col style="width:131px"><col style="width:131px"><col style="width:159px">
	</colgroup>

	<tr class="h40"><td colspan="9" class="title"><?php echo esc_html( $settings['title'] ); ?></td></tr>
	<tr class="h24"><td colspan="9" class="subtitle"><?php echo esc_html( $settings['subtitle'] ); ?></td></tr>
	<tr class="h21"><td colspan="9" class="blank"></td></tr>

	<tr class="h29"><td colspan="9" class="sec">1. GENERAL INFORMATION &amp; TRAVEL DETAILS</td></tr>
	<tr class="h26">
		<td class="lbl">Claimant Name:</td>
		<td colspan="4" class="in"><?php echo $field( 'claimant_name', $claim['claimant_name'] ?? '', 'text', 'required maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td class="lbl">Submission Date:</td>
		<td colspan="3" class="in"><?php echo $edit ? esc_html( cel_claim_display_date( $claim['submission_date'] ?? '' ) ) . ' <span class="cel-auto">(auto)</span>' : esc_html( cel_claim_display_date( $claim['submission_date'] ?? '' ) ); ?></td>
	</tr>
	<tr class="h26">
		<td class="lbl">Purpose of Travel:</td>
		<td colspan="4" class="in"><?php echo $field( 'purpose', $claim['purpose'] ?? '', 'text', 'required maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td class="lbl">Destination / City:</td>
		<td colspan="3" class="in"><?php echo $field( 'destination', $claim['destination'] ?? '', 'text', 'maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
	</tr>
	<tr class="h26">
		<td class="lbl">Travel Period:</td>
		<td colspan="4" class="in"><?php echo $field( 'travel_period', $claim['travel_period'] ?? '', 'text', 'maxlength="200" placeholder="' . ( $edit ? 'e.g. 5 Days' : '' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td class="lbl">Department / Project:</td>
		<td colspan="3" class="in"><?php echo $field( 'department', $claim['department'] ?? '', 'text', 'required maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
	</tr>
	<tr class="h21"><td colspan="9" class="blank"></td></tr>

	<tr class="h29"><td colspan="9" class="sec">2. SECTION A: OUTSTATION &amp; MEAL ALLOWANCES (FIXED INTERNAL SCHEDULE)</td></tr>
	<tr class="h32 head">
		<td>No.</td><td>Date</td><td colspan="3">Destination / Activity Details</td>
		<td>Outstation Allowance</td><td colspan="2">Meal Allowance Selection</td><td>Total Allowance (RM)</td>
	</tr>
	<tbody data-cel-rows="a">
	<?php for ( $i = 0; $i < $rows_a; $i++ ) : ?>
		<?php
		$r     = $allowances[ $i ] ?? array();
		$out   = $r['out'] ?? 'None';
		$meal  = $r['meal'] ?? 'None';
		$total = ( $out_rates[ $out ] ?? 0 ) + ( $meal_rates[ $meal ] ?? 0 );
		$has   = ! empty( $r['date'] ) || ! empty( $r['details'] ) || $total > 0;
		?>
		<tr class="h26 row">
			<td class="c num"><?php echo (int) ( $i + 1 ); ?></td>
			<td class="in c"><?php echo $field( "a[$i][date]", $r['date'] ?? '', 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td colspan="3" class="in"><?php echo $field( "a[$i][details]", $r['details'] ?? '', 'text', 'maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td class="in c"><?php echo $select( "a[$i][outstation]", $out_opts, $out, 'data-cel-out' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td colspan="2" class="in c"><?php echo $select( "a[$i][meal]", $meal_opts, $meal, 'data-cel-meal' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td class="tot b" data-cel-line><?php echo $has ? esc_html( cel_claim_money( $total ) ) : ''; ?></td>
		</tr>
	<?php endfor; ?>
	</tbody>
	<tr class="h29 subrow">
		<td colspan="8" class="sub">SUBTOTAL SECTION A (ALLOWANCES):</td>
		<td class="tot b subtot" data-cel-sub="a"><?php echo esc_html( cel_claim_money( $sub_a ) ); ?></td>
	</tr>
	<tr class="h21"><td colspan="9" class="blank"></td></tr>

	<tr class="h29"><td colspan="9" class="sec">3. SECTION B: INCURRED TRAVEL EXPENSES (REIMBURSABLE AT ACTUAL COST - RECEIPTS ATTACHED)</td></tr>
	<tr class="h32 head">
		<td>No.</td><td>Date</td><td colspan="2">Expense Category</td>
		<td colspan="2">Merchant / Description / Journey Purpose</td><td colspan="2">Receipt / Inv No.</td><td>Amount (RM)</td>
	</tr>
	<tbody data-cel-rows="b">
	<?php for ( $i = 0; $i < $rows_b; $i++ ) : ?>
		<?php $r = $expenses[ $i ] ?? array(); ?>
		<tr class="h26 row">
			<td class="c num"><?php echo (int) ( $i + 1 ); ?></td>
			<td class="in c"><?php echo $field( "b[$i][date]", $r['date'] ?? '', 'date' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td colspan="2" class="in"><?php echo $select( "b[$i][category]", $cat_opts, $r['category'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td colspan="2" class="in"><?php echo $field( "b[$i][merchant]", $r['merchant'] ?? '', 'text', 'maxlength="200"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td colspan="2" class="in c"><?php echo $field( "b[$i][receipt]", $r['receipt'] ?? '', 'text', 'maxlength="100"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			<td class="in r">
			<?php
			if ( $edit ) {
				echo '<input type="number" name="' . esc_attr( "b[$i][amount]" ) . '" value="' . esc_attr( (string) ( $r['amount'] ?? '' ) ) . '" min="0" step="0.01" inputmode="decimal" data-cel-amount>';
			} elseif ( isset( $r['amount'] ) && '' !== $r['amount'] ) {
				echo esc_html( cel_claim_money( $r['amount'] ) );
			}
			?>
			</td>
		</tr>
	<?php endfor; ?>
	</tbody>
	<tr class="h29 subrow">
		<td colspan="8" class="sub">SUBTOTAL SECTION B (ACTUAL EXPENSES):</td>
		<td class="tot b subtot" data-cel-sub="b"><?php echo esc_html( cel_claim_money( $sub_b ) ); ?></td>
	</tr>
	<tr class="h21"><td colspan="9" class="blank"></td></tr>

	<tr class="h29"><td colspan="9" class="sec">4. SUMMARY OF CLAIMS &amp; COMPLIANCE GUIDELINES</td></tr>
	<tr class="h24">
		<td colspan="5" rowspan="4" class="guide"><?php echo nl2br( esc_html( $settings['guidelines'] ) ); ?></td>
		<td colspan="3" class="sumlbl">Subtotal Section A (Allowances):</td>
		<td class="tot b" data-cel-sum="a"><?php echo esc_html( cel_claim_money( $sub_a ) ); ?></td>
	</tr>
	<tr class="h24 gr">
		<td colspan="3" class="sumlbl">Subtotal Section B (Actual Expenses):</td>
		<td class="tot b" data-cel-sum="b"><?php echo esc_html( cel_claim_money( $sub_b ) ); ?></td>
	</tr>
	<tr class="h24 gr">
		<td colspan="3" class="grand">TOTAL AMOUNT CLAIMED (RM):</td>
		<td class="grand gv" data-cel-grand><?php echo esc_html( cel_claim_money( $sub_a + $sub_b ) ); ?></td>
	</tr>
	<tr class="h24 gr last">
		<td colspan="3" class="endcell"></td>
		<td class="endcell"></td>
	</tr>
</table>
</div>
<?php if ( $edit ) : ?>
<template data-cel-tpl="a">
	<tr class="h26 row">
		<td class="c num"></td>
		<td class="in c"><input type="date" data-n="date"></td>
		<td colspan="3" class="in"><input type="text" maxlength="200" data-n="details"></td>
		<td class="in c"><?php echo $select( '', $out_opts, 'None', 'data-n="outstation" data-cel-out' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td colspan="2" class="in c"><?php echo $select( '', $meal_opts, 'None', 'data-n="meal" data-cel-meal' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td class="tot b" data-cel-line></td>
	</tr>
</template>
<template data-cel-tpl="b">
	<tr class="h26 row">
		<td class="c num"></td>
		<td class="in c"><input type="date" data-n="date"></td>
		<td colspan="2" class="in"><?php echo $select( '', $cat_opts, '', 'data-n="category"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
		<td colspan="2" class="in"><input type="text" maxlength="200" data-n="merchant"></td>
		<td colspan="2" class="in c"><input type="text" maxlength="100" data-n="receipt"></td>
		<td class="in r"><input type="number" min="0" step="0.01" inputmode="decimal" data-n="amount" data-cel-amount></td>
	</tr>
</template>
<?php endif; ?>
