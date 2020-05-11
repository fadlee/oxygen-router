<?php

class Oxy_Router
{
	private static $instance;
	private $routes = [];
	private $current_uri = '';

	public static function add_route( $path, $template, $title = '' ) {
		if ( empty( self::$instance ) ) {
			self::$instance = new self;
		}

		$path = trim( $path, '/' );
		self::$instance->routes[$path] = compact( 'template', 'title' );
	}

	private function cleanup() {
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10, 2 );
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );

		remove_action( 'template_redirect', 'wp_shortlink_header', 11, 0 );

		remove_action( 'init', 'oxy_register_condition_taxonomy_term' );
		remove_action( 'init', 'oxy_register_category_condition' );
		remove_action( 'init', 'oxy_register_tag_condition' );
	}

	private function current_route() {
		return $this->routes[$this->current_uri] ? $this->routes[$this->current_uri] : false;
	}

	private function __construct() {
		$this->current_uri = trim( explode( '?', $_SERVER['REQUEST_URI'] ) [0], '/' );

		add_action('plugins_loaded', function () {
			$route_data = $this->current_route();

			if ( $route_data ) {
				// below is the code to disable unwanted SQL query
				$this->cleanup();

				add_filter( 'query_vars', function( $vars ) {
					$vars[] = '_oxy_router';
					return $vars;
				} );

				add_action( 'option_rewrite_rules', function ( $rules ) {
					$add = [$this->current_uri => "index.php?_oxy_router=1"];

					if ( is_array( $rules ) ) {
						$rules = array_merge( $add, $rules );
					} else {
						$rules = $add;
					}

					return $rules;
				});

				add_filter( 'posts_request', function ( $sql, $q ) use ( $route_data ) {
					/** @var WP_Query $q */
					if ( $q->is_main_query() ) {
						/*
						// disable row count
						$q->query_vars['no_found_rows'] = true;

						// disable cache
						$q->query_vars['cache_results'] = false;
						$q->query_vars['update_post_meta_cache'] = false;
						$q->query_vars['update_post_term_cache'] = false;
						*/

						$q->query_vars = [];
						$q->query_vars['no_found_rows'] = true;

						add_action( 'template_redirect', function () use ( $route_data, $q ) {
							$this->init_dummy_post( $route_data, $q );
						} );

						return false;
					}

					return $sql;
				}, 10, 3 );
			} // if route_data
		});
	}

	private function init_dummy_post( $route_data, $wp_query ) {
		global $wpdb, $post;

		$template_ID = $wpdb->get_var( "
            SELECT ID FROM $wpdb->posts 
            WHERE post_name = '{$route_data['template']}'
            LIMIT 1
        " );

		if ( ! $template_ID ) return false;

		$dummy_ID = $wpdb->get_var( "SELECT ID FROM $wpdb->posts ORDER BY ID DESC LIMIT 1" ) + 1;

		$dummy_post_properties = array(
			'ID' => $dummy_ID,
			'post_status' => 'publish',
			'post_author' => 1,
			'post_parent' => 0,
			'post_type' => 'page',
			'post_date' => current_time( 'mysql' ),
			'post_date_gmt' => current_time( 'mysql', true ),
			'post_modified' => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
			'post_content' => '',
			'post_title' => $route_data['title'],
			'post_excerpt' => '',
			'post_content_filtered' => '',
			'post_mime_type' => '',
			'post_password' => '',
			'post_name' => 'route-' . $dummy_ID,
			'guid' => '',
			'menu_order' => 0,
			'pinged' => '',
			'to_ping' => '',
			'ping_status' => '',
			'comment_status' => 'closed',
			'comment_count' => 0,
			'filter' => 'raw',
		);

		// Set the $post global.
		$post = new WP_Post( (object) $dummy_post_properties ); // @codingStandardsIgnoreLine.

		// Copy the new post global into the main $wp_query.
		$wp_query->post = $post;
		$wp_query->posts = array( $post );

		// Prevent comments form from appearing.
		$wp_query->post_count = 1;
		$wp_query->is_home = false;
		$wp_query->is_404 = false;
		$wp_query->is_page = true;
		$wp_query->is_single = true;
		$wp_query->is_singular = true;
		$wp_query->is_archive = false;
		$wp_query->is_tax = false;
		$wp_query->max_num_pages = 0;

		// Prepare everything for rendering.
		setup_postdata( $post );

		add_filter( 'get_post_metadata', function( $val, $post_id, $meta_key ) use ( $template_ID ) {
			if ( $meta_key == 'ct_other_template' ) {
				$val = $template_ID;
			}

			return $val;
		}, 10, 3 );

		add_filter( 'document_title_parts', function( $parts ) {
			$parts['title'] = get_the_title();
			return $parts;
		});

		add_filter( 'body_class', function ( $classes ) {
			array_push( $classes, 'oxy-route' );
			return $classes;
		});

	}

	public function check_404( $valid_404, $wp_query ) {
		$route_data = $this->current_route();

		if ( false === $route_data ) return $valid_404;
		if ( false === $this->init_dummy_post( $route_data, $wp_query ) ) return $valid_404;

		// prevent 404 error
		return ! $valid_404;
	}

}

// example domain.com/oxy-route
Oxy_Router::add_route( 'oxy-route', 'custom-route', 'Route 1' );
