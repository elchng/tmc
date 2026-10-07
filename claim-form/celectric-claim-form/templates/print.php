<?php
/**
 * Stand-alone print page for one claim (no theme, loads only claim.css).
 * Opens the browser's print dialog, where staff choose "Save as PDF".
 * Expects: $claim, $mode.
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
		<span>Destination: <em>Save as PDF</em> · Layout: Landscape · Paper: A4</span>
		<button type="button" class="cel-btn cel-btn-primary" onclick="window.print()">Print / Save as PDF</button>
	</div>
	<div class="cel-page" id="cel-page">
		<?php include CEL_CLAIM_DIR . 'templates/' . $claim['type'] . '-sheet.php'; ?>
	</div>
	<script>
	(function () {
		// Shrink the A4 sheet to fit small screens (phones); printing uses the print CSS.
		var page = document.getElementById('cel-page'), frame = page.querySelector('.cel-frame');
		function fit() {
			var w = page.clientWidth - 16, full = frame.offsetWidth;
			frame.style.zoom = w < full ? (w / full).toFixed(3) : '';
		}
		window.addEventListener('resize', fit);
		window.addEventListener('beforeprint', function () { frame.style.zoom = ''; });
		window.addEventListener('afterprint', fit);
		fit();
		<?php if ( $auto_print ) : ?>
		window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
		<?php endif; ?>
	})();
	</script>
</body>
</html>
