<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] )) {
	echo 'Usage: ', $self, ' <wordpress-export.zip>', PHP_EOL;
	exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$terminal_color_red = "\e[31m";
$terminal_color_green = "\e[32m";
$terminal_color_yellow = "\e[33m";
$terminal_color_reset = "\e[0m";

$errors = false;
$validator = new WXZ_Validator;
foreach ( $_SERVER['argv'] as $zip_filename ) {
	if ( $validator->validate( $zip_filename ) ) {
		if ( empty( $validator->counter ) ) {
			$validator->raise_warning( 'empty', "No data found inside the file." );
		}
		if ( $validator->warnings ) {
			echo PHP_EOL;
		}

		echo $terminal_color_green, basename( $zip_filename ), ' is valid', $terminal_color_reset;
		if ( $validator->warnings ) {
			echo ', ', $terminal_color_yellow, $validator->warnings, ' warning', $validator->warnings > 1 ? 's were' : ' was', ' encountered', $terminal_color_reset;
		}
		echo '.', PHP_EOL;

		if ( ! empty( $validator->counter ) ) {
			echo 'The file contains these types of data:', PHP_EOL;
			foreach ( $validator->counter as $type => $count ) {
				echo '- ', $type, ': ', $count, PHP_EOL;
			}
		}
	} else {
		echo $terminal_color_red, basename( $zip_filename ), ' is invalid!', $terminal_color_reset, PHP_EOL;
		$errors = true;
	}
}

if ( $errors ) {
	exit(1);
}
