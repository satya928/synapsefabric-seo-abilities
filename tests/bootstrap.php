<?php
// Minimal harness shared by the standalone tests (no WordPress needed).
define( 'SFSA_TESTING', true );
require_once __DIR__ . '/../includes/class-sfsa-similarity.php';
require_once __DIR__ . '/../includes/class-sfsa-analyzer.php';
require_once __DIR__ . '/../includes/class-sfsa-linker.php';

$GLOBALS['sfsa_fail'] = 0;
function check( $label, $cond ) {
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	$GLOBALS['sfsa_fail'] += $cond ? 0 : 1;
}
function finish() {
	exit( $GLOBALS['sfsa_fail'] ? 1 : 0 );
}
