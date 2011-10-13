<?php
/*
Plugin Name: Pingbacks Plus
Plugin URI: 
Description: Adds all clicked links to your site as a pingback
Version: 0.1a
Author: Benjamin J. Balter
Author URI: http://ben.balter.com
License: GPL2
*/

class Pingbacks_Plus {

	public $ua = 'pingbacks-plus';
	public $cookie = 'wp_referrer_check';
	public $query_var = 'pingback';

	/**
	 * Registers Hooks
	 */
	function __construct() {
		add_action( 'wp_head', array( &$this, 'enqueue_js') );
		add_filter( 'query_vars', array( &$this, 'add_query_var' ), 10, 1 );
		add_action( 'init', array( &$this, 'process_ping') );
		
		$file = 'js/jquery.cookie';
		$file .= ( WP_DEBUG ) ? '.dev.js' : '.js';
		wp_register_script( 'jquery-cookie', plugins_url( $file , __FILE__ ), array( 'jquery' ), filemtime( dirname( __FILE__ ) . '/' . $file ), true );
	
	}
	
	/**
	 * Enques ping javascript
	 */
	function enqueue_js() {
	
		if ( !isset( $_SERVER['HTTP_REFERER'] ) || empty( $_SERVER['HTTP_REFERER'] ) || !is_single() || is_user_logged_in() || stripos( $_SERVER['HTTP_REFERER'], get_bloginfo( 'url' ) ) !== false )
			return;
	
		$file = 'js/ping';
		$file .= ( WP_DEBUG ) ? '.dev.js' : '.js';
		wp_enqueue_script( 'pingbacks-plus', plugins_url( $file, __FILE__ ), array( 'jquery', 'jquery-cookie' ), filemtime( dirname( __FILE__ ) . '/' . $file ), true );
		
		global $post;
		
		//only load on pages and posts
		if ( !$post || !is_single() )
			return;
			
		wp_localize_script( 'pingbacks-plus', 'pingbacks_plus', array(
			'postID' => $post->ID,
			'cookie' => $this->cookie,
		) );

	}

	/**
	 * Registers pingback query arg with WP
	 * @param array $query_vars the standard query_vars
	 * @returns array query_vars with ours added
	 */
	function add_query_var( $query_vars ) {
	
	    $query_vars[] = $this->query_var;
		return $query_vars;
		
	}
	
	/**
	 * Primary function, processes ajax ping
	 */
	function process_ping() {
	
		if ( !isset( $_GET[ $this->query_var ] ) )
			return;	

		if ( is_user_logged_in() )
			die( -1 );
			
		if ( !isset( $_GET['postID'] ) || !isset( $_GET['referrer'] ) )
			die( -1 );
			
		global $wpdb;
		include_once(ABSPATH . WPINC . '/class-IXR.php');
		include_once(ABSPATH . WPINC . '/class-wp-xmlrpc-server.php');
		$xmlrpc = new wp_xmlrpc_server();
		
		//sanitze referrer, get post
		$pagelinkedfrom = addslashes( $_GET['referrer'] );
		$post_ID = (int) $_GET['postID'];
		$post = get_post( $post_ID );

		//verify post and pingable
		if ( !$post || !pings_open( $post ) )
			die( -1 );
		
		$pagelinkedto = get_permalink( $post->ID );
		
		//verify not an internal link
		if ( stripos( $pagelinkedfrom, get_bloginfo( 'url' ) ) !== false )
			die( -1 );
			
		//verify not already pinged
		if ( $foo = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_author_url = %s", $post_ID, $pagelinkedfrom ) ) ) 
			die( -1 );
		
		//verify source page exists
		$linea = wp_remote_fopen( $pagelinkedfrom );
		if ( !$linea )
			die( -1 );
		
		//below inspired by class-wp-xmlrpc-server.php
		
		$linea = apply_filters('pre_remote_source', $linea, $pagelinkedto);

		// Work around bug in strip_tags():
		$linea = str_replace('<!DOC', '<DOC', $linea);
		$linea = preg_replace( '/[\s\r\n\t]+/', ' ', $linea ); // normalize spaces
		$linea = preg_replace( "/ <(h1|h2|h3|h4|h5|h6|p|th|td|li|dt|dd|pre|caption|input|textarea|button|body)[^>]*>/", "\n\n", $linea );

		preg_match('|<title>([^<]*?)</title>|is', $linea, $matchtitle);
		$title = $matchtitle[1];
		if ( !$title )
			die( -1 );		

		$linea = strip_tags( $linea, '<a>' ); // just keep the tag we need

		$p = explode( "\n\n", $linea );
		$preg_target = preg_quote( $pagelinkedto, '|' );

		foreach ( $p as $para ) {
		
			if ( strpos($para, $pagelinkedto) !== false ) { // it exists, but is it a link?
				
				preg_match("|<a[^>]+?" . $preg_target . "[^>]*>([^>]+?)</a>|", $para, $context);

				// If the URL isn't in a link context, keep looking
				if ( empty( $context ) )
					continue;

				// We're going to use this fake tag to mark the context in a bit
				// the marker is needed in case the link text appears more than once in the paragraph
				$excerpt = preg_replace('|\</?wpcontext\>|', '', $para);

				// prevent really long link text
				if ( strlen($context[1]) > 100 )
					$context[1] = substr($context[1], 0, 100) . '...';

				$marker = '<wpcontext>'.$context[1].'</wpcontext>';    // set up our marker
				$excerpt= str_replace($context[0], $marker, $excerpt); // swap out the link for our marker
				$excerpt = strip_tags($excerpt, '<wpcontext>');        // strip all tags but our context marker
				$excerpt = trim($excerpt);
				$preg_marker = preg_quote($marker, '|');
				$excerpt = preg_replace("|.*?\s(.{0,100}$preg_marker.{0,100})\s.*|s", '$1', $excerpt);
				$excerpt = strip_tags($excerpt); // YES, again, to remove the marker wrapper
				break;
				
			}
		}

		if ( empty( $context ) ) // Link to target not found
			die( -1 );
		
		$pagelinkedfrom = str_replace('&', '&amp;', $pagelinkedfrom);

		$context = '[...] ' . esc_html( $excerpt ) . ' [...]';
		$pagelinkedfrom = $wpdb->escape( $pagelinkedfrom );

		$comment_post_ID = (int) $post_ID;
		$comment_author = $title;
		$comment_author_email = '';
		$xmlrpc->escape($comment_author);
		$comment_author_url = $pagelinkedfrom;
		$comment_content = $context;
		$xmlrpc->escape($comment_content);
		$comment_type = 'pingback';
		$comment_agent = 'pingbacks_plus';

		$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_url', 'comment_author_email', 'comment_content', 'comment_type', 'comment_agent' );
		
		//hide our faux-pingbacks on the front end
		if ( !is_admin() && apply_filters( 'pingbacks_plus_hide_frontend', true ) ); 
			add_filter( 'pre_comment_user_agent', array( &$this, 'user_agent_filter' ), 10, 1 );
		
		$comment_ID = wp_new_comment($commentdata);

		remove_filter( 'pre_comment_user_agent', array( &$this, 'user_agent_filter' ) );

		exit();	
	
	}
	
	/**
	 * Callback to modify user agent to identify pingbacks plus pings
	 */
	function user_agent_filter( $ua ) {
		return $this->ua;
	}
	
	/**
	 * Filter to modify get_comments on the front end to hide pingbacks-plus pings
	 * @param array $comments_clauses the SQL clauses
	 * @returns array the modified clauses
	 */
	function comments_clauses_filter( $comments_clauses ) {
		$comments_clauses['where'] .= " AND comment_agent != '" . $this->ua . "'";
		return $comments_clauses;
	}

}

$pingbacks_plus = new Pingbacks_Plus();
