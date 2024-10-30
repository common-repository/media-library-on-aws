<?php
/**
 * Plugin Name: Viocee_AWS_Media_Library
 * Plugin URI: https://www.viocee.com/
 * Description: This plugin will silently host your WordPress media library on AWS (Amazon Web Services) S3(Amazon Simple Storage Service). If with the premium version of this plugin installed, the media library of WordPress will be enhenced with upload of large files as well as batch addition of files from user specified server location. Your site must runs on Apache server with mod_rewrite enabled, while you must have an account with AWS, check your condition to see if you could install the plugin, or just discard it.
 * Version: 1.0.1
 * Author: Kevin Cheng
 * License: GPLv2 or later
 */

namespace VAML_Viocee_AWS_Media_Library;

// If this file is called directly, abort.
if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

if (!is_blog_installed()) {
	return;
}

require_once( __DIR__ . '/inc/registry.php' );

class Controller {
	protected static $instance;
	private static $registry;
	
	public static function get_instance() {
		if (null === self::$instance) {
			self::$registry = new Registry();
			self::set('vaml_version', '1.0.0');
			self::set('vaml_dir', plugin_dir_path( __FILE__ ));
			self::set('vaml_url', plugins_url('', __FILE__   ));
			self::set('vaml_basename', plugin_basename( __FILE__  ));
			self::set('vaml_hidden', 'vaml_hidden');
			self::set('vaml_nonce_name', '_viocee_aws_media_library_nonce');
			// min 5m of multipart upload by aws
			self::set('vaml_multipart_min_size', 5242880);
			self::set('vaml_wp_marker', '_vaml_viocee_aws_media_library');
			if(file_exists( __DIR__ . '/premium/multi.php')){
				self::set('vaml_version_codename', 'premium');
			} else {
				self::set('vaml_version_codename', 'free');
				self::set('vaml_link_to_premium', 'https://www.viocee.com/?download=viocee_aws_media_library&codename=premium');
			}
			self::set('viocee_instant_cloud_space', 'https://www.viocee.com/?download=viocee_instant_cloud_space');
			self::set_locale();
			// fatal message after set_locale()
			if(class_exists('\VICS_Viocee_Instant_Cloud_Space\Controller')){
				die(__( 'Viocee AWS Media Library is in conflict with Viocee Instant Cloud Space previously installed. Only one of them ( the one previously installed ) could be used currently.', 'vaml-text-lang'));
			}
			self::name_tables();
			self::$instance = new self();
			self::bootstrap();
		}
	}
	
	private static function bootstrap() {
		if($api_essentials = get_option( 'vaml_viocee_aws_media_library_essentials' )){
			if(isset($api_essentials['tmp'])){
				$tmp = $api_essentials['tmp'];
				if('' == $tmp){
					$upload_dir = wp_get_upload_dir();
					$tmp = $upload_dir['basedir'];
				}
				$tmp .= '/' . Controller::get('vaml_hidden');
				// create $tmp . '/mutex' for permission check
				if (!file_exists ($tmp . '/mutex') && wp_mkdir_p($tmp . '/mutex')){
					@chmod($tmp . '/mutex', 0755);
				}
				if (file_exists ($tmp . '/mutex')){
					if (is_readable($tmp . '/mutex') && is_writable($tmp . '/mutex')) {
						self::set('vaml_tmp', $tmp);
				        } else {
					        die(sprintf(__( 'Viocee AWS Media Library does\'t have write permissions into %s!', 'vaml-text-lang'), dirname($tmp)));
				        }
				} else {
					die(sprintf(__( 'Viocee AWS Media Library can\'t create mutex folder %s!', 'vaml-text-lang'), $tmp));
				}
			}
	        }
		if( 'premium' == self::get('vaml_version_codename')){
			require_once( __DIR__ . '/premium/multi.php');
			Multi::get_instance();
			require_once( __DIR__ . '/premium/batch.php');
			Batch::get_instance();
		}
		require_once( __DIR__ . '/inc/utils.php');
		Utils::get_instance();
		if(self::get('vaml_tmp')){
		        require_once( __DIR__ . '/inc/mutex.php');
		        Mutex::get_instance(self::get('vaml_tmp') . '/mutex');
		}
		require_once( __DIR__ . '/inc/api.php');
		Api::get_instance();
		require_once( __DIR__ . '/inc/attachment.php');
		Attachment::get_instance();
		require_once( __DIR__ . '/inc/admin.php');
		Admin::get_instance();
		require_once( __DIR__ . '/inc/rest.php');
		Rest::get_instance();
	}
	
	public static function activate() {
		// action plugins_loaded not yet called
		if (current_user_can('activate_plugins' ) && !class_exists('\VICS_Viocee_Instant_Cloud_Space\Controller')){
			global $wpdb;
			// this function was introduced in WordPress 3.5
			$charset_collate = $wpdb->get_charset_collate();
			$table_name = 'vaml_aws_files';
			$tbl = $wpdb->get_row("SELECT 1 AS check FROM $table_name LIMIT 1");
			$sql = array();
			if(is_null($tbl)){
				$sql[] = "CREATE TABLE " . $table_name . " (
				        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			                `file_index` smallint(6) unsigned NOT NULL DEFAULT '0',
			                `file_path` varchar(255) NOT NULL,
					`post_id` bigint(20) NOT NULL,
			                `backup` tinyint(4) NOT NULL,
			                `status` tinyint(4) NOT NULL,
			                PRIMARY KEY (`id`),
			                KEY `file_index` (`file_index`) USING BTREE
			                ) ENGINE=InnoDB " . $charset_collate;
		        }
			if(!empty($sql)){
			        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			        foreach($sql as $s){
				        dbDelta($s);
			        }
		        }
		}
	}
	
	public static function deactivate() {
		if (current_user_can('activate_plugins' )){
			// action plugins_loaded already called
			$schedules_update = array();
			$schedules_update['aws_orphaned'] = array(
				'val'  =>  1
			);
			$schedules_update['auto_upload'] = null;
			$schedules_update['auto_upload_start_id'] = null;
			//ommit the previous recovery process;
			$schedules_update['recovery_token'] = null;
			$schedules_update['count_s3_media'] = null;
			$schedules_update['cancel_recovery'] = array(
				'val'  =>  1
			);
			Api::safe_update_schedules($schedules_update);
		}
	}
	
	public static function uninstall() {
		if (current_user_can('activate_plugins' )){
			wp_clear_scheduled_hook( 'vaml_viocee_aws_media_library_schedule_actions');
			// Delete Options
			$options = array(
				'vaml_viocee_aws_media_library_essentials',
				'vaml_viocee_aws_media_library_config',
				'vaml_viocee_aws_media_library_settings',
				'vaml_viocee_aws_media_library_schedules'
			);
			foreach ( $options as $option ) {
				delete_option( $option );
			}
			Utils::update_rewrite_rule();
			if(self::get('vaml_tmp')){
				Utils::rrmdir(self::get('vaml_tmp'));
			}
			global $wpdb;
			$wpdb->query("DROP TABLE IF EXISTS vaml_aws_files");
			$wpdb->query($wpdb->prepare( "DELETE FROM " . $wpdb->postmeta . " WHERE meta_key = %s", self::get('vaml_wp_marker')));
		}
	}
	
	private static function set_locale() {
		// load the language file(s)
		$lang = dirname( self::get('vaml_basename') ) . '/lang';
		load_plugin_textdomain( 'vaml-text-lang', false, $lang );
		if( 'premium' == self::get('vaml_version_codename')){
			$lang = dirname( self::get('vaml_basename') ) . '/premium/lang';
			load_plugin_textdomain( 'vaml-text-premium', false, $lang );
		}
	}
	
	// return reference of global $wpdb by '&set_db'
	private static function &name_tables() {
		global $wpdb;
		$wpdb->vaml_aws_files = 'vaml_aws_files';
		$wpdb->tables[] = $wpdb->vaml_aws_files;
		return $wpdb;
	}
	
	// some libraries are exclusive to some plugins, so this function is useful.
        public static function set($key, $value) {
                if (null === self::$registry) {
	                return;
	        }
	        self::$registry->set($key, $value);
        }
	
	public static function get($key ) {
                if (null !== self::$registry) {
	                return self::$registry->get($key);
                }
                return null;
        }
	
	public static function render($action_render = array()) {
		$html = '';
		$child = true;
		if (null !== self::$instance) {
			if(empty($action_render)){
				$child = false;
				$action_render = self::get('action_render');
			}
			if($action_render && isset($action_render['template']) && file_exists($action_render['template'])){
		                if(isset($action_render['data'])){
		                        extract($action_render['data']);
		                }
				ob_start();
				// The do_action function calls surrounding the require are optional but show a handy way to give other developers a chance to
			        // add further customizations before and after the template is rendered.
		                if(isset($action_render['before'])){
		                        do_action( $action_render['before'] );
		                }
				require ( $action_render['template'] );
				if(isset($action_render['after'])){
		                        do_action( $action_render['after'] );
		                }
				$html = ob_get_contents();
		                ob_end_clean();
			}
                }
		if ($child){
			return $html;
		} else {
			echo $html;
		}
        }
	
	/**
	 * Protected constructor to prevent creating a new instance of the
	 * *Singleton* via the `new` operator from outside of this class.
         */
	protected function __construct(){
	}
	
	/**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        private function __clone(){
        }

        /**
         * Private unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        private function __wakeup(){
        }
}
add_action( 'plugins_loaded', array( '\VAML_Viocee_AWS_Media_Library\Controller', 'get_instance' ));
register_activation_hook( __FILE__, array('\VAML_Viocee_AWS_Media_Library\Controller', 'activate'));
register_deactivation_hook( __FILE__, array('\VAML_Viocee_AWS_Media_Library\Controller', 'deactivate'));
register_uninstall_hook(__FILE__, array('\VAML_Viocee_AWS_Media_Library\Controller', 'uninstall'));
?>