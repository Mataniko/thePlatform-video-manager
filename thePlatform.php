<?php
/*
Plugin Name: thePlatform Video Manager
Plugin URI: http://theplatform.com/
Description: Manage video assets hosted in thePlatform MPX from within WordPress.
Version: 1.0.0
Author: thePlatform for Media, Inc.
Author URI: http://theplatform.com/
License: GPL2

Copyright 2013 thePlatform for Media, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class ThePlatform_Plugin {

	var $plugin_base_dir;
	var $plugin_base_url;

	/*
	 * WP Option key
	 */
	private $plugin_options_key = 'theplatform';

	/**
	 * Initialize plugin
	 */
	function &init() {
		static $instance = false;

		if ( !$instance ) {
			$instance = new ThePlatform_Plugin;
		}

		return $instance;
	}

	/**
	 * Constructor
	 */
	function __construct() {
	
		
		require_once(dirname(__FILE__) . '/thePlatform-options.php' );
		require_once(dirname(__FILE__) . '/thePlatform-API.php' );
		require_once( dirname( __FILE__ ) . '/thePlatform-proxy.php' );

		$this->tp_api = new ThePlatform_API;
				
		$this->plugin_base_dir = plugin_dir_path(__FILE__);
		$this->plugin_base_url = plugins_url('/', __FILE__);
		
		add_action('admin_menu', array(&$this, 'add_media_page'));
		add_action('admin_init', array(&$this, 'register_scripts'));		
		add_action('media_buttons', array(&$this, 'theplatform_embed_button'), 100);	


		add_action('wp_ajax_initialize_media_upload', array($this->tp_api, 'initialize_media_upload'));
		add_action( 'wp_ajax_theplatform_embed', array(&$this, 'embed')); 	


		add_shortcode('theplatform', array(&$this, 'shortcode'));
	}
	
	function embed() {
		require_once( $this->plugin_dir . 'thePlatform-embed.php' );
		die();
	}
	
	/**
	 * Registers javascripts and css
	 */
	function register_scripts() {
		wp_register_script('theplatform_js', plugins_url('/js/theplatform.js', __FILE__), array('jquery'));
		wp_register_script('nprogress_js', plugins_url('/js/nprogress.js', __FILE__), array('jquery'));

		wp_localize_script('theplatform_js', 'theplatform', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'plugin_base_url' => plugins_url('images/', __FILE__),
			'tp_nonce' => wp_create_nonce('plugin-name-action_tpnonce')
		));

		wp_register_style('theplatform_css', plugins_url('/css/thePlatform.css', __FILE__ ));
		wp_register_style('nprogress_css', plugins_url('/css/nprogress.css', __FILE__ ));
	}
	
	/**
	 * Add media page (library view, detail view, media uploader)
	 */
	function add_media_page() {
		add_media_page('thePlatform', 'thePlatform Video', 'upload_files', 'theplatform-media', array( &$this, 'media_page' ));
	}

	function media_page() {
		require_once( dirname( __FILE__ ) . '/thePlatform-media.php' );
	}

	/**
	 * Adds thePlatform media embed button to the media upload
	 */
	function theplatform_embed_button() {

		global $post_ID, $temp_ID;
		$iframe_post_id = (int) ( 0 == $post_ID ? $temp_ID : $post_ID );

		$title = 'Embed Video from thePlatform';
		$image_url = plugins_url('/images/embed_button.png', __FILE__);
 		$site_url = admin_url("/admin-ajax.php?post_id=$iframe_post_id&theplatform=popup&action=theplatform_embed&TB_iframe=true&"); 
		echo '<a href="' . esc_url($site_url) . '&id=add_form" class="thickbox button" title="' . esc_attr($title) . '"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($title) . '" width="20" height="20" />thePlatform</a>';

	}

	
	
	/**
	 * Shortcode Callback
	 * @param array $atts Shortcode attributes
	 */
	function shortcode( $atts ) {
		if ( ! class_exists( 'ThePlatform_API' ) )
			require_once( dirname(__FILE__) . '/thePlatform-API.php' );
	
		extract(shortcode_atts(array(
			'width' => '',
			'height' => '',
			'media' => '',
			'player' => '',
			'mute' => '',
			'autoplay' => '',
			'loop' => '',
			'form' => ''
			), $atts
		));

		if ( empty($width) )
			$width = $GLOBALS['content_width'];
		if ( empty($width) )
			$width = 500;

		$width = (int) $width;

		if ( empty($height) )
			$height = $GLOBALS['content_height'];
		if ( empty($height) ) {
			$height = floor($width*9/16);
		}
		
		if ( empty($mute) ) {
			$mute = "false";
		}
		
		if ( empty($autoplay) ) {
			$autoplay = "false";
		}
		
		if ( empty($loop) ) {
			$loop = "false";
		}

		if ( empty($form) ) {
			$form = "iframe";
		}

		if ( empty( $media ) )
			return '<!--Syntax Error: Required Media parameter missing. -->';

		if ( empty( $player ) )
			return '<!--Syntax Error: Required Player parameter missing. -->';

		if ( !is_feed() ) {
			$preferences = get_option('theplatform_preferences_options');
			$accountPID = $preferences['mpx_account_pid'];
			$response = $this->get_embed_shortcode($accountPID, $media, $player, $width, $height, $loop, $autoplay, $mute, $form);
			
			if ( is_wp_error($response) ) {
				$output = $response->get_error_message();;
			} else {
				$output = $response;
			}
		} else {
			$output = '[Sorry. This video cannot be displayed in this feed. <a href="'.get_permalink().'">View your video here.]</a>';
		}

		return $output;
	}

	/**
	 * Called by the plugin shortcode callback function to construct a media embed iframe.
	 *
	 * @param string $account_id Account of the user embedding the media asset
	 * @param string $media_id Identifier of the media object to embed
	 * @param string $player_id Identifier of the player to display the embedded media asset in
	 * @param string $player_width The width of the embedded player
	 * @param string $player_height The height of the embedded player
	 * @param boolean $loop Whether or not to loop the embedded media automatically
	 * @param boolean $auto_play Whether or not to autoplay the embedded media asset on page load
	 * @param boolean $mute Whether or not to mute the audio channel of the embedded media asset
	 * @return string An iframe tag sourced from the selected media embed URL
	*/ 
	function get_embed_shortcode($accountPID, $releasePID, $playerPID, $player_width, $player_height, $loop = false, $autoplay = false, $mute = false, $form = "iframe") {

		if (!$this->preferences)
			$this->preferences = get_option($this->preferences_options_key);

		
		$type = $this->preferences['video_type'];		

		if (empty($type))
			$type = 'embed';
		
		
		$url = 'http://player.theplatform.com/p/' . urlencode($accountPID) . '/' . urlencode($playerPID);
		
		if ($type == 'embed') {
			$url .= '/embed';
		}
		$url .= '/select/' . urlencode($releasePID);
		
		$url .= '?width=' . (int)$player_width . '&height=' . (int)$player_height;
		
		if ( $loop != "false" ) {
			$url .= "&loop=true";
		}
		
		if ( $autoplay != "false" ) {
			$url .= "&autoPlay=true";
		}
		
		if ( $mute != "false" ) {
			$url .= "&mute=true";
		}
		
		if ($form == "script") {		
			return '<div style="width:' . (int)$player_width . 'px; height:' . (int)$player_height . 'px"><script type="text/javascript" src="' . esc_url($url . "&form=javascript") . '"></script></div>';
		}
		else { //Assume iframe			
			return '<iframe src="' . esc_url($url) . '" height=' . (int)$player_height . ' width=' . (int)$player_width . '></iframe>';
		}

	}

}

	

// Instantiate thePlatform plugin on WordPress init
add_action('init', array( 'ThePlatform_Plugin', 'init' ) );

function decode_json_from_server($input, $assoc, $die_on_error = TRUE) {


		$response = json_decode(wp_remote_retrieve_body($input), $assoc);		

		if (!$die_on_error)
			return $response;

		if (is_null($response) && wp_remote_retrieve_response_code($input) != "200") {						
			wp_die('<p>'.__('There was an error getting data from MPX, if the error persists please contact thePlatform.').'</p>');
		}

		if ( is_wp_error($response) ) {
			wp_die('<p>'.__('There was an error getting data from MPX, if the error persists please contact thePlatform. ' . $response->get_error_message()).'</p>');
				
		}

		if (array_key_exists('isException', $response)) {
			wp_die('<p>'.__('There was an error getting data from MPX, if the error persists please contact thePlatform. ' . $response['description']).'</p>');			
		}

		return $response;
		
}


