<?php

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

class WXZ_Validator {
	public $errors;
	public $warnings;
	public $counter;
	private $jsonValidator;
	private $errorFormatter;

	private $schemas = array(
		'users' => 'user',
	);

	public function __construct() {
		// Create a validator.
		$this->jsonValidator = new Validator();
		$resolver = $this->jsonValidator->resolver();

		// Override the schema namespaces to load them locally.
		$resolver->registerPrefix( 'https://wordpress.org/schema/', __DIR__ . '/../schema' );

		// Create an error formatter.
		$this->errorFormatter = new ErrorFormatter();

	}

	function raise_error( $code, $message ) {
		$this->errors += 1;
		echo 'ERR ', $message, PHP_EOL;
	}

	function raise_warning( $code, $message ) {
		$this->warnings += 1;
		echo 'WARN ', $message, PHP_EOL;
	}

	public function validate( $zip_filename ) {
		$this->errors = 0;
		$this->warnings = 0;
		$this->counter = array();

		if ( ! file_exists( $zip_filename ) ) {
			$this->raise_error( 'file-exists', $zip_filename . ': file does not exist.' );
			return false;
		}
		if ( ! is_readable( $zip_filename ) ) {
			$this->raise_error( 'file-accessible', $zip_filename . ': file not accessible.' );
			return false;
		}
		$archive = new PclZip( $zip_filename );
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
			if ( ! $file['folder'] ) {
				$files[ $file['filename'] ] = trim( $file['content'] );
			}
		}

		if ( ! isset( $files['mimetype'] ) ) {
			$this->raise_error( 'mimetype-missing', $zip_filename . ': mimetype file is missing.' );
			return false;
		}

		$mimetype = 'application/vnd.wordpress.export+zip';
		if ( $mimetype !== $files['mimetype'] ) {
			$this->raise_error( 'mimetype-missing', $zip_filename . '/mimetype: content should be "' . $mimetype .'" (found: "' . substr( $files['mimetype'], 100 ) . '")' );
			return false;
		}

		unset( $files['mimetype'] );
		$schema_warned = array();
		foreach ( $files as $filename => $content ) {
			$file_id = "$zip_filename/$filename";

			$type = dirname( $filename );
			$name = basename( $filename, '.json' );
			$item = json_decode( $content );

			if ( 'site' === $type ) {
				if ( 'config' === $name ) {
					if ( false === $item ) {
						$this->raise_error( 'invalid-config', "$file_id: could not be parsed." );
						continue;
					}

					if ( ! isset( $item->title ) ) {
						$this->raise_warning( 'title-missing', "$file_id: doesn't contain a title." );
					}
					continue;
				}
			}

			if ( ! isset( $this->schemas[ $type ] ) ) {
				if ( ! isset( $schema_warned[ $type ] ) ) {
					$this->raise_warning( 'unknown-schema', $file_id . ': Unknown schema for "' . $type . '".' );
					$schema_warned[ $type ] = true;
				}
				continue;
			}

			$schema = $this->schemas[ $type ];

			try {
				$result = $this->jsonValidator->validate( $item, "https://wordpress.org/schema/$schema.json" );
			} catch ( Exception $e ) {
				$this->raise_warning( 'unknown-schema', "$file_id: " . $e->getMessage() );
				continue;
			}
			if ( $result->isValid() ) {
				if ( ! isset( $this->counter[ $type ] ) ) {
					$this->counter[ $type ] = 0;
				}
				$this->counter[ $type ] += 1;
			} else {
				// Get the error
				$error = $result->error();

				echo json_encode(
					$this->errorFormatter->format( $error, true ),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					) . "\n";
				echo "-----------\n";
			}
		}

		return ! $this->errors;
	}
}
