<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] ) ) {
	echo 'Usage: ', $self, ' <wordpress-export.zip>', PHP_EOL;
	exit( 1 );
}

require __DIR__ . '/vendor/autoload.php';

$reader = new WXZ_Reader();
foreach ( $_SERVER['argv'] as $filename ) {
	$sql_filename = basename( $filename, '.zip' ) . '.sqlite';
	unlink( $sql_filename );

	$db = new WXZ_SQLite( __DIR__ . '/' . $sql_filename );
	$db->create_tables( WXZ_Validator::$schemas, __DIR__ );

	$tables = array();
	foreach( $reader->read( $filename ) as $filename => $content ) {
		$type = dirname( $filename );
		$name = basename( $filename, '.json' );
		$item = json_decode( $content );

		if ( 'site' === $type && 'config' === $name ) {
			$db->insert_config( $item );
			continue;
		}

		foreach ( $item as $key => $val ) {
			$db->insert( $type, $item );
		}
	}
	echo 'Wrote ', $sql_filename, '.', PHP_EOL;
}
