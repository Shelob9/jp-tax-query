JP Tax Query
=====================

Adds a tax_query endpoint to the jp-api route or the [WordPress REST API (WP-API)](https://github.com/WP-API/WP-API). You can pass, in the body of the request a tax_query. See [the codex](http://codex.wordpress.org/Class_Reference/WP_Query#Taxonomy_Parameters) for information on how to make one of those. The request's body can have 'post_type' and 'tax_query' arguments only. All other arguments will be stripped out before passing to WP_Query so don't try it.

<strong>This endpoint does NOT require authentication. Please consider whether or not you really want the whole internet to be able to run tax queries on your site before using.</strong>

##### TL;DR
The REST API only lets you filter by one term per taxonomy. This gives you all of the powers of tax_queries.

### Change name of route?
`define( 'JP_API_ROUTE', 'skywalker' );`

Endpoint is now `skywalker/tax_query`

### Installation
This is not a plugin.

The correct way to add it is to add `"shelob9/jp-tax-query": "dev-master"` to your site/plugin/theme's composer.json. Include composer autoloader.

Alternatively, add this repo to your site/plugin/theme using a Git Submodule or by employing the dark art of copypasta. 


### Usage

```php
    $args = array(
    	'post_type' => 'post',
    	'tax_query' => array(
    		'relation' => 'AND',
    		array(
    			'taxonomy' => 'movie_genre',
    			'field'    => 'slug',
    			'terms'    => array( 'action', 'comedy' ),
    		),
    		array(
    			'taxonomy' => 'actor',
    			'field'    => 'id',
    			'terms'    => array( 103, 115, 206 ),
    			'operator' => 'NOT IN',
    		),
    	),
    );
    $response = wp_remote_get( json_url( 'jp-api/tax-query') , array( 'body' => json_encode( $args ) ) );
```


### License
Copyright 2014 Josh Pollock. Licensed under the terms of the GNU General public license version 2. Please share with your neighbor.


