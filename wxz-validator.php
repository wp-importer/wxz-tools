<?php
$self = array_shift( $_SERVER['argv'] );
if ( empty( $_SERVER['argv'] )) {
	echo 'Usage: ', $self, ' <wordpress-export.zip>', PHP_EOL;
	exit(1);
}

require __DIR__ . '/libs/class-pclzip.php';

function raise_error( $code, $message ) {
	echo 'ERR ', $message, PHP_EOL;
}

function raise_warning( $code, $message ) {
	echo 'WARN ', $message, PHP_EOL;
}

foreach ( $_SERVER['argv'] as $filename ) {
	if ( ! file_exists( $filename ) ) {
		raise_error( 'file-exists', $filename . ': file does not exist.' );
		continue;
	}
	if ( ! is_readable( $filename ) ) {
		raise_error( 'file-accessible', $filename . ': file not accessible.' );
		continue;
	}
	$archive = new PclZip( $filename );
	if ( ! $archive ) {
		raise_error( 'zip-library', $filename . ': Error loading zip library.' );
		continue;
	}
	$archive_files = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
	if ( $archive->errorCode() ) {
		raise_error( 'zip-parsing', $filename . ': ' . $archive->errorInfo() );
		continue;
	}
	$archive_files = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );

	foreach ( $archive_files as $file ) {
		if ( $file['folder'] ) {
			continue;
		}
		$file_id = $filename . ' (' . $file['filename'] . ')';

		$type = dirname( $file['filename'] );
		$name = basename( $file['filename'], '.json' );
		$item = json_decode( $file['content'], true );

		if ( 'site' === $type ) {
			if ( 'config' === $name ) {
				if ( false === $item ) {
					raise_error( 'invalid-config', "$file_id: could not be parsed." );
					continue;
				}

				if ( ! isset( $config['title'] ) ) {
					raise_warning( 'title-missing', "$file_id: doesn't contain a title." );
				}
				continue;
			}
		}
	}
}
