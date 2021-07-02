<?php

class WXZ_Reader {
	private $mimetype = 'application/vnd.WordPress.export+zip';

	public function __construct() {
	}

	public function raise_error( $code, $message ) {
		echo 'ERR ', $message, PHP_EOL;
	}

	public function raise_warning( $code, $message ) {
		echo 'WARN ', $message, PHP_EOL;
	}

	public function verify_file_is_valid( $zip_filename ) {
		if ( ! file_exists( $zip_filename ) ) {
			$this->raise_error( 'file-exists', $zip_filename . ': file does not exist.' );
			return false;
		}

		if ( ! is_readable( $zip_filename ) ) {
			$this->raise_error( 'file-accessible', $zip_filename . ': file not accessible.' );
			return false;
		}

		$f = fopen( $zip_filename, 'rb' );
		if ( "PK\x3\x4" !== fread( $f, 4 ) ) {
			$this->raise_error( 'not-a-zip', $zip_filename . ': ZIP header could not be found.' );
			return false;
		}

		fseek( $f, 30 );
		if ( 'mimetype' !== fread( $f, 8 ) ) {
			$this->raise_error( 'first-file-not-mimetype', $zip_filename . ': The first file in the ZIP needs to be called mimetype.' );
			return false;
		}

		fseek( $f, 38 );
		if ( fread( $f, strlen( $this->mimetype ) ) === $this->mimetype ) {
			$this->raise_error( 'first-file-not-mimetype', $zip_filename . ': The file mimetype must only contain "' . $this->mimetype . '".' );
			return false;
		}

		return true;
	}

	public function read( $zip_filename ) {
		if ( '-' === $zip_filename ) {
			$zip_filename = tempnam( '/tmp/', 'wxz.zip' );
			file_put_contents( $zip_filename, file_get_contents( 'php://stdin' ) );
		}

		if ( ! $this->verify_file_is_valid( $zip_filename ) ) {
			return false;
		}

		$archive      = new PclZip( $zip_filename );
		$zip_filename = basename( $zip_filename );
		if ( ! $archive ) {
			$this->raise_error( 'zip-library', $zip_filename . ': Error loading zip library.' );
			return false;
		}
		$archive_files = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
		if ( $archive->errorCode() ) {
			$this->raise_error( 'zip-parsing', $zip_filename . ': ' . $archive->errorInfo() );
			return false;
		}

		$files = array();
		foreach ( $archive_files as $file ) {
			if ( $file['folder'] ) {
				continue;
			}

			if ( 'mimetype' === $file['filename'] ) {
				// Already checked above.
				continue;
			}
			$files[ $file['filename'] ] = trim( $file['content'] );
		}
		return $files;
	}
}
