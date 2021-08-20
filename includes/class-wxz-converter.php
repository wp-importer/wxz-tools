<?php

class WXZ_Converter {
	public $filelist = array();

	// From wp-includes/functions.php
	private function wp_is_numeric_array( $data ) {
		if ( version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
			return array_is_list( $data );
		}

	    if ( ! is_array( $data ) ) {
	        return false;
	    }

	    $keys        = array_keys( $data );
	    $string_keys = array_filter( $keys, 'is_string' );

	    return count( $string_keys ) === 0;
	}

	private function remove_empty_metadata_values( $meta_item ) {
		if ( empty( $meta_item['value'] ) ) {
			return false;
		}

		return $meta_item;
	}

	private function is_metadata_array( $meta_item ) {
		return is_array( $meta_item ) && array_keys( $meta_item ) === array( 'key', 'value' );
	}

	private function remove_empty_elements( $array ) {
		return array_filter(
			array_map(
				function( $el ) {
					if ( $this->is_metadata_array( $el ) ) {
						return $this->remove_empty_metadata_values( $el );
					}

					if ( $this->wp_is_numeric_array( $el ) ) {
						// Re-index to ensure this continues to be a list array.
						return array_values( $this->remove_empty_elements( $el ) );
					}

					if ( is_array( $el ) ) {
						return $this->remove_empty_elements( $el );
					}

					if ( '' !== $el && 0 !== $el ) {
						return $el;
					}

					return false;
				},
				$array
			)
		);
	}

	private function json_encode( $data ) {
		return json_encode( $this->remove_empty_elements( $data ), JSON_PRETTY_PRINT );
	}

	private function get_new_id() {
		static $id = 1e6;
		return ++$id;
	}

	public function convert_wxr( $file ) {
		$this->filelist = array();

		$output_filename = preg_replace( '/\.(wxr|xml)$/i', '.zip', $file );
		if ( $output_filename === $file ) {
			$output_filename = $file . '.zip';
		}

		if ( file_exists( $output_filename ) ) {
			return new WP_Error( 'file-exists', "File $output_filename already exists." );
		}

		$archive = new PclZip( $output_filename );

		$dom       = new DOMDocument;
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}
		$success = $dom->loadXML( file_get_contents( $file ) );
		if ( ! is_null( $old_value ) ) {
			libxml_disable_entity_loader( $old_value );
		}

		if ( ! $success || isset( $dom->doctype ) ) {
			return new WP_Error( 'SimpleXML_parse_error', 'There was an error when reading this WXR file', libxml_get_errors() );
		}

		$xml = simplexml_import_dom( $dom );
		unset( $dom );

		// halt if loading produces an error
		if ( ! $xml ) {
			return new WP_Error( 'SimpleXML_parse_error', 'There was an error when reading this WXR file', libxml_get_errors() );
		}

		$namespaces = $xml->getDocNamespaces();
		if ( ! isset( $namespaces['wp'] ) ) {
			$namespaces['wp'] = 'http://wordpress.org/export/1.1/';
		}
		if ( ! isset( $namespaces['excerpt'] ) ) {
			$namespaces['excerpt'] = 'http://wordpress.org/export/1.1/excerpt/';
		}

		$config = array();
		foreach ( array(
			'/rss/channel/title'       => 'title',
			'/rss/channel/link'        => 'link',
			'/rss/channel/description' => 'description',
			'/rss/channel/pubDate'     => 'date',
			'/rss/channel/language'    => 'language',
		) as $xpath => $key ) {
			$val = $xml->xpath( $xpath );
			if ( ! $val ) {
				continue;
			}
			$config[ $key ] = (string) trim( $val[0] );
		}

		$write_to_dir = false;
		// $write_to_dir = getcwd() . '/export/'; // uncomment to write files.

		$this->add_file( 'site/config.json', $this->json_encode( $config ), $write_to_dir );

		// grab authors
		$c = 0;
		$author_lookup = array();
		$fallback_author = false;
		foreach ( $xml->xpath( '/rss/channel/wp:author' ) as $author_arr ) {
			$a      = $author_arr->children( $namespaces['wp'] );
			$login  = (string) $a->author_login;
			if ( ! intval( $a->author_id ) ) {
				$a->author_id = $this->get_new_id();
			}
			$author = array(
				'version' => 1,
				'id'      => intval( $a->author_id ),
				'login'   => $login,
				'email'   => (string) $a->author_email,
				'name'    => (string) $a->author_display_name,
			);
			$author_lookup[ $author['login'] ] = $author['id'];
			if ( ! $fallback_author ) {
				$fallback_author = $author['id'];
			}
			$this->add_file( 'users/' . intval( $a->author_id ) . '.json', $this->json_encode( $author ) );
		}

		$terms = array();
		$term_lookup = array();
		// grab cats, tags and terms
		foreach ( $xml->xpath( '/rss/channel/wp:category' ) as $term_arr ) {
			$t        = $term_arr->children( $namespaces['wp'] );

			$category = array(
				'version'     => 1,
				'id'          => (int) $t->term_id,
				'taxonomy'    => 'category',
				'name'        => (string) $t->cat_name,
				'slug'        => (string) $t->category_nicename,
				'description' => (string) $t->category_description,
			);
			$term_lookup['category'][ $category['slug'] ] = $category['id'];

			foreach ( $t->termmeta as $meta ) {
				$category['meta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			$parent = (string) $t->category_parent;
			$category['parent'] = !empty($parent)
			          && isset($term_lookup['category'][$parent])
				? $term_lookup['category'][$parent]
				: 0;

			$terms[] = $category;
		}

		foreach ( $xml->xpath( '/rss/channel/wp:tag' ) as $term_arr ) {
			$t   = $term_arr->children( $namespaces['wp'] );

			$tag = array(
				'version'     => 1,
				'id'          => (int) $t->term_id,
				'taxonomy'    => 'tag',
				'slug'        => (string) $t->tag_slug,
				'name'        => (string) $t->tag_name,
				'description' => (string) $t->tag_description,
			);
			$term_lookup['tag'][ $tag['slug'] ] = $tag['id'];

			foreach ( $t->termmeta as $meta ) {
				$tag['meta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}
			$terms[] = $tag;
		}

		foreach ( $xml->xpath( '/rss/channel/wp:term' ) as $term_arr ) {
			$t    = $term_arr->children( $namespaces['wp'] );

			$term = array(
				'version'     => 1,
				'id'          => (int) $t->term_id,
				'taxonomy'    => (string) $t->term_taxonomy,
				'slug'        => (string) $t->term_slug,
				'name'        => (string) $t->term_name,
				'description' => (string) $t->term_description,
			);
			$term_lookup[$term['taxonomy']][ $term['slug'] ] = $term['id'];

			foreach ( $t->termmeta as $meta ) {
				$term['meta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			$parent = (string) $t->term_parent;
			$term['parent'] = !empty($parent) && isset($term_lookup[$term['taxonomy']][$parent])
				? $term_lookup[$term['taxonomy']][$parent]
				: 0;


			$terms[] = $term;
		}

		foreach ( $terms as $t ) {
			$this->add_file( 'terms/' . intval( $t['id'] ) . '.json', $this->json_encode( $t ) );
		}

		// grab posts
		$c = 0;
		foreach ( $xml->channel->item as $item ) {
			$post = array(
				'version' => 1,
				'title'   => (string) $item->title,
			);

			$dc             = $item->children( 'http://purl.org/dc/elements/1.1/' );
			if ( isset( $author_lookup[(string) $dc->creator] ) ) {
				$post['author'] = $author_lookup[(string) $dc->creator];
			} else {
				$post['author'] = $fallback_author;
			}

			$content         = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$excerpt         = $item->children( $namespaces['excerpt'] );
			$post['content'] = (string) $content->encoded;
			$post['excerpt'] = (string) $excerpt->encoded;

			$wp                     = $item->children( $namespaces['wp'] );
			if ( ! intval( $wp->post_id ) ) {
				$wp->post_id = $this->get_new_id();
			}
			$post['id']             = intval( $wp->post_id );
			$post['published']      = $this->format_date( $wp->post_date_gmt );
			$post['pingsOpen']    = (boolean) $wp->ping_status;
			$post['slug']           = (string) $wp->post_name;
			$post['postStatus']         = (string) $wp->status;
			$post['parent']         = (int) $wp->post_parent;
			$post['menuOrder']     = (int) $wp->menu_order;
			$post['type']           = (string) $wp->post_type;
			$post['password']  = (string) $wp->post_password;
			$post['sticky']      = (int) $wp->is_sticky;

			if ( isset( $wp->attachment_url ) ) {
				$post['attachmentUrl'] = (string) $wp->attachment_url;
			}

			foreach ( $item->category as $c ) {
				$att = $c->attributes();
				if ( isset( $att['nicename'] ) ) {
					$slug = (string) $att['nicename'];
					if ( ! isset( $term_lookup[(string) $att['domain']][ $slug ] ) ) {
						$term = array(
							'version'     => 1,
							'id'          => $this->get_new_id(),
							'taxonomy'    => (string) $att['domain'],
							'slug'        => $slug,
							'name'        => $slug,
						);
						$this->add_file( 'terms/' . intval( $term['id'] ) . '.json', $this->json_encode( $term ) );
						$term_lookup[(string) $att['domain']][ $slug ] =$term['id'];
					}
					$post['terms'][] = $term_lookup[(string) $att['domain']][ $slug ];
				}
			}

			foreach ( $wp->postmeta as $meta ) {
				if ( strval( $meta->meta_value ) === "" ) {
					continue;
				}

				$post['meta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			foreach ( $wp->comment as $wp_comment ) {
				$meta = array();
				if ( isset( $wp_comment->commentmeta ) ) {
					foreach ( $wp_comment->commentmeta as $m ) {
						$meta[] = array(
							'key'   => (string) $m->meta_key,
							'value' => (string) $m->meta_value,
						);
					}
				}

				$comment = array(
					'version'     => 1,
					'id'          => (int) $wp_comment->comment_id,
					'author'      => array(
						'id'    => (int) $wp_comment->comment_user_id,
						'name'  => (string) $wp_comment->comment_author,
						'email' => (string) $wp_comment->comment_author_email,
						'url'   => (string) $wp_comment->comment_author_url,
						'IP'    => (string) $wp_comment->comment_author_IP,
					),
					'published'   => $this->format_date( $wp_comment->comment_date_gmt ) ,
					'content'     => (string) $wp_comment->comment_content,
					'type'        => (string) $wp_comment->comment_type,
					'parent'      => (int) $wp_comment->comment_parent,
					'post'        => $post['id'],
					'commentmeta' => $meta,
				);

				if ( 'spam' === $wp_comment->comment_approved ) {
					$comment['approved'] = 'spam';
				} else {
					$comment['approved'] = (bool) $wp_comment->comment_approved;
				}

				$this->add_file( 'comments/' . $comment['id'] . '.json', $this->json_encode( $comment ) );
			}

			$this->add_file( 'posts/' . intval( $wp->post_id ) . '.json', $this->json_encode( $post ) );
		}

		if ( $this->write_wxz( $archive ) ) {
			return $output_filename;
		}

		return new WP_Error( 'zip-creation-failed', 'Error creating the ZIP.' );
	}

	private function add_file( $filename, $content, $write_to_dir = false ) {
		$this->filelist[] = array(
			PCLZIP_ATT_FILE_NAME    => $filename,
			PCLZIP_ATT_FILE_CONTENT => $content,
		);

		if ( $write_to_dir ) {
			$dir          = dirname( $filename );
			$write_to_dir = rtrim( $write_to_dir, '/' ) . '/';
			if ( ! file_exists( $write_to_dir . $dir ) ) {
				mkdir( $write_to_dir . $dir, 0777, true );
			}
			file_put_contents( $write_to_dir . $filename, $content );
		}

		return $filename;
	}

	private function write_wxz( $archive ) {
		if ( empty( $this->filelist ) ) {
			return new WP_Error( 'no-files', 'No files to write.' );
		}

		// This two-step approach is needed to save the mimetype file uncompressed.
		$archive->create(
			array(
				array(
					PCLZIP_ATT_FILE_NAME    => 'mimetype',
					PCLZIP_ATT_FILE_CONTENT => 'application/vnd.wordpress.export+zip',
				),
			),
			PCLZIP_OPT_NO_COMPRESSION
		);

		// No we can add the actual files and use compression.
		$ret = $archive->add( $this->filelist );

		if ( ! $ret ) {
			return false;
		}

		return true;
	}

	/**
	 * Stripped down version of WordPress' mysql2date() and mysql_to_rfc3339() functions,
	 * converting the MySQL-style timestamps in a WXR to the RFC3339 format.
	 */
	private function format_date( $date ) {
		if ( empty( $date ) ) {
			return '';
		}

		$datetime = date_create( $date, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			return '';
		}

		return $datetime->format( 'Y-m-d\TH:i:s' );
	}
}
