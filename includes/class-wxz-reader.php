<?php

class WXZ_Reader {
	private $validator;

	public function __construct() {
		$this->validator = new WXZ_Validator;
	}

	public function raise_error( $code, $message ) {
		echo 'ERR ', $message, PHP_EOL;
		return new WP_Error( $code, $message );
	}

	public function raise_warning( $code, $message ) {
		echo 'WARN ', $message, PHP_EOL;
		return new WP_Error( $code, $message );
	}

	public function read( $zip_filename ) {
		if ( '-' === $zip_filename ) {
			$zip_filename = tempnam( '/tmp/', 'wxz.zip' );
			file_put_contents( $zip_filename, file_get_contents( 'php://stdin' ) );
		}

		$validation = $this->validator->verify_file_is_valid( $zip_filename );
		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		$archive      = new PclZip( $zip_filename );
		$zip_filename = basename( $zip_filename );
		if ( ! $archive ) {
			return $this->raise_error( 'zip-library', 'Error loading zip library.' );
		}
		$archive_files = $archive->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
		if ( $archive->errorCode() ) {
			return $this->raise_error( 'zip-parsing', $archive->errorInfo() );
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
