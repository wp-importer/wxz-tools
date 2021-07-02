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
	$db_file = new PDO( 'sqlite:'.__DIR__ . '/' . $sql_filename );
	foreach( $reader->read( $filename ) as $filename => $content ) {
		$type = dirname( $filename );
		$name = basename( $filename, '.json' );
		$item = json_decode( $content );

		if ( 'site' === $type && 'config' === $name ) {
			$db_file->exec( 'CREATE TABLE IF NOT EXISTS config (
				id INTEGER PRIMARY KEY,
				name TEXT,
				value TEXT
			)' );

			$insert = 'INSERT INTO config (name, value) VALUES (:name, :value)';
			$stmt = $db_file->prepare( $insert );

			foreach ( $item as $key => $value ) {
				$stmt->bindValue( ':name', $key, PDO::PARAM_STR );
				$stmt->bindValue( ':value', $value, PDO::PARAM_STR );
				$stmt->execute();
			}
			continue;
		}

		if ( isset( WXZ_Validator::$schemas[ $type ] ) ) {
			$schema = json_decode( file_get_contents( __DIR__ . str_replace( 'https://wordpress.org', '', WXZ_Validator::$schemas[ $type ] ) ) );
			$sql = 'CREATE TABLE IF NOT EXISTS ' . $type;
			$insert1 = 'INSERT OR IGNORE INTO ' . $type;
			$insert2 = ') VALUES';

			$t = '(';
			$k = '(';
			foreach ( $schema->properties as $key => $spec ) {
				if ( ! isset( $spec->type ) ) {
					continue;
				}
				$sql .= $t . $key . ' ';
				switch ( $schema->properties->$key->type ) {
					case 'string': $sql.= 'text'; break;
					default: $sql .=  $schema->properties->$key->type;
				}

				if ( 'id' === $key ) {
					$sql .= ' PRIMARY KEY';
				}
				$t = ',';

				if ( isset( $item->$key ) ) {
					$insert1 .= $k . $key;
					$insert2 .= $k . ':' . $key;
					$k = ',';
				}
			}
			$db_file->exec( $sql . ')' );

			$stmt = $db_file->prepare( $insert1 . $insert2 . ');' );
			foreach ( $item as $key => $val ) {
				if ( isset( $schema->properties->$key->type ) ) {
					switch ( $schema->properties->$key->type ) {
						case 'string': $param = SQLITE3_TEXT; break;
						case 'integer': $param = SQLITE3_INTEGER; break;
						case 'bool': $param = SQLITE3_INTEGER; break;
					}
					$stmt->bindValue( ':' . $key, $val, $param );
				}
			}
			$stmt->execute();
		}
	}
	echo 'Wrote ', $sql_filename, '.', PHP_EOL;
}
