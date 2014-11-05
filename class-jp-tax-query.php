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

class JP_API_Tax_Query extends WP_JSON_Posts {

	/**
	 * Register the post-related routes
	 *
	 * @param array $routes Existing routes
	 * @return array Modified routes
	 */
	public function register_routes( $routes ) {

		$route = JP_API_ROUTE;
		$routes[] = array(
			//endpoints
			"/{$route}/tax-query" => array(
				array(
					array( $this, 'tax_query' ),      WP_JSON_Server::READABLE | WP_JSON_Server::ACCEPT_JSON
				),
			),
		);

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
			if ( json_check_post_permission( $post, 'read' ) ) {
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




}
