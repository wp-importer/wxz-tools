<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] ) ) {
	echo 'Usage: ', $self, ' <wordpress-export.zip>', PHP_EOL;
	exit( 1 );
}

require __DIR__ . '/vendor/autoload.php';

$reader = new WXZ_Reader();
foreach ( $_SERVER['argv'] as $filename ) {
	$bigjson = array();
	$files = $reader->read( $filename );
	if ( $files instanceof WP_Error ) {
		echo 'Error converting ', $filename, ': ', $files->get_error_message(), PHP_EOL;
	} else {
		foreach ( $files as $filename => $content ) {
			$type = dirname( $filename );
			$name = basename( $filename, '.json' );
			$item = json_decode( $content );

			if ( 'site' === $type && 'config' === $name ) {
				$bigjson['config'] = $item;
				continue;
			}

			if ( ! isset( $bigjson[ $type ] ) ) {
				$bigjson[ $type ] = array();
			}

			$bigjson[ $type ][] = $item;
		}
		echo json_encode( $bigjson, JSON_PRETTY_PRINT ), PHP_EOL;
	}
}
