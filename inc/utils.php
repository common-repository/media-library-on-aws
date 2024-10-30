<?php
namespace VAML_Viocee_AWS_Media_Library;

//require_once( ABSPATH . 'wp-includes/pluggable.php');
class Utils {
    protected static $instance;
    
    public static function get_instance() {
	if (null === self::$instance) {
	    self::$instance = new self();
	}
	return self::$instance;
    }
    
    public static function encrypt($data, $key, $iv = '') {
	$sig = hash('sha256', $data);
	//append $sig to $data for check as decrypt will always return strings, which may not the right string
	$data = $sig . $data; 
	$key = hash('sha256', $key);
	$k = substr($key, 0, 32);
	if ($iv != ''){
	    $key = hash('sha256', $iv);
	}
	$iv = substr($key, (strlen($key)-16), strlen($key));
	if(function_exists('openssl_encrypt')){
	    return base64_encode(openssl_encrypt($data, 'aes-256-cbc', $k, 5, $iv));
	} else {
	    return base64_encode(self::mcrypt_encrypt($data, $k, $iv));
	}
    }
    
    public static function decrypt($ciphertext, $key, $iv = '') {
	$key = hash('sha256', $key);
	$k = substr($key, 0, 32);
	if ($iv != ''){
	    $key = hash('sha256', $iv);
	}
	$iv = substr($key, (strlen($key)-16), strlen($key));
	$ciphertext = base64_decode($ciphertext);
	if ($ciphertext === false ){
	    return false;
	}
	if(function_exists('openssl_decrypt')){
	    $data = openssl_decrypt($ciphertext, 'aes-256-cbc', $k, 5, $iv);
	} else {
	    $data = self::mcrypt_decrypt($ciphertext, $k, $iv);
	}
	if($data !== false || (strlen($data) > 64)){
	    $sig = substr($data, 0, 64);
	    $data = substr($data, 64, strlen($data));
	    if (hash('sha256', $data)==$sig){
		return $data;
	    }
	}
	//decrypt fails
	return false;
    }
    
    private static function mcrypt_encrypt($data, $k, $iv) {
        $data = self::pkcs5padding($data, 16);
	$crypter = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
	mcrypt_generic_init($crypter, $k, $iv);
	$ciphertext = mcrypt_generic($crypter, $data);
	mcrypt_generic_deinit($crypter);
        mcrypt_module_close($crypter);
        return $ciphertext;
    }
    
    private static function mcrypt_decrypt($ciphertext, $k, $iv) {
        $crypter = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');
        mcrypt_generic_init($crypter, $k, $iv);
        $data = mdecrypt_generic($crypter, $ciphertext);
	mcrypt_generic_deinit($crypter);
	mcrypt_module_close($crypter);
	return self::pkcs5unPadding($data);
    }
    
    private static function pkcs5padding($data, $blocksize) {
        $padding = $blocksize - strlen($data) % $blocksize;
        $paddingText = str_repeat(chr($padding), $padding);
        return $data . $paddingText;
    }
    
    private static function pkcs5unPadding($data) {
        $length = strlen($data);
        $unpadding = ord($data[$length - 1]);
        return substr($data, 0, $length - $unpadding);
    }
    
    public static function binhash($str, $length = 12, $incr = false){
        $i = 0;
	if (($length > 0 )&& ( $length <= 32 ) ){
            $str = substr(strtolower(sha1($str . strval($length))), 0, $length);
			
	    $bin = '';
			
	    foreach(str_split($str) as $val) {
		// hex to decimal
		if ((hexdec($val) % 2) === 0){
		    $bin .= '1';
		} else {
		    if ($bin){
			$bin .= '0';
                    }
                }
	    }
			
	    if (!$bin){
		$bin = '0';
	    }
	    $i = bindec($bin);
	}
	if ($incr) {
	    $i = $i + 1;
	}
	return $i;
    }
    
    public static function post_async($url, $payload = array()){
	$result = self::curl_post(add_query_arg(array('instant_respond' => 1), $url), $payload);
	if( 200==$result['headers']['http_status'] || 202==$result['headers']['http_status'] ){
	    return true;
	}
	return false;
    }
    
    public static function curl_post($url, $payload = array()){
	$post_string = json_encode($payload);
	$parts = parse_url($url);
	if('https' == $parts['scheme']){
	    $port = 443;
	} else {
	    $port = 80;
	}
	$parts['port'] = isset($parts['port'])?$parts['port']:$port;
	$result = array();
	$http_status = 0;
	$ch = curl_init();
	//Timeout for connection
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	//Timeout for buffer receiving
	curl_setopt($ch, CURLOPT_TIMEOUT, 60);
	curl_setopt($ch, CURLOPT_PORT, $parts['port']);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	//$resp_nobody 0, default, returning body;
	curl_setopt($ch, CURLOPT_NOBODY, 0);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	// TRUE to return the transfer as a string of the return value of curl_exec() instead of outputting it out directly.
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// SSL certificate
	if('https' == $parts['scheme']){
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	}
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:55.0) Gecko/20100101 Firefox/55.0');
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
	    'Content-Type: application/json',
	    'Content-Length: ' . strlen($post_string))
	);
	$dta =  curl_exec($ch);
	if(!curl_errno($ch)){
	    $http_status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	}
	curl_close($ch);
	if($http_status){
	    //to deal with "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK...\r\n\r\n..." header
	    $dta = explode("\r\n\r\nHTTP/", $dta, 2);
	    if(count($dta)>1){
		$dta = "HTTP/".array_pop($dta);
	    } else {
	        $dta = array_pop($dta);
	    }
	    $dta = explode("\r\n\r\n", $dta, 2);
	    $headers = explode("\r\n", $dta[0]);
	    array_shift($headers);
	    $result['headers']['http_status'] = $http_status;
	    foreach($headers as $val) {
		$value = explode(': ', $val, 2);
		$result['headers'][$value[0]] = $value[1];
	    }
	    $body = json_decode($dta[1], true);
	    if(is_array($body)){
		$result['body'] = $body;
	    } else {
		$result['body'] = $dta[1];
	    }
	}
	return $result;
    }
    
    public static function download($url, $file, $headers = array()) {
	$url_data = parse_url($url);
	$fp = fopen ($file, 'w+');
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url);
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
	curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );
	if($headers){
	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	}
	// SSL certificate
	if($url_data['scheme'] == 'https'){
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	}
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:55.0) Gecko/20100101 Firefox/55.0');
	//Timeout for connection
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	//Timeout for buffer receiving
	curl_setopt($ch, CURLOPT_TIMEOUT, 300);
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_exec($ch);
	$http_status = 0;
	if(!curl_errno($ch)){
	    $http_status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	}
	curl_close($ch);
	fclose( $fp );
	// 206 for partial download
	if (200==$http_status || 206==$http_status) {
	    return true;
	}
	return false;
    }
    
    public static function sanitize_path( $path ) {
	// Try to convert it to real path.
	if ( false !== realpath( $path ) ) {
	    $path = realpath( $path );
	}
	// Remove Windows drive for local installs if the root isn't cached yet.
	$path = preg_replace( '#^[A-Z]\:#i', '', $path );
	return wp_normalize_path( $path );
    }

    
    public static function mb_trim ($str){
	$str = preg_replace('/[\\p{Z}\\s]{2,}/u', ' ', $str);
	return preg_replace('/(^\\s+)|(\\s+$)/us', '', $str); 
    }
    
    public static function update_rewrite_rule($region = '', $s3_bucket = '', $s3_bucket_prefix = ''){
	if(!function_exists('got_mod_rewrite')){
	    require_once(ABSPATH . 'wp-admin/includes/misc.php');
	}
	$htaccess_file = ABSPATH . '.htaccess';
	$fp = null;
	if ((!file_exists($htaccess_file) && is_writable( ABSPATH )) || is_writable($htaccess_file)) {
	    if ( got_mod_rewrite() ) {
		$fp = fopen( $htaccess_file, 'r+' );
	    }
	}
	if ( !$fp ) {
	    return false;
	}
	// Attempt to get a lock. If the filesystem supports locking, this will block until the lock is acquired.
	flock( $fp, LOCK_EX );
	
	$start_marker = '# BEGIN Viocee AWS Media Library';
	$end_marker   = '# END Viocee AWS Media Library';
	$wp_marker = '# BEGIN WordPress';
	$wp_end_marker = '# END WordPress';
	
	$lines = array();
	while ( ! feof( $fp ) ) {
	    $lines[] = rtrim( fgets( $fp ), "\r\n" );
	}
	    
	$wp_lines = array();
	$found_marker = false;
	foreach ( $lines as $k => $line ) {
	    if(empty($wp_lines)){
		// check to see if there is # BEGIN VIOCEE before # BEGIN WordPress
		if ( false !== strpos( $line, $start_marker ) ) {
		    $found_marker = true;
		}
		if ( false !== strpos( $line, $wp_marker ) ) {
		    $wp_lines[] = $line;
		    if( $found_marker ){
			unset($lines[$k]);
		    } else {
			// if found no marker of # BEGIN VIOCEE, we need add one by replace # BEGIN WordPress as that
			$lines[$k] = $start_marker;
		    }
		}
	    } else {
		$wp_lines[] = $line;
		if ( false !== strpos( $line, $wp_end_marker ) ) {
		    if( $found_marker ){
			unset($lines[$k]);
		    } else {
			$lines[$k] = $end_marker;
		    }
		    break;
		} else {
		    unset($lines[$k]);
		}
	    }
	}
	    
	// Split out the existing file into the preceding lines, and those that appear after the marker
	$pre_lines = $post_lines = $existing_lines = array();
	$found_marker = $found_end_marker = false;
	foreach ( $lines as $line ) {
	    if ( ! $found_marker && false !== strpos( $line, $start_marker ) ) {
		$found_marker = true;
		continue;
	    } elseif ( ! $found_end_marker && false !== strpos( $line, $end_marker ) ) {
		$found_end_marker = true;
		continue;
	    }
	    if ( ! $found_marker ) {
		$pre_lines[] = $line;
	    } elseif ( $found_marker && $found_end_marker ) {
		$post_lines[] = $line;
	    } else {
		$existing_lines[] = $line;
	    }
	}
	if( $region && $s3_bucket && $s3_bucket_prefix ){
	    $upload_dir = wp_get_upload_dir();
	    $basedir = $upload_dir['basedir'];
	    $uploads = trim(str_replace(ABSPATH, '', $basedir), '/');
	    $rules = array();
	    $rules[] = '<IfModule mod_rewrite.c>';
	    $rules[] = 'RewriteEngine On';
	    $rules[] = 'RewriteBase /' . ($uploads ? $uploads . '/' : '');
	    $rules[] = 'RewriteCond %{REQUEST_FILENAME} !-f';
	    $rules[] = 'RewriteCond %{REQUEST_FILENAME} !-d';
	    $rules[] = 'RewriteRule ^' . ($uploads ? $uploads . '/' : '') . '(.*)$ https://' . $s3_bucket . '.s3-' . $region . '.amazonaws.com/' . $s3_bucket_prefix . '/$1 [R=301,L]';
	    $rules[] = '</IfModule>';
	    
	    // Check to see if there was a change
	    if ( $existing_lines === $rules ) {
	        flock( $fp, LOCK_UN );
	        fclose( $fp );
	        return true;
	    }
	    // Generate the new file data
	    $new_file_data = implode( "\n", array_merge(
	        $pre_lines,
	        array( $start_marker ),
	        $rules,
	        array( $end_marker ),
	        array(''), // add an empty line;
	        $wp_lines,
	        $post_lines
	    ) );
	} else {
	    $new_file_data = implode( "\n", array_merge(
	        $pre_lines,
		$wp_lines,
	        $post_lines
	    ) );
	}
	// Write to the start of the file, and truncate it to that length
	fseek( $fp, 0 );
	$bytes = fwrite( $fp, $new_file_data );
	if ( $bytes ) {
	    ftruncate( $fp, ftell( $fp ) );
	}
	fflush( $fp );
	flock( $fp, LOCK_UN );
	fclose( $fp );
	return true;
    }
    
    public static function user_role(){
	if(is_user_logged_in()) {
	    global $current_user;
	    return strtolower($current_user->roles[0]);
	}
	return '';
    }
    
    public static function log($script_file, $entries) {
	static $filename;
	if (! is_null($filename)) {
	    $upload_dir = wp_get_upload_dir();
	    $filename = $upload_dir['basedir'] . '/' . Controller::get('vaml_hidden') . '/error_log.txt';
	}
	$content = date('Y-m-d H:i:s'). '-' . $script_file . '-' . $entries . PHP_EOL;
	file_put_contents($filename, $content, FILE_APPEND);  
    }
    
    public static function full_url() {
	static $url;
	if(!$url){
	    $port = $_SERVER['SERVER_PORT'];
	    if (!is_ssl()) {
		$protocol = 'http';
	        if ('80'==$port){
		    $port = '';
		}
	    } else {
		$protocol = 'https';
		if ('443'==$port){
		    $port = '';
		}
	    }
	    
	    $url = str_replace('&amp;', '&', $protocol . "://" . $_SERVER['SERVER_NAME'] .( $port? ':'.$port : $port). $_SERVER['REQUEST_URI']);
	}
	return $url;
    }
    
    public function rrmdir($dir) {
	if (is_dir($dir)) {
	    $done = true;
	    foreach (scandir($dir) as $object) {
		if ($object != '.' && $object != '..') {
		    chmod($dir . '/' . $object, 0777); 
		    if (is_dir($dir . '/' . $object)) {
			if(!$this->rrmdir($dir . '/' . $object)){
			    $done = false;
			}
		    } else {
			if(!unlink($dir . '/' . $object)){
			    $done = false;
			}
		    }
		}
	    }
	    if($done){
		return rmdir($dir);
	    }
	    return $done;
	}
	return false;
    }
    
    public static function verify_nonce($vaml_nonce_action, $die = true) {
	if (!isset( $_REQUEST[Controller::get('vaml_nonce_name')])||!wp_verify_nonce(wp_unslash($_REQUEST[Controller::get('vaml_nonce_name')]), $vaml_nonce_action)) {
	    if($die){
		wp_die(
		    '<h1>' . __( 'Viocee AWS Media Library', 'vaml-text-lang') . '</h1>' .
		    '<p>' . __('Failed security nonce check!', 'vaml-text-lang') . '</p>',
		    403
	        );
	    }
	    return false;
	}
	return true;
    }
    
    public static function slog($message, $code = 0) {
	global $wpdb, $table_prefix;
	
	if(empty($wpdb->mu_sys_log)){
	    $tbl = 'sys_log';
	    $wpdb->mu_sys_log = $table_prefix . $tbl;
	    $wpdb->tables[] = $tbl;
	}
	$prepare = $wpdb->prepare("INSERT INTO " . $wpdb->mu_sys_log . " SET url = %s, message = %s, code = %d, time_added = UNIX_TIMESTAMP()",  self::full_url(), $message, $code);
	
	$wpdb->query($prepare);
    }
}
?>