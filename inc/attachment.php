<?php
namespace VAML_Viocee_AWS_Media_Library;

class Attachment {
    protected static $instance;
    private static $attached_file;
    private static $api;
    
    public static function get_instance() {
	if (null === self::$instance) {
	    self::$instance = new self();
	    self::hooks();
	}
	return self::$instance;
    }
    
    public static function hooks() {
	add_filter('wp_handle_upload_prefilter', array( self::$instance, 'handle_upload_prefilter'), 10, 1);
	// some files of specific type has no wordpress _wp_attachment_metadata property
	// so do not use hook add_attachment which watches _wp_attachment_metadata only
	add_action( 'delete_attachment', array( self::$instance, 'delete_attachment'), 10, 1 );
	add_filter( 'wp_save_image_editor_file', array( self::$instance, 'save_image_editor_file'), 10, 5 );
	add_action( 'added_post_meta', array( self::$instance, 'added_post_meta'), 10, 4 );
	// for restore images
	add_action( 'check_ajax_referer', array( self::$instance, 'check_ajax_referer'), 10, 2 );
	if ( is_admin() && Api::aws_configured() ) {
	    add_filter( 'wp_prepare_attachment_for_js', array( self::$instance, 'prepare_attachment_for_js'), 10, 3 );
	    // admin_footer-upload.php
	    add_action( 'print_media_templates', array( self::$instance, 'tmpl_attachment_details'), 11 );
	}
    }
    
    public static function added_post_meta( $mid, $object_id, $meta_key, $_meta_value ) {
	if ( '_wp_attached_file' == $meta_key ) {
	    add_filter( 'wp_update_attachment_metadata', array( self::$instance, 'update_attachment_metadata'), 10, 2 );
	}
    }
    
    private static function wp_attachment_viocee($post_id) {
	if ($wp_attachment_viocee = get_post_meta( $post_id, Controller::get('vaml_wp_marker'), true)){
	    $wp_attachment_viocee['subdir'] = trim(dirname(get_post_meta( $post_id, '_wp_attached_file', true)), '/');
	    return $wp_attachment_viocee;
	}
	return null;
    }
    
    public static function check_ajax_referer($action, $result) {
	if( isset($_POST['postid']) && isset($_POST['do']) ){
	    self::$attached_file = self::wp_attachment_viocee($_POST['postid']);
	    
	    // for case 'save , scale', do in save_image_editor_file
	    if (self::$attached_file  && ( 'restore' == $_POST['do']) ){
		self::$attached_file['post_id'] = $_POST['postid'];
		self::$attached_file['update_meta'] = true;
		add_filter( 'wp_delete_file', array( self::$instance, 'delete_file'), 10, 1 );
		add_filter( 'update_post_metadata', array( self::$instance, 'update_post_metadata'), 10, 5);
		add_filter( 'deleted_post_metadata', array( self::$instance, 'asdf'), 10, 5 );
	    } else {
		self::$attached_file = null;
	    }
	}
    }
    
    public static function save_image_editor_file( $saved, $filename, $image, $mime_type, $post_id ){
	self::$attached_file = self::wp_attachment_viocee($post_id);
	if ( self::$attached_file ){
	    self::$attached_file['post_id'] = $post_id;
	    self::$attached_file['update_meta'] = true;
	    add_filter( 'wp_delete_file', array( self::$instance, 'delete_file'), 10, 1 );
	    add_filter( 'update_post_metadata', array( self::$instance, 'update_post_metadata'), 10, 5);
	}
	return $saved;
    }
    
    public static function update_post_metadata($check, $object_id, $meta_key, $meta_value, $prev_value) {
	if(!is_null(self::$attached_file) && isset(self::$attached_file['post_id']) && (self::$attached_file['post_id'] == $object_id )){
	    global $wpdb;
	    $table = _get_meta_table( 'post' );
	    $column = 'post_id';
	    $id_column = 'meta_id';
	    $meta_value = self::watch_post_meta($meta_key, $meta_value);
	    $meta_ids = $wpdb->get_col( $wpdb->prepare( "SELECT $id_column FROM $table WHERE meta_key = %s AND $column = %d", $meta_key, $object_id ) );
	    if ( empty( $meta_ids ) ) {
		return add_metadata( 'post', $object_id, $meta_key, $meta_value );
	    }
	    $_meta_value = $meta_value;
	    $meta_value = maybe_serialize( $meta_value );
	    $data  = compact( 'meta_value' );
	    $where = array( $column => $object_id, 'meta_key' => $meta_key );
	    if ( !empty( $prev_value ) ) {
		$prev_value = maybe_serialize($prev_value);
		$where['meta_value'] = $prev_value;
	    }
	    
	    foreach ( $meta_ids as $meta_id ) {
		do_action( 'update_post_meta', $meta_id, $object_id, $meta_key, $_meta_value );
                do_action( 'update_postmeta', $meta_id, $object_id, $meta_key, $meta_value );
	    }
	    
	    $result = $wpdb->update( $table, $data, $where );
	    if ( ! $result ){
		return false;
	    }
	    
	    wp_cache_delete($object_id, 'post_meta');
	    
	    foreach ( $meta_ids as $meta_id ) {
		do_action( 'updated_post_meta', $meta_id, $object_id, $meta_key, $_meta_value );
                do_action( 'updated_postmeta', $meta_id, $object_id, $meta_key, $meta_value );
	    }
	    return true;
	}
	return $check;
    }
    private static function watch_post_meta($meta_key, $meta_value) {
	static $filesize;
	static $hook;
	$files = array();
	if('_wp_attached_file' == $meta_key){
	    $attached = (self::$attached_file['subdir'] ? self::$attached_file['subdir'] . '/' : '') . wp_basename($meta_value);
	    $files[] = $attached;
	    if(is_null($filesize)){
		$upload_dir = wp_get_upload_dir();
		$filesize = strval(filesize( $upload_dir['basedir'] . '/' . $attached ));
	    }
	}
	if('_wp_attachment_metadata' == $meta_key ){
	    if(!is_null($filesize)){
		unset($meta_value['filesize']);
		$meta_value = array('filesize' => $filesize) + $meta_value;
	    }
	    foreach($meta_value as $k => $backups){
		// in function wp_delete_attachment, we can check how many file types in it, there are file, thumb, sizes;
		if ( 'file' == $k || 'thumb' == $k ) {
		    $files[] = (self::$attached_file['subdir'] ? self::$attached_file['subdir'] . '/' : '') . wp_basename($backups);
		}
		if('sizes' == $k ){
		    foreach($backups as $size){
			if(isset($size['file'])){
			    $files[] = (self::$attached_file['subdir'] ? self::$attached_file['subdir'] . '/' : '') . wp_basename($size['file']);
			}
		    }
		}
	    }
	}
	if('_wp_attachment_backup_sizes' == $meta_key ){
	    foreach($meta_value as $k => $backups){
		// for _wp_attachment_backup_sizes
		if(isset($backups['file'])){
		    $files[] = (self::$attached_file['subdir'] ? self::$attached_file['subdir'] . '/' : '') . wp_basename($backups['file']);
		}
	    }
	}
	
	if( self::$attached_file['wp_paths'] = array_unique($files) ){
	    if(is_null($hook)){
		$hook = true;
		add_action( 'updated_post_meta', array( self::$instance, 'backup_sizes'), 10, 4);
		add_action( 'added_post_meta', array( self::$instance, 'backup_sizes'), 10, 4 );
		add_filter( 'wp_update_attachment_metadata', array( self::$instance, 'backup_post_handler'), 10, 2 );
	    }
	}
	return $meta_value;
    }
    
    public static function backup_sizes($meta_id, $post_id, $meta_key, $_meta_value) {
	if(!is_null(self::$attached_file) && isset(self::$attached_file['post_id']) && (self::$attached_file['post_id'] == $post_id ) && isset(self::$attached_file['wp_paths'])){
	    global $wpdb;
	    $meta_table = _get_meta_table( 'post' );
	    $done = false;
	    
	    foreach(self::$attached_file['wp_paths'] as $wp_path){
		$hash = sha1($wp_path);
		if(!in_array($hash, self::$attached_file['files'])){
		    self::$attached_file['files'][] = $hash;
		    self::viocee_update($wp_path, $post_id, 0);
		    if(!isset(self::$attached_file['new_file'])){
			self::$attached_file['new_file'] = array();
		    }
		    if(!in_array($wp_path, self::$attached_file['new_file'])){
			self::$attached_file['new_file'][] = array('path'=>$wp_path, 'post_id'=>$post_id);
		    }
		    $done = true;
		}
	    }
	    if ($done){
		$wp_attachment_viocee = array(
		    'files' => self::$attached_file['files']
		);
		$wpdb->query($wpdb->prepare( "UPDATE " . $meta_table . " SET meta_value = %s WHERE post_id = %d AND meta_key = %s", maybe_serialize($wp_attachment_viocee), $post_id, Controller::get('vaml_wp_marker')));
		wp_cache_delete($post_id, 'post_meta');
	    }
	}
    }
    
    public static function backup_post_handler($data, $post_id) {
	if(!is_null(self::$attached_file) && isset(self::$attached_file['post_id']) && (self::$attached_file['post_id'] == $post_id )){
	    if(isset(self::$attached_file['new_file'])){
		Api::pre_add(self::$attached_file['new_file']);
	    }
	    if(isset(self::$attached_file['del'])){
		Api::pre_del(self::$attached_file['del']);
	    }
	}
	return $data;
    }
    
    public static function handle_upload_prefilter( $file ) {
        add_filter( 'wp_unique_filename', array( self::$instance, 'unique_filename'), 10, 4 );
	return $file;
    }
    
    public static function unique_filename( $filename, $ext, $dir, $unique_filename_callback ) {
	// $filename already checked and changed by function sanitize_file_name
	add_filter('wp_handle_upload', array( self::$instance, 'viocee_handle_upload'), 10, 2);
	$file = $dir  .'/'. $filename;
	return self::check_unique_filename($file);
    }
    
    public static function check_unique_filename($file, $number = '') {
	global $wpdb;
	$table = $wpdb->vaml_aws_files;
	$upload_dir = wp_get_upload_dir();
	$attached = str_replace($upload_dir['basedir'], '', $file);
	$subdir = ltrim(dirname($attached), '/');
	$basename = wp_basename($file);
	$filename = pathinfo($basename, PATHINFO_FILENAME);
	$wp_path = ($subdir ? $subdir . '/' : '') . $filename;
	$ext = pathinfo($basename, PATHINFO_EXTENSION);
	if($ext){
	    $wp_path_lower = $wp_path . '.' . strtolower($ext);
	    $wp_path_upper = $wp_path . '.' . strtoupper($ext);
	}
	$result = $wpdb->get_row($wpdb->prepare( "SELECT COUNT(*) AS total FROM " . $table  . " WHERE (file_index = %d AND file_path = %s) OR (file_index = %d AND file_path = %s) AND status > -1", self::get_viocee_index($wp_path_lower), $wp_path_lower, self::get_viocee_index($wp_path_upper), $wp_path_upper));
	
	if ( $result && $result->total) {
	    $new_number = (int) $number + 1;
	    $appendix = $number . ($ext? '.' . $ext: '');
	    $new_appendix = $new_number . ($ext? '.' . $ext: '');
	    if ( '' == $appendix ) {
		$filename = $filename . '-' . $new_number;
	    } else {
		$filename = str_replace( array( $appendix, '-' . $appendix, '_' . $appendix ), '-' . $new_appendix, $filename );
	    }
	    $filename = $filename . ($ext? '.' . $ext: '');
	    $file =  $upload_dir['basedir'] .'/'. ($subdir ? $subdir . '/' : '') . $filename;
	    return self::check_unique_filename($file, $new_number);
	} else {
	    $filename = $filename . ($ext? '.' . $ext: '');
	    return $filename;
	}
    }
    
    public static function viocee_handle_upload($upload, $context){
	if( 'upload' == $context ){
	    // if viocee_cloud_setting set and not in recovery mode
	    if(Api::$library_settings && !isset(Api::$schedule_actions['recovery_token'])){
		if(is_null(self::$attached_file)){
		    self::$attached_file = array();
		}
		$upload_dir = wp_get_upload_dir();
		$attached = str_replace($upload_dir['basedir'], '', $upload['file']);
		self::$attached_file[sha1($upload['file'])] = array(
		    'file'      => $upload['file'],
		    'url'       => $upload['url'],
		    'type'      => $upload['type'],
		    'subdir'    => ltrim(dirname($attached), '/')
		);
	    }
	}
	return $upload;
    }
    
    public static function update_attachment_metadata($data, $post_id) {
	if(!empty(self::$attached_file)){
	    $file = get_metadata('post', $post_id, '_wp_attached_file', true);
	    if ( $file && 0 !== strpos( $file, '/' ) && ! preg_match( '|^.:\\\|', $file ) && ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) ) {
	        $file = $uploads['basedir'] . '/' . $file;
	        $hash_key = sha1($file);
	        $attached_file = array();
	        if (isset(self::$attached_file[$hash_key])) {
		    $attached_file = self::$attached_file[$hash_key];
		    unset(self::$attached_file[$hash_key]);
		}
		if ( file_exists( $file ) && !empty($attached_file) ) {
		    $files = array();
		    $files[] = ($attached_file['subdir'] ? $attached_file['subdir'] . '/' : '') . wp_basename($attached_file['file']);
		
		    if($data){
		        if (isset($data['file'])){
			    // for none-image file, there is no $data['file'] exist;
		            $files[] = ($attached_file['subdir'] ? $attached_file['subdir'] . '/' : '') . wp_basename($data['file']);
		        }
		        if(isset($data['sizes'])){
			    foreach($data['sizes'] as $size => $val){
			        $files[] = ($attached_file['subdir'] ? $attached_file['subdir'] . '/' : '') . wp_basename($val['file']);
			    }
		        }
		        if(!isset( $data['filesize'])){
			    // '_wp_attachment_metadata' will be update by return data
			    $data = array('filesize' => strval(filesize( $file ))) + $data;
		        }
		    }
		    $files = array_unique($files);
		    $wp_attachment_viocee = array(
		        'files' => array_map('sha1', $files)
		    );
		    global $wpdb;
		    $meta_table = _get_meta_table( 'post' );
		    $wpdb->query($wpdb->prepare( "INSERT INTO " . $meta_table . " SET meta_value = %s, post_id = %d, meta_key = %s", maybe_serialize($wp_attachment_viocee), $post_id, Controller::get('vaml_wp_marker')));
		    wp_cache_delete($post_id, 'post_meta');
		    $s3_files = array(); 
		    foreach($files as $wp_path){
		        self::viocee_update($wp_path, $post_id, 0);
			$s3_files[] = array('path'=>$wp_path, 'post_id'=>$post_id);
		    }
		    Api::pre_add($s3_files);
	        }
	    }
        }
	return $data;
    }
    
    public static function get_viocee_index($wp_path) {
	return intval(Utils::binhash($wp_path, 12, true));
    }
    
    public static function viocee_update($wp_path, $post_id, $status) {
	global $wpdb;
	$table = $wpdb->vaml_aws_files;
	$viocee_index = self::get_viocee_index($wp_path);
	$result = $wpdb->get_row($wpdb->prepare( "SELECT id FROM " . $table  . " WHERE file_index = %d AND file_path = %s AND post_id = %d AND status > -1 LIMIT 1", $viocee_index, $wp_path, $post_id));
	if ( $result && $result->id) {
	    $wpdb->query($wpdb->prepare( "UPDATE " . $table . " SET file_path = %s, status = %d WHERE id = %d LIMIT 1", $wp_path, $status, $result->id));
	} else {
	    $wpdb->query($wpdb->prepare( "INSERT INTO " . $table . " SET file_index = %d, file_path = %s, post_id = %d, backup = %d, status = %d", $viocee_index, $wp_path, $post_id, 0, $status));
	}
    }
    
    public static function delete_attachment( $post_id ) {
	// check if the file belongs to wp_attachment_viocee
	self::$attached_file = self::wp_attachment_viocee($post_id);
	if ( self::$attached_file ){
	    self::$attached_file['post_id'] = $post_id;
	    add_filter( 'wp_delete_file', array( self::$instance, 'delete_file'), 10, 1 );
	    add_action( 'clean_post_cache', array( self::$instance, 'clean_viocee'), 10, 2 );
	}
    }
    
    public static function clean_viocee( $id, $post) {
	if(isset(self::$attached_file['del']) && self::$attached_file['del']){
	    Api::pre_del(self::$attached_file['del']);
	}
    }
    
    public static function delete_file( $file ) {
	if(!is_null(self::$attached_file)){
	    global $wpdb;
	    $table = $wpdb->vaml_aws_files;
	    
	    $wp_path = (self::$attached_file['subdir'] ? self::$attached_file['subdir'] . '/' : '') . wp_basename($file);
	    $viocee_index = self::get_viocee_index($wp_path);
	    $hash = sha1($wp_path);
	    
	    // dont delete record, even if status is 0, that might be in uploading status waiting for 1 which indicating successfuully uploaded to s3
	    $res = $wpdb->query($wpdb->prepare( "UPDATE " . $table . " SET status = -1 WHERE file_index = %d AND file_path = %s AND post_id = %d LIMIT 1", $viocee_index, $wp_path, self::$attached_file['post_id']));
	    
	    if ( false !== $res ){
		if(!isset(self::$attached_file['del'])){
		    self::$attached_file['del'] = array();
		}
		if(!in_array($wp_path, self::$attached_file['del'])){
		    self::$attached_file['del'][] = array('path'=>$wp_path, 'post_id'=>self::$attached_file['post_id']);
		}
	    }
	    
	    // this is not for delete_attachment
	    if(isset(self::$attached_file['update_meta']) && self::$attached_file['update_meta']){
		if( false !== ($k = array_search($hash, self::$attached_file['files']))){
		    unset(self::$attached_file['files'][$k]);
		}
		$meta_table = _get_meta_table( 'post' );
		
		$wp_attachment_viocee = array(
		    'files' => self::$attached_file['files']
		);
		
		$wpdb->query($wpdb->prepare( "UPDATE " . $meta_table . " SET meta_value = %s WHERE post_id = %d AND meta_key = %s", maybe_serialize($wp_attachment_viocee), intval(self::$attached_file['post_id']), Controller::get('vaml_wp_marker')));
	    } 
	}
	return $file;
    }
    
    public static function prepare_attachment_for_js($response, $attachment, $meta){
	$response['vaml_s3_url'] = '';
	if ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) {
	    // On SSL
	    $url = set_url_scheme( $uploads['baseurl'] . '/', 'http' );
	    $path = str_replace($url, '', $response['url']);
	    if($path == $response['url']){
		$url = set_url_scheme( $url , 'https');
		$path = str_replace($url, '', $response['url']);
	    }
	    if($path != $response['url']){
		global $wpdb;
		$table = $wpdb->vaml_aws_files;
		$viocee_index = self::get_viocee_index($path);
		$row = $wpdb->get_row($wpdb->prepare( "SELECT COUNT(*) AS total FROM " . $wpdb->vaml_aws_files . " WHERE file_index = %d AND post_id = %d AND file_path = %s AND (status = 1 OR status = 2)", $viocee_index, $response['id'], $path));
		if($row->total){
		    $response['vaml_s3_url'] = esc_url_raw(Api::s3_object_uri($path));
		}
	    }
	}
	return $response;
    }
    
    public static function tmpl_attachment_details(){
	?>
	<script type="text/html" id="tmpl-attachment-details-two-column-viocee">
		<div class="attachment-media-view {{ data.orientation }}">
			<div class="thumbnail thumbnail-{{ data.type }}">
				<# if ( data.uploading ) { #>
					<div class="media-progress-bar"><div></div></div>
				<# } else if ( data.sizes && data.sizes.large ) { #>
					<img class="details-image" src="{{ data.sizes.large.url }}" draggable="false" alt="" />
				<# } else if ( data.sizes && data.sizes.full ) { #>
					<img class="details-image" src="{{ data.sizes.full.url }}" draggable="false" alt="" />
				<# } else if ( -1 === jQuery.inArray( data.type, [ 'audio', 'video' ] ) ) { #>
					<img class="details-image icon" src="{{ data.icon }}" draggable="false" alt="" />
				<# } #>

				<# if ( 'audio' === data.type ) { #>
				<div class="wp-media-wrapper">
					<audio style="visibility: hidden" controls class="wp-audio-shortcode" width="100%" preload="none">
						<source type="{{ data.mime }}" src="{{ data.url }}"/>
					</audio>
				</div>
				<# } else if ( 'video' === data.type ) {
					var w_rule = '';
					if ( data.width ) {
						w_rule = 'width: ' + data.width + 'px;';
					} else if ( wp.media.view.settings.contentWidth ) {
						w_rule = 'width: ' + wp.media.view.settings.contentWidth + 'px;';
					}
				#>
				<div style="{{ w_rule }}" class="wp-media-wrapper wp-video">
					<video controls="controls" class="wp-video-shortcode" preload="metadata"
						<# if ( data.width ) { #>width="{{ data.width }}"<# } #>
						<# if ( data.height ) { #>height="{{ data.height }}"<# } #>
						<# if ( data.image && data.image.src !== data.icon ) { #>poster="{{ data.image.src }}"<# } #>>
						<source type="{{ data.mime }}" src="{{ data.url }}"/>
					</video>
				</div>
				<# } #>

				<div class="attachment-actions">
					<# if ( 'image' === data.type && ! data.uploading && data.sizes && data.can.save ) { #>
					<button type="button" class="button edit-attachment"><?php _e( 'Edit Image' ); ?></button>
					<# } else if ( 'pdf' === data.subtype && data.sizes ) { #>
					<?php _e( 'Document Preview' ); ?>
					<# } #>
				</div>
			</div>
		</div>
		<div class="attachment-info">
			<span class="settings-save-status">
				<span class="spinner"></span>
				<span class="saved"><?php esc_html_e('Saved.'); ?></span>
			</span>
			<div class="details">
				<div class="filename"><strong><?php _e( 'File name:' ); ?></strong> {{ data.filename }}</div>
				<div class="filename"><strong><?php _e( 'File type:' ); ?></strong> {{ data.mime }}</div>
				<div class="uploaded"><strong><?php _e( 'Uploaded on:' ); ?></strong> {{ data.dateFormatted }}</div>

				<div class="file-size"><strong><?php _e( 'File size:' ); ?></strong> {{ data.filesizeHumanReadable }}</div>
				<# if ( 'image' === data.type && ! data.uploading ) { #>
					<# if ( data.width && data.height ) { #>
						<div class="dimensions"><strong><?php _e( 'Dimensions:' ); ?></strong> {{ data.width }} &times; {{ data.height }}</div>
					<# } #>
				<# } #>

				<# if ( data.fileLength ) { #>
					<div class="file-length"><strong><?php _e( 'Length:' ); ?></strong> {{ data.fileLength }}</div>
				<# } #>

				<# if ( 'audio' === data.type && data.meta.bitrate ) { #>
					<div class="bitrate">
						<strong><?php _e( 'Bitrate:' ); ?></strong> {{ Math.round( data.meta.bitrate / 1000 ) }}kb/s
						<# if ( data.meta.bitrate_mode ) { #>
						{{ ' ' + data.meta.bitrate_mode.toUpperCase() }}
						<# } #>
					</div>
				<# } #>

				<div class="compat-meta">
					<# if ( data.compat && data.compat.meta ) { #>
						{{{ data.compat.meta }}}
					<# } #>
				</div>
			</div>

			<div class="settings">
				<label class="setting" data-setting="url">
					<span class="name"><?php _e('URL'); ?></span>
					<input type="text" value="{{ data.url }}" readonly />
				</label>
				<# var maybeReadOnly = data.can.save || data.allowLocalEdits ? '' : 'readonly'; #>
				<?php if ( post_type_supports( 'attachment', 'title' ) ) : ?>
				<label class="setting" data-setting="title">
					<span class="name"><?php _e('Title'); ?></span>
					<input type="text" value="{{ data.title }}" {{ maybeReadOnly }} />
				</label>
				<?php endif; ?>
				<# if ( 'audio' === data.type ) { #>
				<?php foreach ( array(
					'artist' => __( 'Artist' ),
					'album' => __( 'Album' ),
				) as $key => $label ) : ?>
				<label class="setting" data-setting="<?php echo esc_attr( $key ) ?>">
					<span class="name"><?php echo $label ?></span>
					<input type="text" value="{{ data.<?php echo $key ?> || data.meta.<?php echo $key ?> || '' }}" />
				</label>
				<?php endforeach; ?>
				<# } #>
				<label class="setting" data-setting="caption">
					<span class="name"><?php _e( 'Caption' ); ?></span>
					<textarea {{ maybeReadOnly }}>{{ data.caption }}</textarea>
				</label>
				<# if ( 'image' === data.type ) { #>
					<label class="setting" data-setting="alt">
						<span class="name"><?php _e( 'Alt Text' ); ?></span>
						<input type="text" value="{{ data.alt }}" {{ maybeReadOnly }} />
					</label>
				<# } #>
				<label class="setting" data-setting="description">
					<span class="name"><?php _e('Description'); ?></span>
					<textarea {{ maybeReadOnly }}>{{ data.description }}</textarea>
				</label>
				<label class="setting">
					<span class="name"><?php _e( 'Uploaded By' ); ?></span>
					<span class="value">{{ data.authorName }}</span>
				</label>
				<# if ( data.uploadedToTitle ) { #>
					<label class="setting">
						<span class="name"><?php _e( 'Uploaded To' ); ?></span>
						<# if ( data.uploadedToLink ) { #>
							<span class="value"><a href="{{ data.uploadedToLink }}">{{ data.uploadedToTitle }}</a></span>
						<# } else { #>
							<span class="value">{{ data.uploadedToTitle }}</span>
						<# } #>
					</label>
				<# } #>
				<div class="attachment-compat"></div>
			</div>

			<div class="actions">
			        <a class="view-attachment" href="{{ data.link }}"><?php _e( 'View attachment page' ); ?></a>
				<# if ( data.can.save ) { #> |
					<a href="post.php?post={{ data.id }}&action=edit"><?php _e( 'Edit more details' ); ?></a>
				<# } #>
				<# if ( ! data.uploading && data.can.remove ) { #> |
					<?php if ( MEDIA_TRASH ): ?>
						<# if ( 'trash' === data.status ) { #>
							<button type="button" class="button-link untrash-attachment"><?php _e( 'Untrash' ); ?></button>
						<# } else { #>
							<button type="button" class="button-link trash-attachment"><?php _ex( 'Trash', 'verb' ); ?></button>
						<# } #>
					<?php else: ?>
						<button type="button" class="button-link delete-attachment"><?php _e( 'Delete Permanently' ); ?></button>
					<?php endif; ?>
				<# } #>
				<# if ( data.vaml_s3_url ) { #> |
			        <a href="{{ data.vaml_s3_url }}" target="_blank"><?php _e( 'AWS S3', 'vaml-text-lang'); ?></a>
				<# } #>
			</div>

		</div>
	</script>
	<script type="text/javascript">
	    jQuery(document).ready( function($) {
		if( 'undefined' !== typeof wp.media.view.Attachment.Details.TwoColumn){
		    wp.media.view.Attachment.Details.TwoColumn.prototype.template = wp.template( 'attachment-details-two-column-viocee' );
		}
	    });
	</script>
	<?php
    }
}