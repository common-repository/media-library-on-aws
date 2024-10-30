<?php
namespace VAML_Viocee_AWS_Media_Library;

class Admin {
	protected static $instance;
	private static $display_modes;
	private static $text_display_mode;
	private static $page_slug;
	
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
			if (is_admin() ){
				// add plugin's admin menu by use's capabilities, use's capabilities will be checked in the hook function
				add_action( 'admin_menu', array( self::$instance, 'plugin_menu' ));
				
				// if use who can activate plugins change plugins settings, admin notices might be invoked by his operation.
			        if (current_user_can('activate_plugins' )){
				        add_action( 'admin_notices', array( self::$instance, 'recovery_notice') );
			        }
			}
		}
		return self::$instance;
	}
	
	// when the user try to recovery his media files from s3 to his original disk space before he uninstall this plugin
	// admin notice tells how many media file(s) in recovery process until all done with a success notice;
	public static function recovery_notice() {
		if(Api::$library_settings && isset(Api::$schedule_actions['count_s3_media']) ){
			if(Api::$schedule_actions['count_s3_media']){
				$class = 'notice notice-warning';
				$message = sprintf(__( 'Currently %s media file(s) in recovery process from AWS S3 to your own disk space. Do not delete Viocee AWS Media Library until all done!', 'vaml-text-lang'), Api::$schedule_actions['count_s3_media']);
			} else {
				$class = 'notice notice-success';
				$message = __( 'The recovery of your media files from AWS to your own disk is complete! Viocee AWS Media Library can be uninstalled safely now.', 'vaml-text-lang');
			}
			$screen = get_current_screen();
			// global notice displays on other pages instead of plugin' setting page can be turned off.
			if ('settings_page_vaml_viocee_aws_media_library_conf' != $screen->id ){
				if(Utils::verify_nonce('vaml_notice_silent', false)) {
					if(!isset(Api::$library_settings['silent_notice']) || !Api::$library_settings['silent_notice']){
						try{
							$locker = Mutex::blocker('vaml_viocee_aws_media_library_settings', 60);
							Api::$library_settings['silent_notice'] = 1;
							update_option( 'vaml_viocee_aws_media_library_settings', Api::$library_settings );
						        if(Mutex::release_blocker($locker)){
							        Api::trash();
						        }
					        } catch(\Exception $e) {
						
					        }
					} 
				}
				if(isset(Api::$library_settings['silent_notice']) && Api::$library_settings['silent_notice']){
					$message = '';
				} else {
					$class .= ' is-dismissible';
				        $nonce_url = wp_nonce_url( Utils::full_url(), 'vaml_notice_silent', Controller::get('vaml_nonce_name'));
				        $message .= '&nbsp;&nbsp;[ <a href="' . esc_url($nonce_url) . '">' . __('No more annoying', 'vaml-text-lang') . '</a> ]';
				}
			}
			if($message){
				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message );
			}
		}
	}
	
	public static function load_setting_page() {
		$scheme = is_ssl()? 'https' : 'http';
		wp_enqueue_style('vaml-viocee-aws-media-library-admin-style', Controller::get('vaml_url') . '/static/viocee_admin_theme.css', array(), Controller::get('vaml_version'), 'all');
		// aws credentials can be updated
		// but as for s3 bucket, or bucket prefix, the are fixed for ensuring consistent mod rewriting
		$aws_credentials = false;
		if (Api::$api_essentials && isset($_GET['vaml_viocee_aws_media_library_update']) && 'aws_credentials'==$_GET['vaml_viocee_aws_media_library_update']) {
			// update aws credentials
			$aws_credentials = true;
		}
		$aws_config = Api::aws_configured();
		if (!$aws_credentials && $aws_config){
			$vaml_nonce_action = 'viocee_aws_media_library_setting';
			$error_code = 0;
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
				Utils::verify_nonce($vaml_nonce_action);
				$recovery = false;
				if(isset($_POST['recovery_token']) && isset($_POST['recovery_token_value'])){
					$recovery_token = sanitize_text_field($_POST['recovery_token']);
					$recovery_token_value = sanitize_text_field($_POST['recovery_token_value']);
					if($recovery_token == $recovery_token_value){
						$recovery = true;
					}
				}
				if($recovery){
					$schedules_update = array();
					$schedules_update['recovery_token'] = array(
						'val'  =>  $recovery_token
					);
					$schedules_update['count_s3_media'] = array(
						'val'  =>  Api::count_s3_media()
					);
					$schedules_update['cancel_recovery'] = null;
					Api::safe_update_schedules($schedules_update);
				} else {
					if(isset($_POST['batch_upload_location'])){
						$upload_dir = wp_get_upload_dir();
						$batch_upload_location = Utils::mb_trim($_POST['batch_upload_location']);
						if('' == $batch_upload_location){
							$batch_upload_location = $upload_dir['basedir'] . '/.viocee-temp-batch';
						}
						$batch_upload_location = Utils::sanitize_path($batch_upload_location);
						
						if(0 !== ($pos = strpos($batch_upload_location, $upload_dir['basedir']))){
							if(!is_dir($batch_upload_location) ){
								$error_code = 1;
						        } else {
							        if(!is_readable($batch_upload_location) || !is_writable($batch_upload_location)){
								        $error_code = 2;
							        }
						        }
						}
						if(!$error_code){
							Api::$library_settings['batch_upload_location'] = $batch_upload_location;
						}
					}
					if(!$error_code && Utils::update_rewrite_rule($aws_config['region'], $aws_config['s3_bucket'], $aws_config['s3_bucket_prefix'])){
						try{
							$locker = Mutex::blocker('vaml_viocee_aws_media_library_settings', 60);
							if(isset($_POST['backup_upload'])){
								Api::$library_settings['backup_upload'] = 1;
						        } else {
							        Api::$library_settings['backup_upload'] = 0;
						        }
							update_option( 'vaml_viocee_aws_media_library_settings', Api::$library_settings );
							$schedules_update = array();
						        if(isset($_POST['auto_upload'])){
						                $schedules_update['auto_upload'] = array(
							                'val'  =>  1
						                );
						                $schedules_update['auto_upload_start_id'] = array(
							                'val'    =>  0,
							                'ignore' => true
						                );
						        } else {
						                $schedules_update['auto_upload'] = null;
						                $schedules_update['auto_upload_start_id'] = null;
						        }
					                Api::update_schedules($schedules_update);
							if(Mutex::release_blocker($locker)){
								Api::trash();
							}
						} catch(\Exception $e) {
						
						}
					}
				}
				if(!$error_code){
					if(!$recovery){
						$expired = time() + 10;
						$tm_token = $expired . '_' . sha1($expired . Api::$api_essentials['api_signature'][0]);
						$current_page_url = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_success'=>$tm_token, 'vaml_update'=>'settings'), 'wp-admin/options-general.php' ), $scheme );
					} else {
						$current_page_url = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf'), 'wp-admin/options-general.php' ), $scheme );
					}
					wp_safe_redirect(esc_url_raw($current_page_url));
					exit;
				}
			} else {
				if (isset(Api::$schedule_actions['recovery_token'])) {
					$valid = false;
					$schedules_update = array();
					if(isset($_GET['recovery_cancel']) && Utils::verify_nonce('vaml_recovery_cancel', false)){
						$parts = explode('_', $_GET['recovery_cancel']);
					        if($parts[1] == sha1($parts[0] . Api::$schedule_actions['recovery_token'] . Api::$api_essentials['api_signature'][0]) || (isset(Api::$api_essentials['api_signature'][1]) && $parts[1] == sha1($parts[0] . Api::$schedule_actions['recovery_token'] . Api::$api_essentials['api_signature'][1]))){
						        $schedules_update['recovery_token'] = null;
							$schedules_update['count_s3_media'] = null;
							$schedules_update['cancel_recovery'] = array(
								'val'  =>  1
							);
							$current_page_url = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf'), 'wp-admin/options-general.php' ), $scheme );
						        $valid = true;
					        }
					} else {
						if(isset($_GET['global_notice']) && Utils::verify_nonce('vaml_global_notice', false)){
							$parts = explode('_', $_GET['global_notice']);
					                if($parts[1] == sha1($parts[0] . Api::$api_essentials['api_signature'][0]) || (isset(Api::$api_essentials['api_signature'][1]) && $parts[1] == sha1($parts[0] . Api::$api_essentials['api_signature'][1]))){
							        $valid = true;
						        }
					        }
					}
					if($valid){
						try{
							$locker = Mutex::blocker('vaml_viocee_aws_media_library_settings', 60);
							if($schedules_update){
								Api::update_schedules($schedules_update);
							}
							if (isset(Api::$library_settings['silent_notice']) && Api::$library_settings['silent_notice']){
							        unset(Api::$library_settings['silent_notice']);
							        update_option( 'vaml_viocee_aws_media_library_settings', Api::$library_settings );
							}
						        if(Mutex::release_blocker($locker)){
							        Api::trash();
						        }
					        } catch(\Exception $e) {
						
						}
						if(isset($current_page_url)){
							wp_safe_redirect(esc_url_raw($current_page_url));
							exit();
						}
					}
				}
			}
			$data = self::vaml_account_review($aws_config, $error_code);
		} else {
			if (!$aws_credentials){
				$vaml_nonce_action = 'viocee_aws_media_library_config';
			} else {
				$vaml_nonce_action = 'viocee_aws_media_library_credentials';
			}
			$error_code = 'None';
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
				Utils::verify_nonce($vaml_nonce_action);
				if(!extension_loaded('xml')){
					$error_code = 'XMLNotFound';
				}
				// do not sanitize_text_field, let AWS api validate these data or otherwise, validation might fail as aws credentials cannot be a result of wp sanitize_text_field in all means;
				// if validated by AWS api, these data are definitely safe. 
				$aws_access_key_id = Utils::mb_trim($_POST['aws_access_key_id']);
				$aws_secret_access_key = Utils::mb_trim($_POST['aws_secret_access_key']);
				
				if( '' == $aws_access_key_id || '' == $aws_secret_access_key) {
					$error_code = 'EmptyRequirements';
				}
				
				if('None' == $error_code){
					if(!$aws_credentials){
					        Api::add_signature();
						$region = Utils::mb_trim($_POST['region']);
						$s3_bucket = Utils::mb_trim($_POST['s3_bucket']);
						$s3_bucket_prefix = Utils::mb_trim($_POST['s3_bucket_prefix']);
						if( '' ==  $s3_bucket || '' == $s3_bucket_prefix) {
							$error_code = 'EmptyRequirements';
						} else {
							if(!preg_match('/^[a-z0-9]*(?:(_|-)[a-z0-9]+)*$/', $s3_bucket_prefix) || strlen($s3_bucket_prefix) > 64 || strlen($s3_bucket_prefix) < 3){
					                        $error_code = 'InvalidPrefix';
				                        }
						}
				        } else {
					        $region = $aws_config['region'];
					        $s3_bucket = $aws_config['s3_bucket'];
					        $s3_bucket_prefix = $aws_config['s3_bucket_prefix'];
				        }
				}
				if('None' == $error_code){
					// let aws api validate data submitted by the user
					$result = Api::aws_verify($aws_access_key_id, $aws_secret_access_key, $region, $s3_bucket, $s3_bucket_prefix);
					if(is_array($result) && isset($result['error_code'])){
						$error_code = $result['error_code'];
						if('None' == $error_code){
							if(!$aws_credentials){
								if(Utils::update_rewrite_rule($region, $s3_bucket, $s3_bucket_prefix)){
									Api::$api_essentials['credentials']['default'] = array('key' => $aws_access_key_id, 'secret' => $aws_secret_access_key);
							                Api::$api_essentials['tmp'] = self::vaml_tmp();
							                update_option( 'vaml_viocee_aws_media_library_essentials', Api::$api_essentials );
							                $s3_config = array();
							                $s3_config['region'] = $region;
							                $s3_config['s3_bucket'] = $s3_bucket;
							                $s3_config['s3_bucket_prefix'] = $s3_bucket_prefix;
							                update_option( 'vaml_viocee_aws_media_library_config', $s3_config );
							        }
							} else {
								Api::$api_essentials['credentials']['default'] = array('key' => $aws_access_key_id, 'secret' => $aws_secret_access_key);
								update_option( 'vaml_viocee_aws_media_library_essentials', Api::$api_essentials );
							}
							$expired = time() + 10;
							$tm_token = $expired . '_' . sha1($expired . Api::$api_essentials['api_signature'][0]);
							if(!$aws_credentials){
								$current_page_url = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_success'=>$tm_token, 'vaml_update'=>'config'), 'wp-admin/options-general.php' ), $scheme );
							} else {
								$current_page_url = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_success'=>$tm_token, 'vaml_update'=>'credentials'), 'wp-admin/options-general.php' ), $scheme );
							}
							wp_safe_redirect(esc_url_raw($current_page_url));
							exit;
						}
					} else {
						// unknown err
						$error_code = 'UnknownError';
					}
				}
			}
			$data = self::aws_config($error_code, $aws_credentials);
		}
		$data['vaml_nonce_action'] = $vaml_nonce_action;
		$data['vaml_nonce_name'] = Controller::get('vaml_nonce_name');
		Controller::set('tpl_variables', $data);
	}
	
	private static function vaml_tmp() {
		$tmp = '/var/tmp';
		if(file_exists ($tmp) && is_writable( $tmp ) ){
			return 	$tmp;
		}
		if(file_exists (dirname($tmp)) && is_writable( dirname($tmp) ) && !file_exists($tmp) && wp_mkdir_p($tmp)){
			@chmod($tmp, 0755);
			return 	$tmp;
		}
		return '';
	}
	
	public static function set_display_modes($display_modes) {
		self::$display_modes = $display_modes;
	}
	
	public static function set_text_display_mode($text_display_mode) {
		self::$text_display_mode = $text_display_mode;
	}
	
	public static function plugin_menu() {
		if (current_user_can('upload_files') ){
			if(Api::$api_essentials){
				$title = __('Viocee Upload', 'vaml-text-lang');
		        } else {
			        $title = __('Access Denied', 'vaml-text-lang');
		        }
		        
			$viocee_upload = add_submenu_page(
			        'upload.php',
			        $title,
			        __('Viocee Upload', 'vaml-text-lang'),
			        'manage_options',
			        'vaml_viocee_aws_media_library_upload',
			        array( self::$instance, 'viocee_upload' )
		        );
		        
			add_action('load-' . $viocee_upload, array( self::$instance, 'load_viocee_upload'));
		        
			if(Api::$api_essentials){
			        $title = __('Viocee Batch', 'vaml-text-lang');
		        } else {
			        $title = __('Access Denied', 'vaml-text-lang');
		        }
		        
			$viocee_batch = add_submenu_page(
			        'upload.php',
			        $title,
			        __('Viocee Batch', 'vaml-text-lang'),
			        'manage_options',
			        'vaml_viocee_aws_media_library_batch',
			        array( self::$instance, 'viocee_batch' )
		        );
			add_action('load-' . $viocee_batch, array( self::$instance, 'load_viocee_batch'));
		}
		
		// plugin setting is exclusive to users who can activate plugins
		if (current_user_can('activate_plugins' )){
			$settings = add_options_page(
				__( 'Viocee AWS Media Library', 'vaml-text-lang'),
			        __( 'Viocee AWS Media Library', 'vaml-text-lang'),
			        'manage_options',
			        'vaml_viocee_aws_media_library_conf',
			        array( self::get_instance(), 'vaml_setting')
		        );
		        add_action('load-'.$settings, array( self::$instance, 'load_setting_page'));
		}
	}
	
	private static function vaml_account_review($aws_config, $error_code = 0) {
		$child = array();
		$child['template'] = Controller::get('vaml_dir') . 'static/help_user.tpl';
		$child['data'] = $aws_config;
		$upload_dir = wp_get_upload_dir();
		$basedir = $upload_dir['basedir'];
		$child['data']['uploads'] = trim(str_replace(ABSPATH, '', $basedir), '/');
		$child['data']['uploads'] = ($child['data']['uploads'] ? $child['data']['uploads'] : '/');
		get_current_screen()->add_help_tab( array(
			'id'		=> 'vaml_viocee_aws_media_library_user',
		        'title'		=> __( 'User& Permissions', 'vaml-text-lang' ),
		        'content'	=> Controller::render($child)
		));
		$child['template'] = Controller::get('vaml_dir') . 'static/help_permissions.tpl';
		get_current_screen()->add_help_tab( array(
			'id'		=> 'vaml_viocee_aws_media_library_code',
		        'title'		=> __( 'Permissions Code', 'vaml-text-lang' ),
		        'content'	=> Controller::render($child)
		));
		$child['template'] = Controller::get('vaml_dir') . 'static/help_rewrite.tpl';
		get_current_screen()->add_help_tab( array(
			'id'		=> 'vaml_viocee_aws_media_library_rewrite',
		        'title'		=> __( 'Rewrite Rules', 'vaml-text-lang' ),
		        'content'	=> Controller::render($child)
		));
		$data['recovery'] = false;
		$data['essential_updated'] = '';
		$data['notice_error'] = '';
		$data['s3_configured'] = __( 'Viocee AWS Media Library can\'t be reconfigured for AWS S3 settings. If you want to know the current configuration, Please click the help tab.', 'vaml-text-lang');
		if(Api::$library_settings && isset(Api::$schedule_actions['recovery_token'])){
			$data['recovery'] = true;
			$data['recovery_stop'] = __( 'Stop recovery', 'vaml-text-lang');
			$scheme = 'http';
			if (is_ssl()) {
				$scheme = 'https';
			}
			$sig = md5(wp_generate_password(32, true, true));
			$cancel_token = $sig . '_' . sha1($sig . Api::$schedule_actions['recovery_token']. Api::$api_essentials['api_signature'][0]);
			$data['recovery_cancel_url'] = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'recovery_cancel' => $cancel_token), 'wp-admin/options-general.php' ), $scheme );
			$data['recovery_cancel_url'] = wp_nonce_url($data['recovery_cancel_url'], 'vaml_recovery_cancel', Controller::get('vaml_nonce_name') );
			$data['global_notice'] = '';
			if(isset(Api::$library_settings['silent_notice']) && Api::$library_settings['silent_notice']){
				$data['global_notice'] = __( 'Global notice', 'vaml-text-lang');
				$global_notice_token = $sig . '_' . sha1($sig . Api::$api_essentials['api_signature'][0]);
				$data['global_notice_url'] = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'global_notice' => $global_notice_token), 'wp-admin/options-general.php' ), $scheme );
				$data['global_notice_url'] = wp_nonce_url($data['global_notice_url'], 'vaml_global_notice', Controller::get('vaml_nonce_name') );
			}
		} else {
			$data['action_url'] = add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf'), 'options-general.php' );
			$data['default_settings'] = __( 'Default settings', 'vaml-text-lang');
		        $data['backup_upload'] = __( 'Keep a copy of media file on server when uploaded', 'vaml-text-lang');
		        $data['backup_upload_description'] = __( 'It is not recommended unless your server is large enough to hold all your media files.', 'vaml-text-lang');
		        $data['auto_upload'] = __( 'Upload all old media files automatically', 'vaml-text-lang');
		        $data['auto_upload_description'] = __( 'All old meida files shall be uploaded to Viocee AWS Media Library automatically without notice.', 'vaml-text-lang');
			$data['batch_upload_location'] = '';
			if('premium' == Controller::get('vaml_version_codename')){
			        $data['batch_upload_location'] = __( 'Batch upload location', 'vaml-text-lang');
				$data['location'] = $upload_dir['basedir'] . '/.viocee-temp-batch';
				$data['batch_upload_location_description'] =  sprintf(__( 'Batch upload location must be readable and writable. If left empty, the default location is %s.', 'vaml-text-lang'), $data['location']);
				if (isset($_POST['batch_upload_location'])) {
					if( 1 === $error_code ){
						$data['notice_error'] = __( 'Invalid batch upload location directory!', 'vaml-text-lang');
					}
					if( 2 === $error_code ){
						$data['notice_error'] = __( 'Invalid batch upload location directory permissions, please make it readable and writable!', 'vaml-text-lang');
					}
					$data['location'] = $_POST['batch_upload_location'];
				} else {
					if(isset(Api::$library_settings['batch_upload_location'])){
						$data['location'] = Api::$library_settings['batch_upload_location'];
				        } else {
					        $data['location'] = $upload_dir['basedir'] . '/.viocee-temp-batch';
				        }
				}
			}
			if(Api::$library_settings){
				$data['very_important'] = __( 'Very important', 'vaml-text-lang');
				$data['important_warning'] = __( 'If you do as instructed before deleting Viocee AWS Media Library, the recovery of your media files to your own disk space will be 100% safe, or otherwise, you are most likely encounter media file loss!', 'vaml-text-lang');
				$data['delete_instruction'] = __( 'By submitting the right result of calculation as the safe token, Viocee AWS Media Library will recover your media files to the own disk space of your WordPress. The storage of your media files might be large enough for the recovery of them takes some time. Your current operation won\'t be interfered as the recovery run silently. Viocee AWS Media Library can be safely deleted when you are notified of a complete recovery of your media files.', 'vaml-text-lang');
				$data['recovery_token'] = __( 'Recovery_token', 'vaml-text-lang');
				$a = rand(10000, 99999);
				$b = rand(10000, 99999);
				$calc = strval($a). ' * ' . strval($b);
				$data['recovery_token_value'] = $a * $b;
				$data['recovery_token_description'] = sprintf(__( 'Calculate %s and fill (or ignore it if you will continue to use this plugnin)', 'vaml-text-lang'), $calc);
			} else {
				$data['very_important'] = '';
			}
			if($update_action = self::vaml_success_update()){
				if ( 'settings' == $update_action ){
					$data['essential_updated'] = __('Viocee AWS Media Library have been updated successfully for its settings!', 'vaml-text-lang');
				}
				if ( 'credentials' == $update_action ){
				        $data['essential_updated'] = __('Your AWS account credentials have been updated successfully!', 'vaml-text-lang');
				}
				if ( 'config' == $update_action ){
				        $data['essential_updated'] = __('Your current WordPress site has been integrated successfully with Viocee AWS Media Library. Please complete the following settings to continue!', 'vaml-text-lang');
				}
			}
			$data['reset_credentials'] = __( 'Reset credentials', 'vaml-text-lang');
			$data['reset_credentials_description'] = __( 'Your AWS credentials can be reset, but as for your AWS S3 settings, such as bucket, bucket_prefix, they are fixed unless you reinstall this plugin.', 'vaml-text-lang');
			$data['update_credentials'] = __( 'Update credentials', 'vaml-text-lang');
			$data['update_credentials_href'] = home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_viocee_aws_media_library_update' => 'aws_credentials'), 'wp-admin/options-general.php' ) );
			if(isset($_POST['backup_upload'])){
				$data['backup_upload_field'] = intval($_POST['backup_upload']);
			} else {
				if(isset(Api::$library_settings['backup_upload'])){
					$data['backup_upload_field'] = intval(Api::$library_settings['backup_upload']);
		                } else {
			                $data['backup_upload_field'] = 0;
		                }
			}
			if(isset($_POST['auto_upload'])){
				$data['auto_upload_field'] = 1;
			} else {
				if(isset(Api::$schedule_actions['auto_upload'])){
					$data['auto_upload_field'] = 1;
		                } else {
			                $data['auto_upload_field'] = 0;
		                }
			}
		}
		return $data;
	}
	
	private static function aws_config($error_code, $aws_credentials) {
		$data['aws_credentials'] = $aws_credentials;
		
		if(isset($_POST['aws_access_key_id'])){
			$data['aws_access_key_id'] = Utils::mb_trim($_POST['aws_access_key_id']);
		} else {
			$data['aws_access_key_id'] = '';
		}
		
		if(isset($_POST['aws_secret_access_key'])){
			$data['aws_secret_access_key'] = Utils::mb_trim($_POST['aws_secret_access_key']);
		} else {
			$data['aws_secret_access_key'] = '';
		}
		
		if(!$data['aws_credentials']){
			$child = array();
			$child['template'] = Controller::get('vaml_dir') . 'static/help_user.tpl';
			get_current_screen()->add_help_tab( array(
				'id'		=> 'vaml_viocee_aws_media_library_user',
		                'title'		=> __( 'User& Permissions', 'vaml-text-lang' ),
		                'content'	=> Controller::render($child)
		        ));
			$child['template'] = Controller::get('vaml_dir') . 'static/help_permissions.tpl';
		        get_current_screen()->add_help_tab( array(
			        'id'		=> 'vaml_viocee_aws_media_library_code',
		                'title'		=> __( 'Permissions Code', 'vaml-text-lang' ),
		                'content'	=> Controller::render($child)
		        ));
			$data['regions'] = Api::regions();
			if(isset($_POST['region'])){
			        $data['region_select'] = Utils::mb_trim($_POST['region']);
		        } else {
			        $data['region_select'] = 'us-east-1';
		        }
			foreach ($data['regions'] as $k => $val) {
				if ($k == $data['region_select']) {
				       $region_name = $val;
				       break;
				}
			}
			if(isset($_POST['s3_bucket'])){
			        $data['s3_bucket'] = Utils::mb_trim($_POST['s3_bucket']);
		        } else {
			        $data['s3_bucket'] = '';
		        }
			if(isset($_POST['s3_bucket_prefix'])){
			        $data['s3_bucket_prefix'] = Utils::mb_trim($_POST['s3_bucket_prefix']);
		        } else {
			        $data['s3_bucket_prefix'] = '';
		        }
			$data['action_url'] = add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf'), 'options-general.php' );
		} else {
			$data['action_url'] = add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_viocee_aws_media_library_update' => 'aws_credentials'), 'options-general.php' );
		}
		
		$data['post_error'] = '';
		if(isset($_POST['aws_access_key_id'])){
			$data['ssl_warning'] = false;
			switch ($error_code){
				case 'XMLNotFound':
				$data['post_error'] = __('Please check your php.ini file and see if the XML extension is installed!', 'vaml-text-lang');
				break;
				case 'EmptyRequirements':
				$data['post_error'] = __('Please check form carefully to make sure that there is no blank form field submission!', 'vaml-text-lang');
				break;
			        case 'InvalidPrefix':
				$data['post_error'] = __('Invalid Prefix! Please use alphanumeric characters, underscores and dashes for the prefix with length between 3 to 64.', 'vaml-text-lang');
				break;
				case 'InvalidAccessKeyId':
				$data['post_error'] = __('AWS Access Key ID is invalid!', 'vaml-text-lang');
				break;
				case 'SignatureDoesNotMatch':
				$data['post_error'] = __('AWS Secret Access Key does not match AWS Access Key ID!', 'vaml-text-lang');
				break;
				case 'NoSuchRegionOrBucket':
				$data['post_error'] = sprintf(__('Either region %s or bucket %s does not exist. Are you sure you are using the correct bucket in this region?', 'vaml-text-lang'), $region_name, $data['s3_bucket']);
				break;
				case 'NoSuchBucket':
				$data['post_error'] = sprintf(__('Bucket %s does not exist!', 'vaml-text-lang'), $data['s3_bucket']);
				break;
				case 'AccessDenied':
				$data['post_error'] = __('Access Denied! Please click help tab for more details about how to set a user\'s permissions.', 'vaml-text-lang');
				break;
			        case 'UnknownError':
				$data['post_error'] = __('Oops, Unknown Error! We will identify it as soon as possible.', 'vaml-text-lang');
				break;
			}
		} else {
			$data['ssl_warning'] = !is_ssl();
		}
		if($data['ssl_warning']){
			if(!$data['aws_credentials']){
				$data['link_ssl'] = '<a href="' . home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf'), 'wp-admin/options-general.php' ), 'https') . '">SSL(HTTPS)</a>';
			} else {
				$data['link_ssl'] = '<a href="' . home_url( add_query_arg( array('page'=>'vaml_viocee_aws_media_library_conf', 'vaml_viocee_aws_media_library_update' => 'aws_credentials'), 'wp-admin/options-general.php' ), 'https') . '">SSL(HTTPS)</a>';
			}
		}
		return $data;
	}
	
	public static function vaml_setting() {
		$data = Controller::get('tpl_variables');
		
		$data['premium_link'] = '';
		
		if('premium' != Controller::get('vaml_version_codename')){
			$data['premium_link'] = Controller::get('vaml_link_to_premium');
			$data['text_get_premium'] = __( 'Get Premium', 'vaml-text-lang');
		}
		
		if(isset($data['aws_access_key_id'])){
			$template = Controller::get('vaml_dir') . 'static/config.tpl';
		} else {
			$template = Controller::get('vaml_dir') . 'static/setting.tpl';
		}
		
		Controller::set('action_render', array(
			'data'       => $data,
			'template'   => $template
		));
		
		Controller::render();
	}
	
	public static function load_viocee_upload() {
		wp_enqueue_style('vaml-viocee-aws-media-library-admin-style', Controller::get('vaml_url') . '/static/viocee_admin_theme.css', array(), Controller::get('vaml_version'), 'all');
		if('premium' == Controller::get('vaml_version_codename')){
		        Multi::load_viocee_upload();
		}
	}
	
	public static function viocee_upload() {
		if('premium' == Controller::get('vaml_version_codename')){
			Multi::upload_render(Controller::get('tpl_variables'));
		} else {
		        $html = '<div class="wrap">' . PHP_EOL;
			$html .= '<h1>'. esc_html(get_admin_page_title()). '</h1>' . PHP_EOL;
			$html .= '<div class="notice notice-warning">'. PHP_EOL;
			$html .= '<p>' . __( 'Viocee AWS Media Library free version does not support uploading of large file. Please get and upgrade this plugin by clicking the following button!', 'vaml-text-lang') . '</p>' . PHP_EOL;
			$html .= '</div>' . PHP_EOL;
			$html .= '<br />' . PHP_EOL;
			$html .= '<p><a target="_blank" href="' . esc_url(Controller::get('vaml_link_to_premium')) . '" class="button button-primary">' . __( 'Get Premium', 'vaml-text-lang') . '</a></p>' . PHP_EOL;
	                $html .= '</div>' . PHP_EOL;
			echo $html;
		}
	}
	
	public static function load_viocee_batch() {
		wp_enqueue_style('vaml-viocee-aws-media-library-admin-style', Controller::get('vaml_url') . '/static/viocee_admin_theme.css', array(), Controller::get('vaml_version'), 'all');
		if('premium' == Controller::get('vaml_version_codename')){
			Batch::load_viocee_batch();
		}
	}
	
	public static function viocee_batch() {
		if('premium' == Controller::get('vaml_version_codename')){
			Batch::batch_render(Controller::get('tpl_variables'));
		} else {
		        $html = '<div class="wrap">' . PHP_EOL;
			$html .= '<h1>'. esc_html(get_admin_page_title()). '</h1>' . PHP_EOL;
			$html .= '<div class="notice notice-warning">'. PHP_EOL;
			$html .= '<p>' . __( 'Viocee AWS Media Library free version does not support adding of your media library files in batch. Please get and upgrade this plugin by clicking the following button!', 'vaml-text-lang') . '</p>' . PHP_EOL;
			$html .= '</div>' . PHP_EOL;
			$html .= '<br />' . PHP_EOL;
	                $html .= '<p><a target="_blank" href="' . esc_url(Controller::get('vaml_link_to_premium')) . '" class="button button-primary">' . __( 'Get Premium', 'vaml-text-lang') . '</a></p>' . PHP_EOL;
	                $html .= '</div>' . PHP_EOL;
			echo $html;
		}
	}
	
	private static function vaml_success_update() {
		if(isset($_GET['vaml_success']) && isset($_GET['vaml_update'])){
			$parts = explode('_', $_GET['vaml_success']);
	                if(filter_var($parts[0], FILTER_VALIDATE_INT)){
		                if($parts[1] == sha1($parts[0] . Api::$api_essentials['api_signature'][0]) || (isset(Api::$api_essentials['api_signature'][1]) && $parts[1] == sha1($parts[0] . Api::$api_essentials['api_signature'][1]))){
		                        if((int)$parts[0] > time()){
			                        return $_GET['vaml_update'];
		                        }
		                }
	                }
	        }
	        return '';
        }
}