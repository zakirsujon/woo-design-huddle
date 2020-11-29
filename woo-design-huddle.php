<?php
/**
 * Plugin Name: Design Huddle for WooCommerce
 * Plugin URI: https://am2am.com/plugins/woo-design-huddle
 * Description: A plugin for WooCommerce to integrate Design Huddle Embedding Editor for product
 * Version: 1.3
 * Author: Zakir Hossen Sujon
 * Author URI: https://github.com/zakirsujon
 * Text Domain: woo-design-huddle
 * Domain Path: /languages
 * License: GPL2
 */

defined( 'ABSPATH' ) || exit;

// WooCommerce Detection
if ( ! function_exists( 'is_woocommerce_active' ) ) {
  function is_woocommerce_active() {
    $active_plugins = (array) get_option( 'active_plugins', [] );
    return in_array( 'woocommerce/woocommerce.php', $active_plugins );
  }
}

// Check if WooCommerce is active and the minimum requirements are satisfied.
if ( ! is_woocommerce_active() ) {
  add_action( 'admin_notices', function(){
    $message = '<strong>WooCommerce</strong> must to be activated first to use <strong>Design Huddle for WooCommerce</strong>.';
    printf( '<div class="error"><p>%s</p></div>', wp_kses_post( $message ) );
  } );
  return;
}

if ( ! defined( 'DH_WOO_EE_DIR' ) ) {
	define( 'DH_WOO_EE_DIR', plugin_dir_path( __FILE__ ) );
}



class Woo_DesignHuddle
{
	// Configuration
  private $name = 'Woo Design Huddle';
  private $prefix = 'dh_woo_ee';
  private $settings;


  private static $instance;

  public static function get_instance(){
    if (null === self::$instance) {
      self::$instance = new self();
    }

    return self::$instance;
  }


  private function __construct(){
    register_activation_hook( __FILE__, [$this, 'activate'] );

  	add_filter( 'plugin_action_links_' . dirname(__FILE__), [$this, 'plugin_action_links'] );

    $this->settings = get_option( $this->prefix .'_settings' );

    add_action( 'admin_menu', [$this, 'admin_menu'] );
    add_action( 'admin_init', [$this, 'settings'] );

    add_action( 'wp_enqueue_scripts', [$this, 'scripts'] );

    add_action( 'after_setup_theme', [$this, 'remove_single_product_options'], 99 );

    add_action( 'wp_logout', [$this, 'remove_user_data']);
    
    add_action( 'woocommerce_product_after_variable_attributes', [$this, 'variation_field'], 10, 3 );
    add_action( 'woocommerce_save_product_variation', [$this, 'save_variation_field'], 10, 2 );

    add_filter( 'wc_get_template', [$this, 'override_template'], 11, 2 );
    add_filter( 'woocommerce_available_variation', [$this, 'get_template_id'] );
    add_action( 'woocommerce_single_variation', [$this, 'show_editor_button'], 19 );

    add_action( 'wp_ajax_dh_user_token', [$this, 'get_user_access_token'] );
    add_action( 'wp_ajax_nopriv_dh_user_token', [$this, 'get_user_access_token'] );

    add_action( 'woocommerce_after_add_to_cart_button', [$this, 'input_project_info'] );
    add_action( 'wp_ajax_dh_get_thumbnail', [$this, 'get_project_thumbnail'] );
    add_action( 'wp_ajax_nopriv_dh_get_thumbnail', [$this, 'get_project_thumbnail'] );

    add_filter( 'woocommerce_get_item_data', [$this, 'show_edit_design'], 10, 2 );
    add_filter( 'woocommerce_add_cart_item_data', [$this, 'add_project_info'] );
    add_filter( 'woocommerce_cart_item_permalink', '__return_false' );
    add_filter( 'woocommerce_cart_item_thumbnail', [$this, 'show_project_image'], 10, 2 );
    add_filter( 'woocommerce_cart_item_name', [$this, 'checkout_show_image'], 10, 2 );

    add_action( 'woocommerce_checkout_create_order_line_item', [$this, 'save_project_id'], 10, 3 );
    add_action( 'woocommerce_checkout_order_processed', [$this, 'export_project_file'], 10, 3 );

    add_filter( 'woocommerce_display_item_meta', [$this, 'show_download_btn'], 10, 3 );
  }


  public function activate(){
    require DH_WOO_EE_DIR . '/includes/activate.php';
  }


  public function remove_user_data(){
    unset( $_COOKIE['dh_user_token'] );
    setcookie( 'dh_user_token', '', time() - ( 15 * 60 ) );
  }


  public function show_download_btn( $html, $item, $args ){
    $front = wc_get_order_item_meta($item->get_id(), '_dh_exported_file_front');
    $back = wc_get_order_item_meta($item->get_id(), '_dh_exported_file_back');

    if( $front || $back ){
      $upload_dir = wp_get_upload_dir();
      $rpep_path  = $upload_dir['baseurl'] . '/rpep-uploads/artFiles/';

      $html .= '<ul class="wc-item-meta">';
      $html .= $front ? '<li><b>Front Design:</b> <a download href="'. $rpep_path . $front .'">Download </a></li>' : '';
      $html .= $back ? '<li><b>Back Design:</b>  <a download href="'. $rpep_path . $back .'">Download</a></li>' : '';
      $html .= '</ul>';
    }

    return $html;
  }


  function save_project_id( $item, $cart_item_key, $values ){
    if( isset( $values['dh_project_id'] ) ) {
      $item->add_meta_data( '_dh_project_id', $values['dh_project_id'], true );
    }
  }


  public function checkout_show_image( $name, $cart_item ){
    if ( is_checkout() && !empty( $cart_item['dh_project_id'] ) ){
      $response = wp_remote_get(
        sprintf('%s/partners/api/projects/%s', $this->settings['store_url'], $cart_item['dh_project_id']),
        [
          'timeout' => 20,
          'headers' => [ 'Authorization' => 'Bearer '. $this->settings['token']['access_token'] ]
        ]
      );

      $thumbnail = json_decode( wp_remote_retrieve_body( $response ), true );
      $name = sprintf('<img src="%s">', $thumbnail['data']['thumbnail_url']) . $name;
    }
    return $name;
  }


  public function export_project_file( $order_id, $data, $order ){
    require DH_WOO_EE_DIR . '/includes/export.php';
  }


  public function show_project_image( $image, $cart_item ){
    if ( !empty( $cart_item['dh_project_id'] ) ){
      $response = wp_remote_get(
        sprintf('%s/partners/api/projects/%s', $this->settings['store_url'], $cart_item['dh_project_id']),
        [
          'timeout' => 20,
          'headers' => [ 'Authorization' => 'Bearer '. $this->settings['token']['access_token'] ]
        ]
      );

      $thumbnail = json_decode( wp_remote_retrieve_body( $response ), true );
      $image = sprintf('<img src="%s">', $thumbnail['data']['thumbnail_url']);
    }

    return $image;
  }


  public function show_edit_design( $item_data, $cart_item ){
    if ( !empty( $cart_item['dh_project_id'] ) ){
      $link = $cart_item['data']->get_permalink( $cart_item );

      $item_data['edit-design'] = [
        'key' => 'dh-edit-link',
        'display' => '<a href="#" data-project="' . wc_clean( $cart_item['dh_project_id'] ) .'" class="dh-project-cart">Edit Design</a>'
      ];
    }

    return $item_data;
  }


  public function add_project_info( $cart_item ) {
    $project_id = filter_input( INPUT_POST, 'dh_project_id' );
    $cart_item['dh_project_id'] = $project_id;

    $project_thumbnail = filter_input( INPUT_POST, 'dh_project_thumbnail' );
    $cart_item['dh_project_thumbnail'] = $project_thumbnail;
   
    return $cart_item;
  }


  public function get_project_thumbnail(){
    $project_id = $_POST['project_id'];

    $url = sprintf('%s/partners/api/projects/%s/?generate_latest_thumbnail=true', $this->settings['store_url'], $project_id);

    $response = wp_remote_get(
      $url,
      [
        'timeout' => 20,
        'headers' => [
          'Authorization' => 'Bearer '. $this->settings['token']['access_token']
        ]
      ]
    );

    echo wp_remote_retrieve_body( $response );

    wp_die();
  }


  public function input_project_info() {
    printf('<input type="hidden" name="dh_project_id" id="dh_project_id" />');
    printf('<input type="hidden" name="dh_project_thumbnail" id="dh_project_thumbnail" />');
  }


  public function override_template( $located, $template_name ) {    
    if ( 'single-product/add-to-cart/variation.php' == $template_name ) {
      $located = DH_WOO_EE_DIR . '/template/variation.php';
    }
    
    return $located;
  }

  public function plugin_action_links( $links ) {
		$action_links = [
			'settings' => '<a href="' . admin_url( 'options-general.php?page='. $this->prefix ) . '" aria-label="' . esc_attr__( 'View settings', 'woo-design-huddle' ) . '">' . esc_html__( 'Settings', 'woo-design-huddle' ) . '</a>',
		];

		return array_merge( $action_links, $links );
	}


  public function remove_single_product_options(){
    remove_theme_support( 'wc-product-gallery-zoom' );
    remove_theme_support( 'wc-product-gallery-lightbox' );
    // remove_theme_support( 'wc-product-gallery-slider' );
  }


  public function admin_menu(){
    add_options_page( $this->name, $this->name, 'manage_options', $this->prefix, [$this, 'options_page'] );
  }


  public function settings(){
    require DH_WOO_EE_DIR . '/includes/settings.php';
  }


  public function section(){
    printf('<p>%s</p>', __( 'Design Huddle Embedding Editor API credentials', 'woo-design-huddle' ) );
  }


  public function field( $args ){
  	$name = $this->prefix . '_settings['. $args['label_for'] .']';
  	$value = $this->settings[$args['label_for']] ?? '';
  	
  	printf('<input name="%s" type="text" id="%s" value="%s" class="regular-text">', $name, $args['label_for'], $value);
  }


  public function options_page(){
    echo '<div class="wrap">
    <form action="options.php" method="post">
      <h1>'. $this->name .'</h1>';

      settings_fields( $this->prefix );
      do_settings_sections( $this->prefix );
      submit_button();
      
    echo '</form></div>';
  }


  public function generate_access_token( $settings ){
  	$response = wp_remote_post(
      $settings['store_url'] .'/oauth/token',
      [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => [
        	'client_id' => $settings['client_id'],
        	'client_secret' => $settings['client_secret'],
        	'grant_type' => 'client_credentials'
        ]
      ]
    );

    if( is_wp_error( $response ) ){
      printf('<p style="word-break:break-all"><b>Error :</b><br>%s</p><br>', $name, wp_remote_retrieve_body( $response ));
    } else {
      $tokens = json_decode( wp_remote_retrieve_body( $response ), true );

      unset($tokens['token_type']);
      $tokens['expires_in'] = date('F j, Y, H:i', time()+$tokens['expires_in']);
		}

		// Save 'access_token' in database to reuse
		$settings['token'] = $tokens;
    $this->settings = $settings;

    update_option( $this->prefix .'_settings', $settings );

		return $tokens;
  }


  public function access_token(){
  	// Quit if API credentials are not present
  	if( empty($this->settings['client_id']) && empty($this->settings['client_id']) ){
  		printf('<p><h4>%s</h4></p><br>', __( 'No Token found! You need to put API info first.', 'woo-design-huddle' ) );
  		return;
  	}

  	
  	// Get 'access_token' either from database or generate
    if( isset($this->settings['token']) ){
    	$tokens = $this->settings['token'];
    	$now = date('F j, Y, H:i', time());
	  	if( $tokens['expires_in'] < $now ){
	  		$tokens = $this->generate_access_token( $this->settings );
	  	}
    } else {
    	$tokens = $this->generate_access_token( $this->settings );
    }


    if( $tokens ){
      foreach ($tokens as $name => $code) {
        printf('<p style="word-break:break-all"><b>%s :</b><br>%s</p><br>', $name, $code);
      }
      $datediff = strtotime($tokens['expires_in']) - time();
      printf('<p><b>life_time :</b><br>%s days</p><br>', round($datediff / (60 * 60 * 24)) );
    } else {
      printf('<p><h4>%s</h4></p><br>', __( 'No Token found! You need to authorize first.', 'woo-design-huddle' ) );
    }

  }


  public function variation_field( $loop, $data, $variation ){
  	$response = wp_remote_get(
      $this->settings['store_url'] .'/partners/api/templates',
      [
        'headers' => [
          'Authorization' => 'Bearer '. $this->settings['token']['access_token']
        ]
      ]
    );

    $temp = json_decode( wp_remote_retrieve_body( $response ), true );

    if( isset($temp['data']['items']) ){
    	$options = ['&nbsp;' => ['None']];

	    foreach( $temp['data']['items'] as $i ){
	    	$options[$i['primary_template_category_item']['item_name']][$i['template_id']] = $i['template_title'];
	    }

      $value = get_post_meta( $variation->ID, 'variable_dh_template', true );

      printf('<p class="form-row form-row-first form-field variable_dh_template%1$s_field">
        <label for="variable_dh_template%1$s">Design Huddle Template</label>
        <select id="variable_dh_template%1$s" name="variable_dh_template[%1$s]" class="select short">', $loop);

      foreach( $options as $group => $item ){
        printf('<optgroup label="%s">', esc_attr($group));
        foreach( $item as $id => $name ){
          printf('<option value="%s" %s>%s</option>', esc_attr($id), selected($value, $id, false), esc_attr($name));
        }
      }

      echo '</select></p>';
	  }
  }


  public function save_variation_field( $id, $loop ) {
    update_post_meta( $id, 'variable_dh_template', esc_attr( $_POST['variable_dh_template'][$loop] ) );
	}


  public function get_template_id( $variations ) {
    $variations['variable_dh_template'] = get_post_meta( $variations[ 'variation_id' ], 'variable_dh_template', true );
    return $variations;
  }


  public function get_user_access_token(){
    $guest_id = isset($_POST['guest_id']) ? $_POST['guest_id'] : '';
    $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : '';

    if( $user_id ){
      $guest_code = $guest_id;
    } else {
      $user_type  = '/guest';
      $user_id = $guest_id;
      $guest_code = '';
    }
    
    echo $this->get_access_token( $user_id, $guest_code, $user_type );

    wp_die();
  }



  public function get_access_token( $user_id, $guest_code, $user_type = '' ){
    $url = $this->settings['store_url'] .'/oauth/token'. $user_type;
    $body = [
      'client_id' => $this->settings['client_id'],
      'client_secret' => $this->settings['client_secret'],
      'grant_type' => 'password',
      'username' => $user_id
    ];

    if( empty($user_type) ){
      $body['guest_code'] = $guest_code;
    }

    // error_log("\n\nurl : ". print_r($url, true) ."\n\nbody : ". print_r(http_build_query($body), true));

    $token = wp_remote_post( $url,
      [
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => $body
      ]
    );

    // error_log("\n\noutput : ". print_r(wp_remote_retrieve_body( $token ), true));

    return wp_remote_retrieve_body( $token );
  }


  public function generate_ajax_obj(){
    $obj = [
      'ajax_url' => admin_url( 'admin-ajax.php' ),
      'store_url' => $this->settings['store_url'],
      'store_domain' => basename($this->settings['store_url']),
    ];

    if( isset($_COOKIE['dh_user_token']) ){
      $obj['user_token'] = $_COOKIE['dh_user_token'];
    }

    if( $user_id = get_current_user_id() ){
      $obj['guest_id'] = isset($_COOKIE['dh_guest_id']) ? $_COOKIE['dh_guest_id'] : '';

      if( $meta = get_user_meta( $user_id, 'dh_authentication', true ) ){
        $new_token = false;

        $obj['user_id'] = $meta['user_id'];
        $obj['user_token'] = $meta['token'];

        if( $meta['expire'] < time() || $meta['guest_id'] != $obj['guest_id'] ){
          $new_token = true;
        }
      } else {
        $obj['user_id'] = 'user_'. $user_id .'_'. time().rand(100,999);
        $new_token = true;
      }

      if( $new_token ){
        $response = $this->get_access_token( $obj['user_id'], $obj['guest_id'] );
        $tokens = json_decode( $response, true );

        if( isset($tokens['access_token']) ){
          $expire = time() + $tokens['expires_in'];
          $obj['user_token'] = $tokens['access_token'];

          update_user_meta( $user_id, 'dh_authentication', [
            'user_id' => $obj['user_id'],
            'token' => $obj['user_token'],
            'expire' => $expire,
            'guest_id' => $obj['guest_id']
          ] );
        } else {
          error_log(print_r($response, true));
        }
      }

    } else {
      $obj['guest_id'] = isset($_COOKIE['dh_guest_id']) ? $_COOKIE['dh_guest_id'] : 'guest_'.time().rand(100,999);
    }

    return $obj;
  }


  public function scripts(){
    wp_enqueue_style( 'dh-woo-ee-style', plugin_dir_url( __FILE__ ) . 'assets/css/embeded-editor.css' );
    wp_enqueue_script( 'dh-woo-ee-lib', 'https://cdn.designhuddle.com/editor/v1/lib.js', [], null, true );
    wp_register_script( 'dh-woo-ee-script', plugin_dir_url( __FILE__ ) . 'assets/js/embeded-editor.js', ['jquery'], null, true );

    $obj = $this->generate_ajax_obj();
    wp_localize_script( 'dh-woo-ee-script', 'dh_woo_ee_object', $obj );
    wp_enqueue_script( 'dh-woo-ee-script' );
  }


  public function show_editor_button(){
    echo '<div class="dh-editor-btn-wrap clearfix">
      <p><a href="#" class="button" id="dh-project-btn">Customize This Design</a></p>
    </div>';
  }


}

$woo_designhuddle = Woo_DesignHuddle::get_instance();
