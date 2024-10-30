<?php
namespace VAML_Viocee_AWS_Media_Library;

class Api {
	protected static $instance;
	public static $api_essentials = array();
	public static $aws_config = array();
	public static $library_settings = array();
	public static $schedule_actions = array();
	public static $clean_lockers = true;
	
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
			
			// long cron action name, or likely a name conflict, as it will be recorded in cron database record;
		        add_action( 'vaml_viocee_aws_media_library_schedule_signature', array(self::$instance, 'add_signature'), 10, 0 );
		        add_action( 'vaml_viocee_aws_media_library_schedule_actions', array(self::$instance, 'schedule_api_actions'), 10, 0 );
		        add_action( 'vaml_viocee_aws_media_library_schedule_trash', array(self::$instance, 'schedule_trash'), 10, 1 );
			
			add_action( 'wp_loaded', array( self::$instance, 'cron_jobs'), 10, 0);
			
			if($api_essentials = get_option( 'vaml_viocee_aws_media_library_essentials' )){
				self::$api_essentials = $api_essentials;
		        }
			
			if($aws_config = get_option( 'vaml_viocee_aws_media_library_config' )){
			        self::$aws_config = $aws_config;
				if($library_settings = get_option( 'vaml_viocee_aws_media_library_settings' )){
					self::$library_settings = $library_settings;
				} else {
					self::$library_settings['backup_upload'] = 0;
				}
			}
			if($schedule_actions = get_option( 'vaml_viocee_aws_media_library_schedules' )){
			        self::$schedule_actions = $schedule_actions;
			}
		}
		return self::$instance;
	}
	
	// custom cron intervals
	public static function cron_intervals( $schedules ) {
		$schedules['vaml_viocee_aws_media_library_ten_minutes'] = array(
			'interval'	=> 600,	
			'display'	=> esc_html__( 'Viocee Once Every 10 Minutes', 'vaml-text-lang')
		);
		return (array)$schedules;
	}
	
	public static function cron_jobs() {
		if ( !is_admin() ) {
			// limit to front end only
			add_action( 'parse_request', array( self::$instance, 'invoke_cron'), 10, 1);
		} else {
			self::invoke_cron();
		}
	}
	
	public static function invoke_cron($query = null) {
		if(self::$api_essentials){
			if(!wp_next_scheduled('vaml_viocee_aws_media_library_schedule_signature')){
				// daily alternate api_signature
				wp_schedule_event(time(), 'daily', 'vaml_viocee_aws_media_library_schedule_signature');
		        }
		}
		if(self::$schedule_actions){
			if(!wp_next_scheduled('vaml_viocee_aws_media_library_schedule_actions')){
				add_filter( 'cron_schedules', array(self::$instance, 'cron_intervals'), 10, 1 );
				wp_schedule_event(time(), 'vaml_viocee_aws_media_library_ten_minutes', 'vaml_viocee_aws_media_library_schedule_actions');
			}
		}
		return $query;
	}
	
	public static function add_signature() {
		if(!self::$api_essentials || !isset(self::$api_essentials['api_signature'])){
			self::$api_essentials['api_signature'] = array();
		}
		// Prepend a signature to the beginning of api_signature array
		array_unshift(self::$api_essentials['api_signature'], hash('sha256', wp_generate_password(64, true, true)));
		if(count(self::$api_essentials['api_signature']) > 2) {
			array_pop(self::$api_essentials['api_signature']);
		}
		update_option( 'vaml_viocee_aws_media_library_essentials', self::$api_essentials);
	}
	
	public static function safe_update_schedules($new_schedules) {
		if(Controller::get('vaml_tmp')){
			try{
				$locker = Mutex::blocker('vaml_viocee_aws_media_library_settings', 60);
			        self::update_schedules($new_schedules);
				if(Mutex::release_blocker($locker)){
				        Api::trash();
			        }
			} catch(\Exception $e) {
						
		        }
		}
	}
	
	public static function update_schedules($new_schedules) {
		$schedules = get_option( 'vaml_viocee_aws_media_library_schedules' );
		if(!is_array($schedules)){
			$schedules = array();
		}
		foreach($new_schedules as $s => $arr ){
			$update = true;
			if( is_null($arr) || (is_array($arr) && is_null($arr['val']))){
				$update = false;
				unset($schedules[$s]);
			} else {
				//dependance
				if(isset($arr['dep'])){
					foreach($arr['dep'] as $dep ){
						if(!isset($schedules[$dep])){
					                $update = false;
							unset($schedules[$s]);
							break;
				                }
			                }
			        }
			}
			if($update){
				if(isset($arr['ignore']) && $arr['ignore']){
					if(!isset($schedules[$s])){
					        $schedules[$s] = $arr['val'];
				        }
			        } else {
					$schedules[$s] = $arr['val'];
				}
			}
		}
		if(!empty($schedules)){
			update_option('vaml_viocee_aws_media_library_schedules', $schedules);
		} else {
			delete_option('vaml_viocee_aws_media_library_schedules');
		}
	}
	
	public static function schedule_api_actions() {
		if(isset(self::$schedule_actions['aws_update']) && self::$schedule_actions['aws_update']){
		        self::rest_post('schedule_aws_update');
		}
		
		if(!isset(self::$schedule_actions['aws_orphaned']) || self::$schedule_actions['aws_orphaned']){
		        self::rest_post('schedule_check_orphans');
		}
		
		// in recovery process
		if(self::$library_settings && isset(self::$schedule_actions['recovery_token']) ){
			self::rest_post('schedule_recovery');
		}
		
		if(self::$library_settings && isset(self::$schedule_actions['cancel_recovery']) ){
			self::rest_post('schedule_cancel_recovery');
		}
		
		if(self::$library_settings && isset(self::$schedule_actions['auto_upload']) && isset(self::$schedule_actions['auto_upload_start_id'])){
			$params = array();
			$params['post_id'] = self::$schedule_actions['auto_upload_start_id'];
			self::rest_post('schedule_auto_upload', $params);
		}
	}
	
	public static function aws_configured () {
		return self::$aws_config;
	}
	
	public static function regions() {
		$aws_regions = array();
		$aws_regions['us-east-1'] = 'US East (N. Virginia)';
		$aws_regions['us-east-2'] = 'US East (Ohio)';
		$aws_regions['us-west-1'] = 'US West (N. California)';
		$aws_regions['us-west-2'] = 'US West (Oregon)';
		$aws_regions['ca-central-1'] = 'Canada (Central)';
		$aws_regions['ap-south-1'] = 'Asia Pacific (Mumbai)';
		$aws_regions['ap-northeast-2'] = 'Asia Pacific (Seoul)';
		$aws_regions['ap-southeast-1'] = 'Asia Pacific (Singapore)';
		$aws_regions['ap-southeast-2'] = 'Asia Pacific (Sydney)';
		$aws_regions['ap-northeast-1'] = 'Asia Pacific (Tokyo)';
		$aws_regions['eu-central-1'] = 'EU (Frankfurt)';
		$aws_regions['eu-west-1'] = 'EU (Ireland)';
		$aws_regions['eu-west-2'] = 'EU (London)';
		$aws_regions['sa-east-1'] = 'South America (São Paulo)';
		return $aws_regions;
	}
	
	public static function aws_verify($aws_access_key_id, $aws_secret_access_key, $region, $s3_bucket, $s3_bucket_prefix) {
		$params = array();
		$params['aws_access_key_id'] = $aws_access_key_id;
		$params['aws_secret_access_key'] = $aws_secret_access_key;
		$params['region'] = $region;
		$params['s3_bucket'] = $s3_bucket;
		$params['s3_bucket_prefix'] = $s3_bucket_prefix;
		// last parameter false: no async
		$result = self::rest_post('aws_verify', $params, false);
		if(is_array($result) && ($result['headers']['http_status'] == 200 ||$result['headers']['http_status'] == 204)){
			return $result['body'];
		}
		return null;
	}
	
	public static function pre_recover($attached) {
		if($attached && !is_null(self::$aws_config)){
			foreach($attached as $elem){
				$params = array();
				$params['basedir'] = $elem['basedir'];
				if(!isset($elem['ttl'])){
					$file = $elem['basedir'] . '/' . $elem['path'];
					$params['ttl'] = strtolower(hash('sha256', $file)) . '_' . $elem['post_id'];
				        try{
					        $ttl = Mutex::set_ttl($params['ttl'], 30);
					        if($ttl){
						        $params['path'] = $elem['path'];
						        $params['backup'] = intval($elem['backup']);
						        $params['post_id'] = $elem['post_id'];
						        self::rest_post('file_recover', $params );
					        }
				        } catch(\Exception $e) {
						
				        }
				} else {
					$params['ttl'] = $elem['ttl'];
					$params['path'] = $elem['path'];
					$params['backup'] = intval($elem['backup']);
					$params['post_id'] = $elem['post_id'];
					if(isset($elem['recover_attempt'])){
						$params['recover_attempt'] = intval($elem['recover_attempt']);
					}
					self::rest_post('file_recover', $params );
				}
			}
		}
	}
	
	public static function trash($task = null){
		if(is_null($task)){
			$task = 'clean_lockers';
		}
		if(!wp_next_scheduled('vaml_viocee_aws_media_library_schedule_trash', array($task))){
			wp_schedule_single_event (time() + 600, 'vaml_viocee_aws_media_library_schedule_trash', array($task));
		}
	}
	
	public static function schedule_trash($task){
		if(self::$api_essentials){
			$params = array();
			$params['task'] = $task;
			self::rest_post('schedule_trash', $params);
		}
	}
	
	public static function pre_add($attached) {
		if($attached && self::$aws_config){
			$upload_dir = wp_get_upload_dir();
			foreach($attached as $elem){
				$path = trim($elem['path'], '/');
				$file = $upload_dir['basedir'] . '/' . $path;
				if (file_exists($file)){
					$params = array();
					$params['basedir'] = $upload_dir['basedir'];
					// sha256, in case of upper case in $path;
					$params['ttl'] = strtolower(hash('sha256', $file)) . '_' . $elem['post_id'];
					try{
						$ttl = Mutex::set_ttl($params['ttl'], 30);
						if($ttl){
							$params['path'] = $path;
							$params['post_id'] = $elem['post_id'];
							// vaml_multipart_min_size is restricted by aws of min 5m of multipart upload
							// start process mutipart upload when the file is no less than 10m
							$action = '';
							if( (Controller::get('vaml_multipart_min_size') * 2) <= filesize( $file )){
								if('premium' == Controller::get('vaml_version_codename')){
								        $action = 'file_split';
								}
					                } else {
								$action = 'put_object';
							}
							if($action){
								self::rest_post($action, $params );
							}
						}
					} catch(\Exception $e) {
						
					}
				} 
			}
		}
	}
	
	public static function pre_del($attached = array()) {
		if($attached){
			$upload_dir = wp_get_upload_dir();
			foreach($attached as $elem){
				$path = trim($elem['path'], '/');
				$file = $upload_dir['basedir'] . '/' . $path;
				try{
					$params = array();
					$params['basedir'] = $upload_dir['basedir'];
					// sha256, in case of upper case in $path;
					$params['ttl'] = strtolower(hash('sha256', $file)) . '_' . $elem['post_id'];
					$ttl = Mutex::set_ttl($params['ttl'], 180);
					if($ttl){
						$params['path'] = $path;
						$params['post_id'] = $elem['post_id'];
						self::rest_post('delete_object', $params );
					}
				} catch(\Exception $e) {
						
				}
			}
		}
	}
	
	public static function count_s3_media(){
		global $wpdb;
		$table = $wpdb->vaml_aws_files;
		$res = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total FROM "  . $table  . " WHERE status = %d", 1) );
		return $res->total;
	}
	
	public static function s3_object_uri($path) {
		if(!is_null(self::$aws_config)){
			return 'https://' . self::$aws_config['s3_bucket'] . '.s3-' . self::$aws_config['region'] . '.amazonaws.com' . '/' . self::$aws_config['s3_bucket_prefix'] . '/' . ltrim($path, '/');
		}
		return null;
	}
	
	public static function encrypt_post($action, $params = array()) {
		$post_fields = array();
		if(self::$api_essentials){
			$post_fields['sig'] = wp_generate_password(64, true, true);
			$key = self::$api_essentials['api_signature'][0] . $post_fields['sig'];
			$post_fields['content'] = Utils::encrypt(json_encode(array('action' => $action, 'params' => $params)), $key);
		}
		return $post_fields;
	}
	
	public static function rest_post($action, $params = array(), $async = true){
		$url = rest_url('viocee-users-aws-cloud/api-listener/');
		if($post_fields = self::encrypt_post($action, $params)){
		        if ($async) {
				return Utils::post_async($url, $post_fields);
			} else {
				return Utils::curl_post($url, $post_fields);
		        }
		}
		return null;
	}
}
?>