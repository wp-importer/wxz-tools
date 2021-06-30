<?php

class WXZ_Converter {
	public $filelist = array();

	private function json_encode( $json ) {
		return json_encode( $json, JSON_PRETTY_PRINT );
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
		foreach ( $xml->xpath( '/rss/channel/wp:author' ) as $author_arr ) {
			$a      = $author_arr->children( $namespaces['wp'] );
			$login  = (string) $a->author_login;
			$author = array(
				'version'      => 1,
				'id'           => intval( $a->author_id ),
				'login'        => $login,
				'email'        => (string) $a->author_email,
				'display_name' => (string) $a->author_display_name,
			);
			$this->add_file( 'users/' . intval( $a->author_id ) . '.json', $this->json_encode( $author ) );
		}

		$terms = array();
		// grab cats, tags and terms
		foreach ( $xml->xpath( '/rss/channel/wp:category' ) as $term_arr ) {
			$t        = $term_arr->children( $namespaces['wp'] );
			$category = array(
				'version'     => 1,
				'id'          => intval( $t->term_id ),
				'nicename'    => (string) $t->category_nicename,
				'parent'      => (string) $t->category_parent,
				'slug'        => (string) $t->cat_name,
				'description' => (string) $t->category_description,
			);

			foreach ( $t->termmeta as $meta ) {
				$category['termmeta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			$terms[] = $category;
		}

		foreach ( $xml->xpath( '/rss/channel/wp:tag' ) as $term_arr ) {
			$t   = $term_arr->children( $namespaces['wp'] );
			$tag = array(
				'version'     => 1,
				'id'          => intval( $t->term_id ),
				'slug'        => (string) $t->tag_slug,
				'name'        => (string) $t->tag_name,
				'description' => (string) $t->tag_description,
			);

			foreach ( $t->termmeta as $meta ) {
				$tag['termmeta'][] = array(
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
				'id'          => intval( $t->term_id ),
				'taxonomy'    => (string) $t->term_taxonomy,
				'slug'        => (string) $t->term_slug,
				'parent'      => (string) $t->term_parent,
				'name'        => (string) $t->term_name,
				'description' => (string) $t->term_description,
			);

			foreach ( $t->termmeta as $meta ) {
				$term['termmeta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			$terms[] = $term;
		}

		foreach ( $terms as $t ) {
			$this->add_file( 'terms/' . intval( $t['id'] ) . '.json', $this->json_encode( $t ) );
		}

		// grab posts
		foreach ( $xml->channel->item as $item ) {
			$post = array(
				'version' => 1,
				'title'   => (string) $item->title,
				'guid'    => (string) $item->guid,
			);

			$dc             = $item->children( 'http://purl.org/dc/elements/1.1/' );
			$post['author'] = (string) $dc->creator;

			$content         = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$excerpt         = $item->children( $namespaces['excerpt'] );
			$post['content'] = (string) $content->encoded;
			$post['excerpt'] = (string) $excerpt->encoded;

			$wp                     = $item->children( $namespaces['wp'] );
			$post['id']             = intval( $wp->post_id );
			$post['date']           = (string) $wp->post_date;
			$post['date_utc']       = (string) $wp->post_date_gmt;
			$post['comment_status'] = (string) $wp->comment_status;
			$post['ping_status']    = (string) $wp->ping_status;
			$post['name']           = (string) $wp->post_name;
			$post['status']         = (string) $wp->status;
			$post['parent']         = (int) $wp->post_parent;
			$post['menu_order']     = (int) $wp->menu_order;
			$post['type']           = (string) $wp->post_type;
			$post['post_password']  = (string) $wp->post_password;
			$post['is_sticky']      = (int) $wp->is_sticky;

			if ( isset( $wp->attachment_url ) ) {
				$post['attachment_url'] = (string) $wp->attachment_url;
			}

			foreach ( $item->category as $c ) {
				$att = $c->attributes();
				if ( isset( $att['nicename'] ) ) {
					$post['terms'][] = array(
						'name'   => (string) $c,
						'slug'   => (string) $att['nicename'],
						'domain' => (string) $att['domain'],
					);
				}
			}

			foreach ( $wp->postmeta as $meta ) {
				$post['postmeta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			// foreach ( $wp->comment as $comment ) {
			// $meta = array();
			// if ( isset( $comment->commentmeta ) ) {
			// foreach ( $comment->commentmeta as $m ) {
			// $meta[] = array(
			// 'key'   => (string) $m->meta_key,
			// 'value' => (string) $m->meta_value,
			// );
			// }
			// }

			// $post['comments'][] = array(
			// 'comment_id'           => (int) $comment->comment_id,
			// 'comment_author'       => (string) $comment->comment_author,
			// 'comment_author_email' => (string) $comment->comment_author_email,
			// 'comment_author_IP'    => (string) $comment->comment_author_IP,
			// 'comment_author_url'   => (string) $comment->comment_author_url,
			// 'comment_date'         => (string) $comment->comment_date,
			// 'comment_date_gmt'     => (string) $comment->comment_date_gmt,
			// 'comment_content'      => (string) $comment->comment_content,
			// 'comment_approved'     => (string) $comment->comment_approved,
			// 'comment_type'         => (string) $comment->comment_type,
			// 'comment_parent'       => (string) $comment->comment_parent,
			// 'comment_user_id'      => (int) $comment->comment_user_id,
			// 'commentmeta'          => $meta,
			// );
			// }

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
}
