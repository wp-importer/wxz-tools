<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] ) ) {
	echo 'Usage: ', $self, ' <wordpress-export.xml>', PHP_EOL;
	exit( 1 );
}

require __DIR__ . '/vendor/autoload.php';

$converter = new WXZ_Converter();
$errors    = false;
foreach ( $_SERVER['argv'] as $wxr_filename ) {
	$output_filename = $converter->convert_wxr( $wxr_filename );
	if ( $output_filename instanceof WP_Error ) {
		echo 'Error converting ', $wxr_filename, ': ', $output_filename->get_error_message(), PHP_EOL;
		$errors = true;
	} else {
		echo 'Converted ', $wxr_filename, ' to ', $output_filename, PHP_EOL;
	}
}

if ( $errors ) {
	exit( 1 );
}
