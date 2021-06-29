<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] )) {
	echo 'Usage: ', $self, ' <wordpress-export.zip>', PHP_EOL;
	exit(1);
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/class-wxz-validator.php';

$errors = false;
$validator = new WXZ_Validator;
foreach ( $_SERVER['argv'] as $zip_filename ) {
	if ( $validator->validate( $zip_filename ) ) {
		if ( empty( $validator->counter ) ) {
			$validator->raise_warning( 'empty', "No data found inside the file." );
		}
		echo basename( $zip_filename ), ' is valid';
		if ( $validator->warnings ) {
			echo ', ', $validator->warnings, ' warning', $validator->warnings > 1 ? 's were' : ' was', ' encountered';
		}
		echo '.', PHP_EOL;

		if ( ! empty( $validator->counter ) ) {
			echo 'The file contains:', PHP_EOL;
			foreach ( $validator->counter as $type => $count ) {
				echo '- ', $type, ': ', $count, PHP_EOL;
			}
		}
	} else {
		echo basename( $zip_filename ), ' is invalid!', PHP_EOL;
		$errors = true;
	}
}

if ( $errors ) {
	exit(1);
}
