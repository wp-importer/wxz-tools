<?php

class WXZ_SQLite {
	private $db;
	private $schemas = array();
	public function __construct( $filename ) {
		$this->db = new PDO( 'sqlite:' . $filename );
	}

	private function get_sqlite_field_type( $type ) {
		switch ( $type ) {
			case 'string':
				return 'TEXT';
			case 'boolean':
				return 'INTEGER';
			case 'integer':
				return 'INTEGER';
			default:
				throw new Exception( 'Unknown type ' . $type );
		}
	}

	private function get_sqlite_field_type_id( $type ) {
		switch ( $this->get_sqlite_field_type( $type ) ) {
			case 'TEXT':
				return SQLITE3_TEXT;
			case 'INTEGER':
				return SQLITE3_INTEGER;
			default:
				throw new Exception( 'Unknown type' );
		}
	}

	public function create_tables( $schemas, $dir ) {
		$this->db->exec( 'CREATE TABLE IF NOT EXISTS config (
	id INTEGER PRIMARY KEY,
	name TEXT,
	value TEXT
)' );

		$this->db->exec( 'CREATE TABLE IF NOT EXISTS content (
	id INTEGER PRIMARY KEY,
	name TEXT
)' );

		// Create tables according to schemas.
		foreach ( $schemas as $type => $json_schema_url ) {
			$this->schemas[ $type ] = json_decode( file_get_contents( $dir . str_replace( 'https://wordpress.org', '', $json_schema_url ) ) );

			// Holds the fields for the CREATE TABLE statement.
			$create_table  = array();
			$create_posts_terms_table = false;
			$create_meta_table = false;

			foreach ( $this->schemas[ $type ]->properties as $key => $spec ) {
				if ( isset( $spec->{'$ref'} ) ) {
					$spec = json_decode( file_get_contents( $dir . str_replace( 'https://wordpress.org', '', $spec->{'$ref'} ) ) );
					$this->schemas[ $type ]->properties->$key = $spec;
				}
				if ( ! isset( $spec->type ) ) {
					continue;
				}

				// Version from the JSON file doesn't need to be carried over.
				if ( 'version' === $key ) {
					unset( $this->schemas[ $type ]->properties->$key );
					continue;
				}

				// Special treatment for posts_terms table below.
				if ( 'array' === $spec->type && 'terms' === $key ) {
					$create_posts_terms_table = 'CREATE TABLE IF NOT EXISTS posts_terms (
	id INTEGER PRIMARY KEY,
	post_id INTEGER REFERENCES posts( id ),
	term_id INTEGER REFERENCES terms( id ),
	UNIQUE( post_id, term_id ) ON CONFLICT REPLACE
)';
					continue;
				}

				// Special treatment for meta tables below.
				if ( 'array' === $spec->type && 'meta' === $key ) {
					$create_meta_table = 'CREATE TABLE IF NOT EXISTS ' . $type . '_meta (
	id INTEGER PRIMARY KEY,
	' . rtrim( $type, 's' ) . '_id INTEGER REFERENCES ' . $type . '( id ),
	`key` TEXT,
	value TEXT
)';
					continue;
				}

				// There is the option for a field to have multiple types.
				if ( is_array( $this->schemas[ $type ]->properties->$key->type ) ) {

					// In case one of the types is an object or a condition.
					if ( isset( $this->schemas[ $type ]->properties->$key->properties ) ) {
						if ( 'then' !== $key ) {
							$create_table[] = $key . ' ' . $this->get_sqlite_field_type( $this->schemas[ $type ]->properties->$key->type[0] );
						}
						foreach ( $this->schemas[ $type ]->properties->$key->properties as $property => $data ) {
							if ( 'then' === $key ) {
								// Even if it's just a condition, we'll want to create the column.
								$create_table[] = $property . ' ' . $this->get_sqlite_field_type( $data->type );
							} else {
								// Sub-properties are stored with keys of the form key_subkey.
								$create_table[] = $key . '_' . $property . ' ' . $this->get_sqlite_field_type( $data->type );
							}
						}
					} elseif ( isset( $this->schemas[ $type ]->properties->$key->items ) ) {
						// TODO: link content rows.
					}
				} elseif ( 'id' === $key ) {
					$create_table[] = 'id INTEGER PRIMARY KEY';
				} else {
					//
					$create_table[] = $key . ' ' . $this->get_sqlite_field_type( $this->schemas[ $type ]->properties->$key->type );
				}
			}

			$this->db->exec( 'CREATE TABLE IF NOT EXISTS ' . $type . ' (' . PHP_EOL . "\t". trim( implode( ',' . PHP_EOL . "\t", $create_table ) ) . PHP_EOL . ')' );

			if ( $create_meta_table ) {
				$this->db->exec( $create_meta_table );
			}
		}

		if ( $create_posts_terms_table ) {
			$this->db->exec( $create_posts_terms_table );
		}

	}

	public function insert_config( stdClass $data ) {
		$stmt = $this->db->prepare( 'INSERT INTO config (name, value) VALUES (:name, :value)' );

		foreach ( $data as $key => $value ) {
			$stmt->bindValue( ':name', $key, $this->get_sqlite_field_type_id( 'string' ) );
			$stmt->bindValue( ':value', $value, $this->get_sqlite_field_type_id( 'string' ) );
			$stmt->execute();
		}
	}

	public function insert_meta( $type, $id, $meta ) {
		$stmt = $this->db->prepare( 'INSERT INTO ' . $type . '_meta (' . rtrim( $type, 's' ) . '_id, `key`, value) VALUES (:post_id, :key, :value)' );

		foreach ( $meta as $data ) {
			$stmt->bindValue( ':'. rtrim( $type, 's' ) . '_id', $id, $this->get_sqlite_field_type_id( 'integer' ) );
			$stmt->bindValue( ':key', $data->key, $this->get_sqlite_field_type_id( 'string' ) );
			$stmt->bindValue( ':value', $data->value, $this->get_sqlite_field_type_id( 'string' ) );
			$stmt->execute();
		}
	}

	public function insert_post_terms( $post_id, $terms ) {
		$stmt = $this->db->prepare( 'INSERT INTO posts_terms (post_id, term_id) VALUES (:post_id, :term_id)' );

		foreach ( $terms as $term_id ) {
			$stmt->bindValue( ':post_id', $post_id, $this->get_sqlite_field_type_id( 'integer' ) );
			$stmt->bindValue( ':term_id', $term_id, $this->get_sqlite_field_type_id( 'integer' ) );
			$stmt->execute();
		}
	}

	public function insert( $type, stdClass $data ) {
		$insert_data = array();
		foreach ( $this->schemas[ $type ]->properties as $key => $spec ) {
			if ( ! isset( $spec->type ) ) {
				continue;
			}

			if ( 'array' === $spec->type && 'terms' === $key && isset( $data->$key ) ) {
				$this->insert_post_terms( $data->id, $data->$key );
				unset( $data->$key );
				continue;
			}

			if ( 'array' === $spec->type && 'meta' === $key && isset( $data->$key ) ) {
				$this->insert_meta( $type, $data->id, $data->$key );
				unset( $data->$key );
				continue;
			}

			// The particular JSON doesn't include this column from the schema.
			if ( ! isset( $data->$key ) ) {
				continue;
			}

			// Handle multiple possible types.
			if ( is_array( $this->schemas[ $type ]->properties->$key->type ) ) {
				if ( isset( $this->schemas[ $type ]->properties->$key->properties ) ) {

					// Try to copy each defined property into the insert data.
					foreach ( $this->schemas[ $type ]->properties->$key->properties as $property => $data ) {
						if ( ! isset( $data->$type->$property ) ) {
							continue;
						}
						if ( 'then' === $key ) {
							$insert_data[ $property ] = $data->$type->$property;
						} else {
							$insert_data[ $key . '_' . $property ] = $data->$type->$property;
						}
					}
				} elseif ( isset( $this->schemas[ $type ]->properties->$key->items ) ) {
					// content
				}
			} else {
				$insert_data[ $key ] = $data->$key;
			}
		}

		// Now that all data has been collected, we can just insert it.
		$stmt = $this->db->prepare( 'INSERT OR IGNORE INTO ' . $type . '(' . implode( ', ', array_keys( $insert_data ) ) . ') VALUES (:' . implode( ', :', array_keys( $insert_data ) ) . ')' );

		foreach ( $insert_data as $key => $value ) {
			$stmt->bindValue( ':' . $key, $value, $this->get_sqlite_field_type_id( gettype( $value ) ) );
		}
		return $stmt->execute();
	}
}
