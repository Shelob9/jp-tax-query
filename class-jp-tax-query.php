<?php
/**
 * The class for adding the endpoint & route for the tax_query
 *
 * @package   @jp-tax-query
 * @author    Josh Pollock <Josh@JoshPress.net>
 * @license   GPL-2.0+
 * @link
 * @copyright 2014 Josh Pollock
 */

if ( class_exists( 'JP_API_Tax_Query' ) || ! function_exists( 'json_url' ) ) {
	return;
}

if ( ! defined( 'JP_API_ROUTE' ) ) {
	define( 'JP_API_ROUTE', 'jp-api' );
}

class JP_API_Tax_Query {
	/**
	 * Server object
	 *
	 * @var WP_JSON_ResponseHandler
	 */
	protected $server;

	/**
	 * Constructor
	 *
	 * @param WP_JSON_ResponseHandler $server Server object
	 */
	public function __construct(WP_JSON_ResponseHandler $server) {
		$this->server = $server;

	}

	/**
	 * Register the post-related routes
	 *
	 * @param array $routes Existing routes
	 * @return array Modified routes
	 */
	public function register_routes( $routes ) {

		$route = JP_API_ROUTE;
		$tax_query_routes = array(
			//endpoints
			"/{$route}/tax-query" => array(
				array(
					array( $this, 'tax_query' ),      WP_JSON_Server::READABLE | WP_JSON_Server::ACCEPT_JSON
				),
			),
			"/{$route}/tax_query" => array(
				array(
					array( $this, 'tax_query' ),      WP_JSON_Server::READABLE | WP_JSON_Server::ACCEPT_JSON
				),
			)
		);

		$routes = array_merge( $tax_query_routes, $routes );
		return $routes;

	}


	public function tax_query( $data ) {
		$allowed = array( 'post_type', 'tax_query' );

		foreach( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed ) ) {
				unset( $data[ $key ] );
			}
		}

		if ( ! is_array( $data ) || empty( $data ) || ! isset( $data[ 'tax_query' ] ) ) {
			return new WP_Error( 'jp_api_tax_query', __( 'Invalid tax query.' ), array( 'status' => 500 ) );
		}

		$post_query = new WP_Query();
		$posts_list = $post_query->query( $data );
		$response   = new WP_JSON_Response();
		$response->query_navigation_headers( $post_query );

		if ( ! $posts_list ) {
			$response->set_data( array() );
			return $response;
		}

		// holds all the posts data
		$struct = array();

		$response->header( 'Last-Modified', mysql2date( 'D, d M Y H:i:s', get_lastpostmodified( 'GMT' ), 0 ).' GMT' );

		foreach ( $posts_list as $post ) {
			$post = get_object_vars( $post );

			// Do we have permission to read this post?
			if ( ! $this->check_read_permission( $post ) ) {
				continue;
			}

			$response->link_header( 'item', json_url( '/posts/' . $post['ID'] ), array( 'title' => $post['post_title'] ) );
			$post_data = $this->prepare_post( $post, 'view' );
			if ( is_wp_error( $post_data ) ) {
				continue;
			}

			$struct[] = $post_data;
		}
		$response->set_data( $struct );

		return $response;
	}

	/**
	 * Check if we can read a post
	 *
	 * Correctly handles posts with the inherit status.
	 * @param array $post Post data
	 * @return boolean Can we read it?
	 */
	protected function check_read_permission( $post ) {
		$post_type = get_post_type_object( $post['post_type'] );

		// Ensure the post type can be read
		if ( ! $post_type->show_in_json ) {
			return false;
		}

		// Can we read the post?
		if ( 'publish' === $post['post_status'] || current_user_can( $post_type->cap->read_post, $post['ID'] ) ) {
			return true;
		}

		// Can we read the parent if we're inheriting?
		if ( 'inherit' === $post['post_status'] && $post['post_parent'] > 0 ) {
			$parent = get_post( $post['post_parent'], ARRAY_A );

			if ( $this->check_read_permission( $parent ) ) {
				return true;
			}
		}

		// If we don't have a parent, but the status is set to inherit, assume
		// it's published (as per get_post_status())
		if ( 'inherit' === $post['post_status'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if we can edit a post
	 * @param array $post Post data
	 * @return boolean Can we edit it?
	 */
	protected function check_edit_permission( $post ) {
		$post_type = get_post_type_object( $post['post_type'] );

		if ( ! current_user_can( $post_type->cap->edit_post, $post['ID'] ) ) {
			return false;
		}

		return true;
	}



	/**
	 * Retrieve comments
	 *
	 * @param int $id Post ID to retrieve comments for
	 * @return array List of Comment entities
	 */
	public function get_comments( $id ) {
		//$args = array('status' => $status, 'post_id' => $id, 'offset' => $offset, 'number' => $number )l
		$comments = get_comments( array('post_id' => $id) );

		$post = get_post( $id, ARRAY_A );

		if ( empty( $post['ID'] ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_read_permission( $post ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 401 ) );
		}

		$struct = array();

		foreach ( $comments as $comment ) {
			$struct[] = $this->prepare_comment( $comment, array( 'comment', 'meta' ), 'collection' );
		}

		return $struct;
	}

	/**
	 * Retrieve a single comment
	 *
	 * @param int $comment Comment ID
	 * @return array Comment entity
	 */
	public function get_comment( $comment ) {
		$comment = get_comment( $comment );

		if ( empty( $comment ) ) {
			return new WP_Error( 'json_comment_invalid_id', __( 'Invalid comment ID.' ), array( 'status' => 404 ) );
		}

		$data = $this->prepare_comment( $comment );

		return $data;
	}


	/**
	 * Get a post type
	 *
	 * @param string|object $type Type name, or type object (internal use)
	 * @param boolean $context What context are we in?
	 * @return array Post type data
	 */
	public function get_post_type( $type, $context = 'view' ) {
		if ( ! is_object( $type ) ) {
			$type = get_post_type_object( $type );
		}

		if ( $type->show_in_json === false ) {
			return new WP_Error( 'json_cannot_read_type', __( 'Cannot view post type' ), array( 'status' => 403 ) );
		}

		if ( $context === true ) {
			$context = 'embed';
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, 'WPAPI-1.1', '$context should be set to "embed" rather than true' );
		}

		$data = array(
			'name'         => $type->label,
			'slug'         => $type->name,
			'description'  => $type->description,
			'labels'       => $type->labels,
			'queryable'    => $type->publicly_queryable,
			'searchable'   => ! $type->exclude_from_search,
			'hierarchical' => $type->hierarchical,
			'meta'         => array(
				'links' => array(
					'self'       => json_url( '/posts/types/' . $type->name ),
					'collection' => json_url( '/posts/types' ),
				),
			),
		);

		// Add taxonomy link
		$relation = 'http://wp-api.org/1.1/collections/taxonomy/';
		$url = json_url( '/taxonomies' );
		$url = add_query_arg( 'type', $type->name, $url );
		$data['meta']['links'][ $relation ] = $url;

		if ( $type->publicly_queryable ) {
			if ( $type->name === 'post' ) {
				$data['meta']['links']['archives'] = json_url( '/posts' );
			} else {
				$data['meta']['links']['archives'] = json_url( add_query_arg( 'type', $type->name, '/posts' ) );
			}
		}

		return apply_filters( 'json_post_type_data', $data, $type, $context );
	}

	/**
	 * Get the registered post statuses
	 *
	 * @return array List of post status data
	 */
	public function get_post_statuses() {
		$statuses = get_post_stati(array(), 'objects');

		$data = array();

		foreach ($statuses as $status) {
			if ( $status->internal === true || ! $status->show_in_admin_status_list ) {
				continue;
			}

			$data[ $status->name ] = array(
				'name'         => $status->label,
				'slug'         => $status->name,
				'public'       => $status->public,
				'protected'    => $status->protected,
				'private'      => $status->private,
				'queryable'    => $status->publicly_queryable,
				'show_in_list' => $status->show_in_admin_all_list,
				'meta'         => array(
					'links' => array()
				),
			);
			if ( $status->publicly_queryable ) {
				if ( $status->name === 'publish' ) {
					$data[ $status->name ]['meta']['links']['archives'] = json_url( '/posts' );
				} else {
					$data[ $status->name ]['meta']['links']['archives'] = json_url( add_query_arg( 'status', $status->name, '/posts' ) );
				}
			}
		}

		return apply_filters( 'json_post_statuses', $data, $statuses );
	}

	/**
	 * Prepares post data for return in an XML-RPC object.
	 *
	 * @access protected
	 *
	 * @param array $post The unprepared post data
	 * @param string $context The context for the prepared post. (view|view-revision|edit|embed)
	 * @return array The prepared post data
	 */
	protected function prepare_post( $post, $context = 'view' ) {
		// holds the data for this post. built up based on $fields
		$_post = array( 'ID' => (int) $post['ID'] );

		$post_type = get_post_type_object( $post['post_type'] );

		if ( ! $this->check_read_permission( $post ) ) {
			return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 401 ) );
		}

		$previous_post = null;
		if ( ! empty( $GLOBALS['post'] ) ) {
			$previous_post = $GLOBALS['post'];
		}
		$post_obj = get_post( $post['ID'] );

		// Don't allow unauthenticated users to read password-protected posts
		if ( ! empty( $post['post_password'] ) ) {
			if ( ! $this->check_edit_permission( $post ) ) {
				return new WP_Error( 'json_user_cannot_read', __( 'Sorry, you cannot read this post.' ), array( 'status' => 403 ) );
			}

			// Fake the correct cookie to fool post_password_required().
			// Without this, get_the_content() will give a password form.
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$hasher = new PasswordHash( 8, true );
			$value = $hasher->HashPassword( $post['post_password'] );
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = wp_slash( $value );
		}

		$GLOBALS['post'] = $post_obj;
		setup_postdata( $post_obj );

		// prepare common post fields
		$post_fields = array(
			'title'           => get_the_title( $post['ID'] ), // $post['post_title'],
			'status'          => $post['post_status'],
			'type'            => $post['post_type'],
			'author'          => (int) $post['post_author'],
			'content'         => apply_filters( 'the_content', $post['post_content'] ),
			'parent'          => (int) $post['post_parent'],
			#'post_mime_type' => $post['post_mime_type'],
			'link'            => get_permalink( $post['ID'] ),
		);

		$post_fields_extended = array(
			'slug'           => $post['post_name'],
			'guid'           => apply_filters( 'get_the_guid', $post['guid'] ),
			'excerpt'        => $this->prepare_excerpt( $post['post_excerpt'] ),
			'menu_order'     => (int) $post['menu_order'],
			'comment_status' => $post['comment_status'],
			'ping_status'    => $post['ping_status'],
			'sticky'         => ( $post['post_type'] === 'post' && is_sticky( $post['ID'] ) ),
		);

		$post_fields_raw = array(
			'title_raw'   => $post['post_title'],
			'content_raw' => $post['post_content'],
			'excerpt_raw' => $post['post_excerpt'],
			'guid_raw'    => $post['guid'],
			'post_meta'   => $this->get_all_meta( $post['ID'] ),
		);

		// Dates
		$timezone = json_get_timezone();


		if ( $post['post_date_gmt'] === '0000-00-00 00:00:00' ) {
			$post_fields['date'] = null;
			$post_fields_extended['date_tz'] = null;
			$post_fields_extended['date_gmt'] = null;
		}
		else {
			$date = WP_JSON_DateTime::createFromFormat( 'Y-m-d H:i:s', $post['post_date'], $timezone );
			$post_fields['date'] = $date->format( 'c' );
			$post_fields_extended['date_tz'] = $date->format( 'e' );
			$post_fields_extended['date_gmt'] = date( 'c', strtotime( $post['post_date_gmt'] ) );
		}

		if ( $post['post_modified_gmt'] === '0000-00-00 00:00:00' ) {
			$post_fields['modified'] = null;
			$post_fields_extended['modified_tz'] = null;
			$post_fields_extended['modified_gmt'] = null;
		}
		else {
			$modified = WP_JSON_DateTime::createFromFormat( 'Y-m-d H:i:s', $post['post_modified'], $timezone );
			$post_fields['modified'] = $modified->format( 'c' );
			$post_fields_extended['modified_tz'] = $modified->format( 'e' );
			$post_fields_extended['modified_gmt'] = date( 'c', strtotime( $post['post_modified_gmt'] ) );
		}

		// Authorized fields
		// TODO: Send `Vary: Authorization` to clarify that the data can be
		// changed by the user's auth status
		if ( current_user_can( $post_type->cap->edit_post, $post['ID'] ) ) {
			$post_fields_extended['password'] = $post['post_password'];
		}

		// Consider future posts as published
		if ( $post_fields['status'] === 'future' ) {
			$post_fields['status'] = 'publish';
		}

		// Fill in blank post format
		$post_fields['format'] = get_post_format( $post['ID'] );

		if ( empty( $post_fields['format'] ) ) {
			$post_fields['format'] = 'standard';
		}

		if ( ( 'view' === $context || 'view-revision' == $context ) && 0 !== $post['post_parent'] ) {
			// Avoid nesting too deeply
			// This gives post + post-extended + meta for the main post,
			// post + meta for the parent and just meta for the grandparent
			$parent = get_post( $post['post_parent'], ARRAY_A );
			$post_fields['parent'] = $this->prepare_post( $parent, 'embed' );
		}

		// Merge requested $post_fields fields into $_post
		$_post = array_merge( $_post, $post_fields );

		// Include extended fields. We might come back to this.
		$_post = array_merge( $_post, $post_fields_extended );

		if ( 'edit' === $context ) {
			if ( current_user_can( $post_type->cap->edit_post, $post['ID'] ) ) {
				if ( is_wp_error( $post_fields_raw['post_meta'] ) ) {
					$GLOBALS['post'] = $previous_post;
					if ( $previous_post ) {
						setup_postdata( $previous_post );
					}
					return $post_fields_raw['post_meta'];
				}

				$_post = array_merge( $_post, $post_fields_raw );
			} else {
				$GLOBALS['post'] = $previous_post;
				if ( $previous_post ) {
					setup_postdata( $previous_post );
				}
				return new WP_Error( 'json_cannot_edit', __( 'Sorry, you cannot edit this post' ), array( 'status' => 403 ) );
			}
		} elseif ( 'view-revision' == $context ) {
			if ( current_user_can( $post_type->cap->edit_post, $post['ID'] ) ) {
				$_post = array_merge( $_post, $post_fields_raw );
			} else {
				$GLOBALS['post'] = $previous_post;
				if ( $previous_post ) {
					setup_postdata( $previous_post );
				}
				return new WP_Error( 'json_cannot_view', __( 'Sorry, you cannot view this revision' ), array( 'status' => 403 ) );
			}
		}

		// Entity meta
		$links = array(
			'self'       => json_url( '/posts/' . $post['ID'] ),
			'author'     => json_url( '/users/' . $post['post_author'] ),
			'collection' => json_url( '/posts' ),
		);

		if ( 'view-revision' != $context ) {
			$links['replies'] = json_url( '/posts/' . $post['ID'] . '/comments' );
			$links['version-history'] = json_url( '/posts/' . $post['ID'] . '/revisions' );
		}

		$_post['meta'] = array( 'links' => $links );

		if ( ! empty( $post['post_parent'] ) ) {
			$_post['meta']['links']['up'] = json_url( '/posts/' . (int) $post['post_parent'] );
		}

		$GLOBALS['post'] = $previous_post;
		if ( $previous_post ) {
			setup_postdata( $previous_post );
		}
		return apply_filters( 'json_prepare_post', $_post, $post, $context );
	}

	/**
	 * Retrieve the post excerpt.
	 *
	 * @return string
	 */
	protected function prepare_excerpt( $excerpt ) {
		if ( post_password_required() ) {
			return __( 'There is no excerpt because this is a protected post.' );
		}

		$excerpt = apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $excerpt ) );

		if ( empty( $excerpt ) ) {
			return null;
		}

		return $excerpt;
	}

	/**
	 * Retrieve custom fields for post.
	 *
	 * @param int $id Post ID
	 * @return (array[]|WP_Error) List of meta object data on success, WP_Error otherwise
	 */
	public function get_all_meta( $id ) {
		$id = (int) $id;

		if ( empty( $id ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		$post = get_post( $id, ARRAY_A );

		if ( empty( $post['ID'] ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_edit_permission( $post ) ) {
			return new WP_Error( 'json_cannot_edit', __( 'Sorry, you cannot edit this post' ), array( 'status' => 403 ) );
		}

		global $wpdb;
		$table = _get_meta_table( 'post' );
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM $table WHERE post_id = %d", $id ) );

		$meta = array();

		foreach ( $results as $row ) {
			$value = $this->prepare_meta( $id, $row, true );

			if ( is_wp_error( $value ) ) {
				continue;
			}

			$meta[] = $value;
		}

		return apply_filters( 'json_prepare_meta', $meta, $id );
	}

	/**
	 * Retrieve custom field object.
	 *
	 * @param int $id Post ID
	 * @param int $mid Metadata ID
	 * @return array|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function get_meta( $id, $mid ) {
		$id = (int) $id;

		if ( empty( $id ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		$post = get_post( $id, ARRAY_A );

		if ( empty( $post['ID'] ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_edit_permission( $post ) ) {
			return new WP_Error( 'json_cannot_edit', __( 'Sorry, you cannot edit this post' ), array( 'status' => 403 ) );
		}

		$meta = get_metadata_by_mid( 'post', $mid );

		if ( empty( $meta ) ) {
			return new WP_Error( 'json_meta_invalid_id', __( 'Invalid meta ID.' ), array( 'status' => 404 ) );
		}

		if ( absint( $meta->post_id ) !== $id ) {
			return new WP_Error( 'json_meta_post_mismatch', __( 'Meta does not belong to this post' ), array( 'status' => 400 ) );
		}

		return $this->prepare_meta( $id, $meta );
	}

	/**
	 * Prepares meta data for return as an object
	 *
	 * @param int $post Post ID
	 * @param stdClass $data Metadata row from database
	 * @param boolean $is_serialized Is the value field still serialized? (False indicates the value has been unserialized)
	 * @return array|WP_Error Meta object data on success, WP_Error otherwise
	 */
	protected function prepare_meta( $post, $data, $is_raw = false ) {
		$ID    = $data->meta_id;
		$key   = $data->meta_key;
		$value = $data->meta_value;

		// Don't expose protected fields.
		if ( is_protected_meta( $key ) ) {
			return new WP_Error( 'json_meta_protected', sprintf( __( '%s is marked as a protected field.'), $key ), array( 'status' => 403 ) );
		}

		// Normalize serialized strings
		if ( $is_raw && is_serialized_string( $value ) ) {
			$value = unserialize( $value );
		}

		// Don't expose serialized data
		if ( is_serialized( $value ) || ! is_string( $value ) ) {
			return new WP_Error( 'json_meta_protected', sprintf( __( '%s contains serialized data.'), $key ), array( 'status' => 403 ) );
		}

		$meta = array(
			'ID'    => (int) $ID,
			'key'   => $key,
			'value' => $value,
		);

		return apply_filters( 'json_prepare_meta_value', $meta, $post );
	}

	/**
	 * Check if the data provided is valid data
	 *
	 * Excludes serialized data from being sent via the API.
	 *
	 * @see https://github.com/WP-API/WP-API/pull/68
	 * @param mixed $data Data to be checked
	 * @return boolean Whether the data is valid or not
	 */
	protected function is_valid_meta_data( $data ) {
		if ( is_array( $data ) || is_object( $data ) || is_serialized( $data ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add meta to a post
	 *
	 * @param int $id Post ID
	 * @param array $data {
	 *     @type string|null $key Meta key
	 *     @type string|null $key Meta value
	 * }
	 * @return bool|WP_Error
	 */
	public function add_meta( $id, $data ) {
		$id = (int) $id;

		if ( empty( $id ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		$post = get_post( $id, ARRAY_A );

		if ( empty( $post['ID'] ) ) {
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );
		}

		if ( ! $this->check_edit_permission( $post ) ) {
			return new WP_Error( 'json_cannot_edit', __( 'Sorry, you cannot edit this post' ), array( 'status' => 403 ) );
		}

		if ( ! array_key_exists( 'key', $data ) ) {
			return new WP_Error( 'json_post_missing_key', __( 'Missing meta key.' ), array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( 'value', $data ) ) {
			return new WP_Error( 'json_post_missing_value', __( 'Missing meta value.' ), array( 'status' => 400 ) );
		}

		if ( empty( $data['key'] ) ) {
			return new WP_Error( 'json_meta_invalid_key', __( 'Invalid meta key.' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_valid_meta_data( $data['value'] ) ) {
			// for now let's not allow updating of arrays, objects or serialized values.
			return new WP_Error( 'json_post_invalid_action', __( 'Invalid provided meta data for action.' ), array( 'status' => 400 ) );
		}

		if ( is_protected_meta( $data['key'] ) ) {
			return new WP_Error( 'json_meta_protected', sprintf( __( '%s is marked as a protected field.'), $data['key'] ), array( 'status' => 403 ) );
		}

		$meta_key = wp_slash( $data['key'] );
		$value    = wp_slash( $data['value'] );

		$result = add_post_meta( $id, $meta_key, $value );

		if ( ! $result ) {
			return new WP_Error( 'json_meta_could_not_add', __( 'Could not add post meta.' ), array( 'status' => 400 ) );
		}

		$response = json_ensure_response( $this->get_meta( $id, $result ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response->set_status( 201 );
		$response->header( 'Location', json_url( '/posts/' . $id . '/meta/' . $result ) );

		return $response;
	}



	/**
	 * Prepares comment data for returning as a JSON response.
	 *
	 * @param stdClass $comment Comment object
	 * @param array $requested_fields Fields to retrieve from the comment
	 * @param string $context Where is the comment being loaded?
	 * @return array Comment data for JSON serialization
	 */
	protected function prepare_comment( $comment, $requested_fields = array( 'comment', 'meta' ), $context = 'single' ) {
		$fields = array(
			'ID'   => (int) $comment->comment_ID,
			'post' => (int) $comment->comment_post_ID,
		);

		$post = (array) get_post( $fields['post'] );

		// Content
		$fields['content'] = apply_filters( 'comment_text', $comment->comment_content, $comment );
		// $fields['content_raw'] = $comment->comment_content;

		// Status
		switch ( $comment->comment_approved ) {
			case 'hold':
			case '0':
				$fields['status'] = 'hold';
				break;

			case 'approve':
			case '1':
				$fields['status'] = 'approved';
				break;

			case 'spam':
			case 'trash':
			default:
				$fields['status'] = $comment->comment_approved;
				break;
		}

		// Type
		$fields['type'] = apply_filters( 'get_comment_type', $comment->comment_type );

		if ( empty( $fields['type'] ) ) {
			$fields['type'] = 'comment';
		}

		// Post
		if ( 'single' === $context ) {
			$parent = get_post( $post['post_parent'], ARRAY_A );
			$fields['parent'] = $this->prepare_post( $parent, 'single-parent' );
		}

		// Parent
		if ( ( 'single' === $context || 'single-parent' === $context ) && (int) $comment->comment_parent ) {
			$parent_fields = array( 'meta' );

			if ( $context === 'single' ) {
				$parent_fields[] = 'comment';
			}
			$parent = get_comment( $post['post_parent'] );

			$fields['parent'] = $this->prepare_comment( $parent, $parent_fields, 'single-parent' );
		}

		// Parent
		$fields['parent'] = (int) $comment->comment_parent;

		// Author
		if ( (int) $comment->user_id !== 0 ) {
			$fields['author'] = (int) $comment->user_id;
		} else {
			$fields['author'] = array(
				'ID'     => 0,
				'name'   => $comment->comment_author,
				'URL'    => $comment->comment_author_url,
				'avatar' => json_get_avatar_url( $comment->comment_author_email ),
			);
		}

		// Date
		$timezone = json_get_timezone();

		$date               = WP_JSON_DateTime::createFromFormat( 'Y-m-d H:i:s', $comment->comment_date, $timezone );
		$fields['date']     = $date->format( 'c' );
		$fields['date_tz']  = $date->format( 'e' );
		$fields['date_gmt'] = date( 'c', strtotime( $comment->comment_date_gmt ) );

		// Meta
		$meta = array(
			'links' => array(
				'up' => json_url( sprintf( '/posts/%d', (int) $comment->comment_post_ID ) )
			),
		);

		if ( 0 !== (int) $comment->comment_parent ) {
			$meta['links']['in-reply-to'] = json_url( sprintf( '/posts/%d/comments/%d', (int) $comment->comment_post_ID, (int) $comment->comment_parent ) );
		}

		if ( 'single' !== $context ) {
			$meta['links']['self'] = json_url( sprintf( '/posts/%d/comments/%d', (int) $comment->comment_post_ID, (int) $comment->comment_ID ) );
		}

		// Remove unneeded fields
		$data = array();

		if ( in_array( 'comment', $requested_fields ) ) {
			$data = array_merge( $data, $fields );
		}

		if ( in_array( 'meta', $requested_fields ) ) {
			$data['meta'] = $meta;
		}

		return apply_filters( 'json_prepare_comment', $data, $comment, $context );
	}

}
