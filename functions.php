<?php
/**
 * The action/filter for adding the endpoint & route for the tax_query
 *
 * @package   @jp-tax-query
 * @author    Josh Pollock <Josh@JoshPress.net>
 * @license   GPL-2.0+
 * @link      
 * @copyright 2014 Josh Pollock
 */
if ( ! function_exists( 'jp_api_tax_query' ) ) :
	add_action( 'wp_json_server_before_serve', 'jp_api_tax_query', 10, 1 );
	function jp_api_tax_query( $server ) {
		$jp_api_taxonomy = new JP_API_Tax_Query( $server );
		add_filter( 'json_endpoints', array( $jp_api_taxonomy, 'register_routes' ), 0 );
	}
endif;
