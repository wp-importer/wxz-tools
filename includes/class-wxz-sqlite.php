<?php

class WXZ_SQLite {
	private $db;
	private $tables = array();
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
			$create_table  = array();

			$keys = array();
			foreach ( $this->schemas[ $type ]->properties as $key => $spec ) {
				if ( isset( $spec->{'$ref'} ) ) {
					$spec = json_decode( file_get_contents( $dir . str_replace( 'https://wordpress.org', '', $spec->{'$ref'} ) ) );
					$this->schemas[ $type ]->properties->$key = $spec;
				}
				if ( ! isset( $spec->type ) ) {
					continue;
				}
				if ( 'array' === $spec->type && 'terms' === $key ) {
					$keys[ $key ] = 'array';
					// Will be created below.
					continue;
				}
				if ( 'array' === $spec->type && 'meta' === $key ) {
					$keys[ $key ] = 'array';
					// Will be created below.
					continue;
				}
				if ( 'array' === $spec->type && 'content' === $key ) {
					continue;
					// Will be created below.
					continue;
				}
				if ( is_array( $this->schemas[ $type ]->properties->$key->type ) ) {
					if ( isset( $this->schemas[ $type ]->properties->$key->properties ) ) {
						foreach ( $this->schemas[ $type ]->properties->$key->properties as $property => $data ) {
							if ( 'then' === $key ) {
								$create_table[] = $property . ' ' . $this->get_sqlite_field_type( $data->type );
								$keys[ $property ] = $data->type;
							} else {
								$create_table[] = $key . '_' . $property . ' ' . $this->get_sqlite_field_type( $data->type );
								$keys[ $key . '_' . $property ] = $data->type;
							}
						}
					} elseif ( isset( $this->schemas[ $type ]->properties->$key->items ) ) {
						// content
					}
				} elseif ( 'id' === $key ) {
					$create_table[] = 'id INTEGER PRIMARY KEY';
					$keys[ $key ] = 'integer';
				} else {
					$create_table[] = $key . ' ' . $this->get_sqlite_field_type( $this->schemas[ $type ]->properties->$key->type );
					$keys[ $key ] = 'integer';
				}
			}

			$this->db->exec( 'CREATE TABLE IF NOT EXISTS ' . $type . ' (' . implode( ', ', $create_table ) . ')' );
			$this->tables[ $type ] = $keys;

			if ( isset( $keys['meta'] ) ) {
				$this->db->exec( 'CREATE TABLE IF NOT EXISTS ' . $type . '_meta (
					id INTEGER PRIMARY KEY,
					' . rtrim( $type, 's' ) . '_id INTEGER REFERENCES ' . $type . '( id ),
					`key` TEXT,
					value TEXT
				)' );
			}
		}

		$this->db->exec( 'CREATE TABLE IF NOT EXISTS posts_terms (
			id INTEGER PRIMARY KEY,
			post_id INTEGER REFERENCES posts( id ),
			term_id INTEGER REFERENCES terms( id ),
			UNIQUE( post_id, term_id ) ON CONFLICT REPLACE
		)' );


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
			if ( ! isset( $data->$key ) ) {
				continue;
			}
			if ( is_array( $this->schemas[ $type ]->properties->$key->type ) ) {
				if ( isset( $this->schemas[ $type ]->properties->$key->properties ) ) {
					foreach ( $this->schemas[ $type ]->properties->$key->properties as $property => $data ) {
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

		$stmt = $this->db->prepare( 'INSERT OR IGNORE INTO ' . $type . '(' . implode( ', ', array_keys( $insert_data ) ) . ') VALUES (:' . implode( ', :', array_keys( $insert_data ) ) . ')' );

		foreach ( $insert_data as $key => $value ) {
			$stmt->bindValue( ':' . $key, $value, $this->get_sqlite_field_type_id( gettype( $value ) ) );
		}
		return $stmt->execute();
	}
}
