<?php

use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

class WXZ_Validator {
	public $errors   = 0;
	public $warnings = 0;
	public $counter  = array();
	private $json_validator;
	private $error_formatter;
	private $mimetype = 'application/vnd.WordPress.export+zip';

	// Maps the files in the directories to the schema.
	public static $schemas = array(
		'users'    => 'https://wordpress.org/schema/user.json',
		'terms'    => 'https://wordpress.org/schema/term.json',
		'posts'    => 'https://wordpress.org/schema/post.json',
		'comments' => 'https://wordpress.org/schema/comment.json',
	);

	public function __construct() {
		// Create a validator.
		$this->json_validator = new Validator();
		$resolver             = $this->json_validator->resolver();

		// Override the schema namespaces to load them locally.
		$resolver->registerPrefix( 'https://wordpress.org/schema/', __DIR__ . '/../schema' );

		// Create an error formatter.
		$this->error_formatter = new ErrorFormatter();

	}

	public function raise_error( $code, $message ) {
		$this->errors += 1;
		echo 'ERR ', $message, PHP_EOL;
		return new WP_Error( $code, $message );
	}

	public function raise_warning( $code, $message ) {
		$this->warnings += 1;
		echo 'WARN ', $message, PHP_EOL;
		return new WP_Error( $code, $message );
	}

	public function verify_file_is_valid( $zip_filename ) {
		if ( ! file_exists( $zip_filename ) ) {
			return $this->raise_error( 'file-exists', 'file does not exist.' );
		}

		if ( ! is_readable( $zip_filename ) ) {
			return $this->raise_error( 'file-accessible', 'file not accessible.' );
		}

		$f = fopen( $zip_filename, 'rb' );
		if ( "PK\x3\x4" !== fread( $f, 4 ) ) {
			return $this->raise_error( 'not-a-zip', 'ZIP header could not be found.' );
		}

		fseek( $f, 30 );
		if ( 'mimetype' !== fread( $f, 8 ) ) {
			return $this->raise_error( 'first-file-not-mimetype', 'The first file in the ZIP needs to be called mimetype.' );
		}

		fseek( $f, 38 );
		if ( fread( $f, strlen( $this->mimetype ) ) === $this->mimetype ) {
			return $this->raise_error( 'first-file-not-mimetype', 'The file mimetype must only contain "' . $this->mimetype . '".return ' );
		}

		return true;
	}

	public function validate( $zip_filename ) {
		$this->errors   = 0;
		$this->warnings = 0;
		$this->counter  = array();

		$validation = $this->verify_file_is_valid( $zip_filename );
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

		foreach ( $files as $filename => $content ) {
			$this->validate_file( $filename, $content );
		}

		if ( $this->errors ) {
			return new WP_Error( 'not-valid', 'Because of the errors found, the file cannot be deemed valid.' );
		}

		return true;
	}

	public function validate_file( $filename, $content ) {
		static $schema_warned = array();

		$type = dirname( $filename );
		$name = basename( $filename, '.json' );
		$item = json_decode( $content );

		if ( 'site' === $type ) {
			if ( 'config' === $name ) {
				if ( false === $item ) {
					return $this->raise_error( 'invalid-config', "$filename: could not be parsed." );
				}

				if ( ! isset( $item->title ) ) {
					return $this->raise_warning( 'title-missing', "$filename: doesn't contain a title." );
				}

				return true;
			}
		}

		if ( ! isset( self::$schemas[ $type ] ) ) {
			if ( ! isset( $schema_warned[ $type ] ) ) {
				$schema_warned[ $type ] = true;
				return $this->raise_warning( 'unknown-schema', $filename . ': Unknown schema for "' . $type . '".' );
			}
		}

		$schema = self::$schemas[ $type ];

		try {
			$result = $this->json_validator->validate( $item, $schema );
		} catch ( Exception $e ) {
			return $this->raise_warning( 'unknown-schema', $filename . ': ' . $e->getMessage() );
		}

		if ( ! $result->isValid() ) {
			return $this->raise_error(
				'schema-error',
				$filename . ': ' . json_encode(
					$this->error_formatter->format( $result->error(), true ),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
		}

		if ( ! isset( $this->counter[ $type ] ) ) {
			$this->counter[ $type ] = 0;
		}
		$this->counter[ $type ] += 1;

		$id = intval( $name );
		if ( ! isset( $item->id ) || $item->id !== $id ) {
			return $this->raise_warning( 'id-mismatch', $filename . ': id in json (' . ( isset( $item->id ) ? $item->id : 'missing' ) . ') differs from filename.' );
		}

		return true;
	}
}
