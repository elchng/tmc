<?php
/**
 * Stand-alone print page for one claim. Opens the browser's print dialog,
 * where staff choose "Save as PDF". Expects: $claim, $settings, $mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
$auto_print = ! isset( $_GET['noprint'] );
$file_name  = $claim['claim_no'] . ' - ' . $claim['claimant_name'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $file_name ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( CEL_CLAIM_URL . 'assets/claim.css?ver=' . CEL_CLAIM_VERSION ); ?>">
</head>
<body class="cel-print-body">
	<div class="cel-toolbar">
		<strong><?php echo esc_html( $claim['claim_no'] ); ?></strong>
		<span>Choose <em>Save as PDF</em> as the printer · Layout: Landscape · Paper: A4</span>
		<button type="button" class="cel-btn cel-btn-primary" onclick="window.print()">Print / Save as PDF</button>
	</div>
	<div class="cel-page">
		<?php include CEL_CLAIM_DIR . 'templates/sheet.php'; ?>
	</div>
	<?php if ( $auto_print ) : ?>
	<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
	<?php endif; ?>
</body>
</html>
