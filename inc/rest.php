<?php
namespace VAML_Viocee_AWS_Media_Library;

class Rest {
	protected static $instance;
	protected static $single_chunk = 16777216;
	
	public static $response;
	public static $http_status;
	
	public static $credentials;
	public static $aws_config;
	
	public static $post;
	
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
			add_action( 'rest_api_init', array(self::$instance, 'rest_api_init'));
			$sync = array();
			$sync['streaming_task'] = 64;
			$sync['resumable_download'] = 64;
			Controller::set('max_sync_tasks', $sync);
		}
		return self::$instance;
	}
	
	public static function rest_api_init(){
		register_rest_route( 'viocee-users-aws-cloud', '/api-listener/', array(
			'methods'  => 'POST',
			'callback' => array( self::$instance, 'api_listener')
		));
		
		if( 'premium' == controller::get('vaml_version_codename')){
			register_rest_route( 'viocee-users-aws-cloud', '/large_upload/', array(
				'methods'  => 'GET, POST',
				'callback' => array( '\VAML_Viocee_AWS_Media_Library\Multi', 'large_upload')
			));
		}
	}
	
	public static function api_listener(){
	        $instant_respond = false;
		if(isset($_GET['instant_respond']) && (1 == intval($_GET['instant_respond']))){
			$instant_respond = true;
			self::instant_respond();
		}
		self::$post = json_decode(file_get_contents('php://input'), true);
		if(Api::$api_essentials && isset(self::$post['sig']) && isset(self::$post['content'])){
			$key = Api::$api_essentials['api_signature'][0] . self::$post['sig'];
			$content = Utils::decrypt(self::$post['content'], $key);
			if(false === $content){
				$key = Api::$api_essentials['api_signature'][1] . self::$post['sig'];
				$content = Utils::decrypt(self::$post['content'], $key);
			}
			if(false !== $content){
				self::$aws_config = Api::$aws_config;
				if(isset(Api::$api_essentials['credentials'])){
					self::$credentials = Api::$api_essentials['credentials']['default'];
				}
				self::$post = json_decode($content, true);
				$action = self::$post['action'];
				// set the raw post
				self::$post = self::$post['params'];
				if( 'aws_verify' == $action ){
					self::aws_verify();	
				}
				if( 'put_object' == $action ){
					self::put_object();	
				}
				if( 'delete_object' == $action ){
					self::delete_object();	
				}
				if( 'file_split' == $action && 'premium' == Controller::get('vaml_version_codename')){
					Multi::file_split();	
				}
				if( 'multi_start' == $action && 'premium' == Controller::get('vaml_version_codename')){
					Multi::multi_start();	
				}
				if( 'multi_async' == $action && 'premium' == Controller::get('vaml_version_codename')){
					Multi::multi_async();	
				}
				if( 'multi_upload' == $action && 'premium' == Controller::get('vaml_version_codename')){
					Multi::multi_upload();
				}
				if( 'part_download' == $action ){
					self::part_download();
				}
				if( 'schedule_auto_upload' == $action ){
					self::schedule_auto_upload();
				}
				if( 'schedule_aws_update' == $action ){
					self::schedule_aws_update();
				}
				if( 'schedule_check_orphans' == $action ){
					self::schedule_check_orphans();
				}
				if( 'schedule_recovery' == $action ){
					self::schedule_recovery();
				}
				if( 'file_recover' == $action ){
					self::file_recover();
				}
				if( 'schedule_cancel_recovery' == $action ){
					self::schedule_cancel_recovery();
				}
				if( 'schedule_trash' == $action ){
					self::schedule_trash();
				}
			}
		}
		if (!$instant_respond && !is_null(self::$response)) {
			return new \WP_REST_Response(self::$response, self::$http_status);
		}
	}
	
	/**
	 * Respond 200 OK only
	 * This is used to return an acknowledgement response indicating that the request has been accepted and then the script can continue processing
	 */
	private function instant_respond() {
		// check if fastcgi_finish_request is callable
		if (is_callable('fastcgi_finish_request')) {
			// call session_write_close() before fastcgi_finish_request(), see php doc
			if(session_id()) {
				session_write_close();
			}
			//this returns 200 to the user, and processing continues
			fastcgi_finish_request();
			return;
		}
		ignore_user_abort(true);
		ob_start();
		$server_protocol = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL', FILTER_SANITIZE_STRING);
		header($server_protocol . ' 202 Accepted');
		// Disable compression (in case content length is compressed).
		header('Content-Encoding: none');
		header('Content-Length: ' . ob_get_length());
		// Close the connection.
		header('Connection: close');
		ob_end_flush();
		ob_flush();
		flush();
		if(session_id()) {
			session_write_close();
		}
	}
	
	public static function aws_ini() {
		require_once(Controller::get('vaml_dir') . 'aws/aws-autoloader.php');
	}
	
	public static function aws_verify(){
		self::aws_ini();
		self::$http_status = 200;
		$error_code = '';
		$s3Client = new \Aws\S3\S3Client([
			'version'     => 'latest',
			'region'      => self::$post['region'],
			'credentials' => ['key' => self::$post['aws_access_key_id'], 'secret' => self::$post['aws_secret_access_key']]
		]);
		$key = self::$post['s3_bucket_prefix'] . '/vaml_viocee_aws_media_library_verification.txt';
		$result = null;
		try {
			$result = $s3Client->putObject(array(
				'Bucket'        => self::$post['s3_bucket'],
				'Key'           => $key,
				'Body'          => 'Done!',
				'ACL'           => 'public-read'
			));
		} catch (\Aws\S3\Exception\S3Exception $e) {
			$error_code = $e->getAwsErrorCode();
			if(!$error_code){
				// No error code indicating wrong region that could not be resolved
				$error_code = 'NoSuchRegionOrBucket';
			}
		} catch (\Aws\Exception\AwsException $e) {
			$error_code = $e->getAwsErrorCode();
		}
		/*
		 * InvalidAccessKeyId
		 * SignatureDoesNotMatch
		 * NoSuchRegion
		 * NoSuchBucket
		 * AccessDenied
		 */
		if($error_code){
			self::$response['error_code'] = $error_code;
		} elseif($result && $result->hasKey('ETag')){
			self::$response['error_code'] = 'None';
		} else {
			self::$response['error_code'] = 'UnknownError';
		}
	}
	
	private static function put_object(){
		$file = self::$post['basedir'] . '/' . self::$post['path'];
		$sync = Controller::get('max_sync_tasks');
		if(file_exists( $file ) && isset($sync['streaming_task'])){
			self::aws_ini();
			$s3Client = new \Aws\S3\S3Client([
				'version'     => 'latest',
				'region'      => self::$aws_config['region'],
				'credentials' => ['key' => self::$credentials['key'], 'secret' => self::$credentials['secret']]
			]);
			$key = self::$aws_config['s3_bucket_prefix'] . '/' . self::$post['path'];
			$result = null;
			try{
				$pid = Mutex::wt_add('streaming_task', 120, $sync['streaming_task']);
				$stream = fopen($file, 'r');
				if($stream){
					try {
						$result = $s3Client->putObject(array(
							'Bucket'        => self::$aws_config['s3_bucket'],
				                        'Key'           => $key,
				                        'Body'          => $stream,
						        'ContentLength' => filesize($file),
				                        'ContentType'   => mime_content_type($file),
				                        'ACL'           => 'public-read'
				                ));
				        } catch (\Aws\S3\Exception\S3Exception $e) {
				                // Catch an S3 specific exception. do nothing
				        } catch (\Aws\Exception\AwsException $e) {
				            	// This catches the more generic AwsException. You can grab information
				                // from the exception using methods of the exception object.
				                // do nothing;
				        }
				        fclose($stream);
			        }
				if(Mutex::wt_remove($pid)){
					Api::trash();
				}
			} catch (\Exception $e) {
				//die($e->getMessage());
			}
			if($result && $result->hasKey('@metadata') && ($metadata = $result->get('@metadata'))){
				if($metadata['effectiveUri']){
					self::update_aws_files(self::$post['basedir'], self::$post['path'], self::$post['post_id']);
				}
			}
		}
		Mutex::clear_ttl(self::$post['ttl']);
	}
	
	private static function delete_object(){
		self::aws_ini();
		$s3Client = new \Aws\S3\S3Client([
			'version'     => 'latest',
			'region'      => self::$aws_config['region'],
			'credentials' => ['key' => self::$credentials['key'], 'secret' => self::$credentials['secret']]
		]);
		$file = self::$post['basedir'] . '/' . self::$post['path'];
		$key = self::$aws_config['s3_bucket_prefix'] . '/' . self::$post['path'];
		$result = null;
		try {
			$result = $s3Client->headObject(array(
				'Bucket'       => self::$aws_config['s3_bucket'],
				'Key'          => $key
			));
		} catch (\Aws\S3\Exception\S3Exception $e) {
			// Catch an S3 specific exception. do nothing
		} catch (\Aws\Exception\AwsException $e) {
			// This catches the more generic AwsException. You can grab information
			// from the exception using methods of the exception object.
			// do nothing;
		}
		if(!is_null($result)){
			$result = null;
			try {
				$result = $s3Client->deleteObject(array(
				        'Bucket'       => self::$aws_config['s3_bucket'],
				        'Key'          => $key
			        ));
		        } catch (\Aws\S3\Exception\S3Exception $e) {
			        // Catch an S3 specific exception. do nothing
		        } catch (\Aws\Exception\AwsException $e) {
			        // This catches the more generic AwsException. You can grab information
			        // from the exception using methods of the exception object.
			        // do nothing;
		        }
		        if($result && $result->hasKey('@metadata') && ($metadata = $result->get('@metadata'))){
			        if(204 == $metadata['statusCode']){
					self::delete_aws_files(self::$post['basedir'], self::$post['path'], self::$post['post_id']);
			        }
		        }
		} else {
			self::delete_aws_files(self::$post['basedir'], self::$post['path'], self::$post['post_id']);
		}
		Mutex::clear_ttl(self::$post['ttl']);
	}
	
	private static function schedule_check_orphans(){
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT(a.id) FROM " . $wpdb->vaml_aws_files  . " a LEFT JOIN " . $wpdb->postmeta . " b ON (a.post_id = b.post_id) WHERE a.status != %d AND b.post_id IS NULL LIMIT 512", -1 ));
		$schedules_update = array();
		if ( $wpdb->num_rows ) {
			$aws_orphaned = 0;
			foreach($rows as $row) {
			        $res = $wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " SET status = -1 WHERE id = %d LIMIT 1", $row->id));
			        if ( false !== $res ) {
				        $aws_orphaned = 1;
				}
			}
			$schedules_update['aws_orphaned'] = array(
				'val'  =>  $aws_orphaned
			);
		} else {
			$schedules_update['aws_orphaned'] = null;
		}
		Api::safe_update_schedules($schedules_update);
	}
	
	private static function schedule_auto_upload(){
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT a.post_id FROM " . $wpdb->postmeta . " a FORCE INDEX(post_id) LEFT JOIN " . $wpdb->postmeta . " b FORCE INDEX(post_id) ON (a.post_id = b.post_id AND b.meta_key = %s) WHERE a.post_id > %d AND a.meta_key = %s AND b.meta_key IS NULL LIMIT 128", Controller::get('vaml_wp_marker'), self::$post['post_id'], '_wp_attached_file'));
		$schedules_update = array();
		$conditions = array();
		if($wpdb->num_rows){
			foreach($rows as $row) {
				$files = self::get_object_files($row->post_id);
		                if($files){
					$wp_attachment_viocee = array(
					        'files' => array_map('sha1', $files)
				        );
				        $wpdb->query($wpdb->prepare( "INSERT INTO " . $wpdb->postmeta . " SET meta_value = %s, post_id = %d, meta_key = %s", maybe_serialize($wp_attachment_viocee), $row->post_id, Controller::get('vaml_wp_marker')));
				        foreach($files as $path){
					        Attachment::viocee_update($path, $row->post_id, 0);
				        }
					$post_id = $row->post_id;
				} else {
				        break;
			        }
		        }
		} else {
			// indicating all old files have been scanned;
			$schedules_update['auto_upload_start_id'] = null;
		}
		if(isset($post_id)){
			$schedules_update['auto_upload_start_id'] = array(
				'val'  =>  $post_id,
				'dep'  =>  ['auto_upload']
			);
			$schedules_update['aws_update'] = array(
				'val'  =>  1
			);
		}
		if(!empty($schedules_update)){
			Api::safe_update_schedules($schedules_update);
		}
	}
	
	private static function get_object_files($object_id){
		global $wpdb;
		$files = array();
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->postmeta . " where post_id = %d ORDER BY meta_key", $object_id));
		if ( $rows ) {
			$sub_dir = '';
			foreach($rows as $row) {
				if('_wp_attached_file' == $row->meta_key){
					$files[] = $row->meta_value;
					$sub_dir =  ltrim(dirname($row->meta_value), '/');
				}
				if('_wp_attachment_metadata' == $row->meta_key ){
					$metadata = maybe_unserialize($row->meta_value);
					$filesize = '';
					foreach($metadata as $k => $val){
						if ( 'filesize' == $k) {
							$filesize = $val;
						}
						if ( 'file' == $k || 'thumb' == $k ) {
							$files[] = ($sub_dir ? $sub_dir . '/' : '') . wp_basename($val);
						}
						if('sizes' == $k ){
							foreach($val as $size){
								if(isset($size['file'])){
									$files[] = ($sub_dir ? $sub_dir . '/' : '') . wp_basename($size['file']);
								}
							}
						}
					}
					if (!$filesize){
						$uploads = wp_get_upload_dir();
						if(file_exists($uploads['basedir'] . '/' . $files[0])){
							$filesize = filesize( $uploads['basedir'] . '/' . $files[0] );
							$metadata = array('filesize' => strval($filesize)) + $metadata;
							$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->postmeta . " SET meta_value = %s WHERE post_id = %d AND meta_key = %s", maybe_serialize($metadata), $object_id, '_wp_attachment_metadata'));
						}
					}
				}
				if('_wp_attachment_backup_sizes' == $row->meta_key ){
					$sizes = maybe_unserialize($row->meta_value);
					foreach($sizes as $k => $val){
						// for _wp_attachment_backup_sizes
						if(isset($val['file'])){
							$files[] = ($sub_dir ? $sub_dir . '/' : '') . wp_basename($val['file']);
						}
					}
				}
			}
		}
		if($files){
			$files = array_unique($files);
		}
		return $files;
	}
	
	private static function scan_media_status(){
		global $wpdb;
		$res = $wpdb->get_row($wpdb->prepare( "SELECT COUNT(*) AS total FROM " . $wpdb->vaml_aws_files . " WHERE ( status = %d OR status = %d )", 0, -1));
		$update = 0;
		if($res->total){
			$update = 1;
		}
		$schedules_update = array();
		$schedules_update['aws_update'] = array(
			'val'  =>  $update
		);
		Api::safe_update_schedules($schedules_update);
	}
	
	private static function schedule_aws_update(){
		global $wpdb;
		// find medias deleted or uploaded but not S3 hosted by status
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT file_path, post_id, status FROM "  . $wpdb->vaml_aws_files  . " WHERE ( status = %d OR status = %d ) LIMIT 64", 0, -1));
		if ( $rows ) {
			$del_files = array();
			$s3_files = array();
			foreach($rows as $row) {
				if(-1 === (int)$row->status){
					$del_files[] = array('path'=>$row->file_path, 'post_id'=>$row->post_id);
				} elseif (0 === (int)$row->status){
					$s3_files[] = array('path'=>$row->file_path, 'post_id'=>$row->post_id);
				}
			}
			// delete
			if ( $del_files ){
				Api::pre_del($del_files);
			}
			// if not in recover mode, upload
			if($s3_files && Api::$library_settings && !isset(Api::$schedule_actions['recovery_token'])){
				Api::pre_add($s3_files);
			}
		}
	}
	
	private static function schedule_cancel_recovery(){
		global $wpdb;
		// 2 indicating media files stored on aws and wp's default disk space
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, file_path FROM "  . $wpdb->vaml_aws_files  . " WHERE status = %d LIMIT 64", 2) );
		$files = array();
		if($wpdb->num_rows){
			foreach($rows as $row) {
				$files[] = array('id' => $row->id, 'path' => $row->file_path);
			}
		}
		if($files){
			$upload_dir = wp_get_upload_dir();
			foreach($files as $file){
				$res = $wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " SET status = %d WHERE id = %d LIMIT 1", 1, $file['id']));
				if ( $res && file_exists( $upload_dir['basedir'] . '/' . $file['path'] )){
					@unlink( $upload_dir['basedir'] . '/' . $file['path'] );
				}
			}
		} else {
			$schedules_update = array();
			$schedules_update['cancel_recovery'] = null;
			Api::safe_update_schedules($schedules_update);
		}
	}
	
	private static function schedule_recovery(){
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT file_path, post_id, backup FROM " . $wpdb->vaml_aws_files  . " WHERE status = %d LIMIT 64", 1 ));
		if($wpdb->num_rows){
			$files = array();
			$upload_dir = wp_get_upload_dir();
			foreach($rows as $row) {
				$files[] = array('basedir' => $upload_dir['basedir'], 'path' => trim($row->file_path, '/'), 'post_id'=>$row->post_id, 'backup' => $row->backup);
			}
			if ( $files ){
				Api::pre_recover($files);
			}
		}
	}
	
	private static function file_recover(){
		global $wpdb;
		if(!file_exists( self::$post['basedir'] . '/' . self::$post['path'] )){
			if(self::$post['backup']){
				$done = self::backup_recover();
			} else {
				self::aws_ini();
				$s3Client = new \Aws\S3\S3Client([
					'version'     => 'latest',
					'region'      => self::$aws_config['region'],
					'credentials' => ['key' => self::$credentials['key'], 'secret' => self::$credentials['secret']]
				]);
				$key = self::$aws_config['s3_bucket_prefix'] . '/' . self::$post['path'];
				$result = null;
				$error_code = '';
				try {
					$result = $s3Client->HeadObject(array(
						'Bucket'        => self::$aws_config['s3_bucket'],
				                'Key'           => $key
				        ));
				} catch (\Aws\S3\Exception\S3Exception $e) {
					$error_code = $e->getAwsErrorCode();
				        // Catch an S3 specific exception. do nothing
				} catch (\Aws\Exception\AwsException $e) {
					// This catches the more generic AwsException. You can grab information
				        // from the exception using methods of the exception object.
				        // do nothing;
				}
				
				if($result && $result->hasKey('ContentLength')){
					// 16 MB
					$content_length = $result->get('ContentLength');
					if(self::$single_chunk > $content_length){
						$done = self::download_recover($content_length);
				        } else {
					        $done = self::resumable_recover($content_length);
				        }
			        } else {
					if( 'NotFound' == $error_code){
					        $done = self::download_recover(0);
					}
				}
			}
		}
		if(isset($done) && $done){
			Mutex::clear_ttl(self::$post['ttl']);
			$schedules_update = array();
			$schedules_update['count_s3_media'] = array(
				'val'  =>  Api::count_s3_media(),
				'dep'  => ['recovery_token']
			);
			Api::safe_update_schedules($schedules_update);
		}
	}
	
	private static function backup_recover(){
		global $wpdb;
		$file = self::$post['basedir'] . '/' . self::$post['path'];
		$backup = dirname($file) .'/%20'. basename($file);
		if(file_exists( $backup )){
			if ( rename($backup, $file) ){
				$file_index = Attachment::get_viocee_index(self::$post['path']);
				// 2 indicating media files stored on aws and wp's default disk space
				$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " FORCE INDEX(file_index) SET status = %d WHERE file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", 2, $file_index, self::$post['path'], self::$post['post_id']));
				return true;
			} 
		}
		return false;
	}
	
	private static function download_recover($content_length){
		global $wpdb;
		$file = self::$post['basedir'] . '/' . self::$post['path'];
		$file_index = Attachment::get_viocee_index(self::$post['path']);
		if($content_length){
			$url = Api::s3_object_uri(self::$post['path']);
			// down_load from s3 url to $file;
			if($url && Utils::download($url, $file)){
				if ($content_length == filesize($file)){
					$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " FORCE INDEX(file_index) SET status = %d WHERE file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", 2, $file_index, self::$post['path'], self::$post['post_id']));
					return true;
				}
			}
		} else {
			$wpdb->query($wpdb->prepare( "DELETE FROM " . $wpdb->vaml_aws_files . " WHERE file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", $file_index, self::$post['path'], self::$post['post_id']));
			$wpdb->query($wpdb->prepare( "DELETE FROM " . $wpdb->postmeta . " WHERE post_id = %d AND meta_key = %s LIMIT 1", self::$post['post_id'], Controller::get('vaml_wp_marker')));
			return true;
		}
		return false;
	}
	
	private static function resumable_recover($content_length){
		Mutex::set_ttl(self::$post['ttl'], 180, true);
		$file = self::$post['basedir'] . '/' . self::$post['path'];
		$backup = dirname($file) .'/%20'. basename($file);
		$download_parts = array();
		$parts_num = ceil($content_length / self::$single_chunk);
		$size = $content_length;
		for ($i = 0; $i < $parts_num; $i++) {
			$buffer_size = min($size, self::$single_chunk);
			$part_file = $backup. '.' . $content_length . '-' . self::$single_chunk . '.' . $i;
			if ( !file_exists($part_file)) {
				// 24, max working download process
				if(count($download_parts) > 24){
					break;
				} else {
					$range = strval($i * self::$single_chunk) . '-' . strval($i * self::$single_chunk + $buffer_size - 1);
					$download_parts[] = array('part_file' =>$part_file, 'range' => 'bytes=' . $range, 'content-length' => $buffer_size, 'path' => self::$post['path'], 'post_id' => self::$post['post_id'], 'ttl' => self::$post['ttl']);
				}
			}
			$size = $size - $buffer_size;
		}
		$files = array();
		if($download_parts){
			$promises = array();
			$client = new \GuzzleHttp\Client();
			$url = rest_url('viocee-users-aws-cloud/api-listener/');
			foreach($download_parts as $part){
				$post_fields = array();
				$post_fields['part_file'] = $part['part_file'];
				$post_fields['range'] = $part['range'];
				$post_fields['content-length'] = $part['content-length'];
				$post_fields['post_id'] = $part['post_id'];
				$post_fields['path'] = $part['path'];
				$post_fields['ttl'] = $part['ttl'];
				$post_fields = Api::encrypt_post('part_download', $post_fields);
				$promises[] = $client->requestAsync('POST', $url, ['json' => $post_fields]);
		        }
			\GuzzleHttp\Promise\all($promises)->then(function (array $responses) {
			
			})->wait();
			$all_done = true;
			foreach($download_parts as $part){
				// if there is one part file failed download
				if ( !file_exists($part['part_file'])) {
					$all_done = false;
					break;
				}
			}
			if($all_done){
				$recover_attempt = 0;
				$files[] = array('basedir' => self::$post['basedir'], 'path' => self::$post['path'], 'post_id'=> self::$post['post_id'], 'backup' => self::$post['backup'], 'ttl' => self::$post['ttl']);
			} else {
				// try to to download with 3 attempts
				$recover_attempt = 1;
				if(isset(self::$post['recover_attempt'])){
					$recover_attempt = self::$post['recover_attempt'] + 1;
				}
				if($recover_attempt <= 3){
					$files[] = array('basedir' => self::$post['basedir'], 'path' => self::$post['path'], 'post_id'=> self::$post['post_id'], 'backup' => self::$post['backup'], 'ttl' => self::$post['ttl'], 'recover_attempt' => $recover_attempt);
				}
			}
			if($recover_attempt <= 3){
				Api::pre_recover($files);
			}
		} else {
			$fp = fopen($backup, 'wb');
			if(false !== $fp){
				for ($i = 0; $i < $parts_num; $i++) {
					$done = false;
					$part_file = $backup . '.' . $content_length . '-' . self::$single_chunk . '.' . $i;
					if($fc = fopen($part_file, 'rb')){
						if($content = fread($fc, filesize($part_file))){
							if(fwrite($fp, $content)){
								$done = true;
							}
						}
						fclose($fc);
					}
					if(!$done){
						unlink($part_file);
						break;
					}
				}
				fclose($fp);
				if(isset($done)){
					if(!$done){
						unlink($backup);
					} else {
						global $wpdb;
						$file_index = Attachment::get_viocee_index(self::$post['path']);
						$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " FORCE INDEX(file_index) SET status = %d WHERE file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", 2, $file_index, self::$post['path'], self::$post['post_id']));
						for ($i = 0; $i < $parts_num; $i++) {
							unlink($backup . '.' . $content_length . '-' . self::$single_chunk . '.' . $i);
						}
						rename($backup, $file);
						return true;
					}
				}
			}
		}
		return false;
	}
	
	private static function part_download(){
		Mutex::set_ttl(self::$post['ttl'], 180, true);
		$sync = Controller::get('max_sync_tasks');
		if(isset($sync['resumable_download'])){
			try{
				$pid = Mutex::wt_add('resumable_download', 180, $sync['resumable_download']);
				$url = Api::s3_object_uri(self::$post['path']);
			        $temp = self::$post['part_file'] . '.temp';
			        $header = array();
			        $header[] = 'Range: ' . self::$post['range'];
			        $header[] = 'Content-Length: ' . self::$post['content-length'];
			        $header[] = 'Content-Transfer-Encoding: binary';
				if(Utils::download($url, $temp, $header)){
				        rename($temp, self::$post['part_file']);
			        }
			        if(Mutex::wt_remove($pid)){
				        Api::trash();
			        }
		        } catch (\Exception $e) {
				
		        }
		}
	}
	
	// delet database and backup files
	private static function delete_aws_files($basedir, $path, $post_id){
		self::update_aws_files($basedir, $path, $post_id, 'delete');
	}
	
	// update database
	public static function update_aws_files($basedir, $path, $post_id, $action = 'update'){
		global $wpdb;
		$file = $basedir . '/' .  $path;
		$file_index = Attachment::get_viocee_index($path);
		if ( 'update' == $action ){
			$row = $wpdb->get_row($wpdb->prepare( "SELECT id FROM " . $wpdb->vaml_aws_files . " WHERE file_index = %d AND file_path = %s AND post_id = %d AND status = 0 LIMIT 1", $file_index, $path, $post_id));
		        if ( $row && $row->id) {
				$backup = '';
				if(Api::$library_settings && isset(Api::$library_settings['backup_upload']) && Api::$library_settings['backup_upload']){
					$backup = dirname($file) .'/%20'. basename($file); 
					if( !file_exists ( dirname($backup)) && !wp_mkdir_p(dirname($backup)) ){
					        $backup = '';
				        }
				}
				if($backup) {
					if(!file_exists( $backup )){
					        rename($file, $backup);
					}
					$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " SET backup = 1, status = 1 WHERE id = %d LIMIT 1", $row->id));
				} else {
					unlink($file);
					$wpdb->query($wpdb->prepare( "UPDATE " . $wpdb->vaml_aws_files . " SET backup = 0, status = 1 WHERE id = %d LIMIT 1", $row->id));
				}
		        }
		} else {
			$wpdb->query($wpdb->prepare( "DELETE FROM " . $wpdb->vaml_aws_files . " WHERE status = -1 AND file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", $file_index, $path, $post_id));
			$row = $wpdb->get_row($wpdb->prepare( "SELECT COUNT(*) AS total FROM " . $wpdb->vaml_aws_files . " WHERE file_index = %d AND file_path = %s AND backup = 1 AND status = 1", $file_index, $path));
			if(!$row->total){
				$backup = dirname($file) .'/%20'. basename($file); 
				if(file_exists( $backup )){
				        unlink($backup);
			        }
			}
		}
		self::scan_media_status();
	}
	
	private static function schedule_trash(){
		if ( 'clean_lockers' == self::$post['task']){
			Mutex::trash_lockers();
		}
		if ( 'clean_multi' == self::$post['task']){
			if( 'premium' == Controller::get('vaml_version_codename')){
				Multi::clean_multi();
			}
		}
	}
}
?>