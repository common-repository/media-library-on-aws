<?php
namespace VAML_Viocee_AWS_Media_Library;

class Mutex {
    protected static $instance;
    private static $lockers = array();
    
    public static $mutex;
    // reserved lockers;
    public static $ttl_reader = array('ttl_reader');
    // segment to decrease time function
    public static $time_segment = 3600;
    
    public static function get_instance($mutex) {
	if (null === self::$instance) {
	    self::$instance = new self();
	    if (!file_exists ($mutex . '/ttl') && wp_mkdir_p($mutex . '/ttl')){
		@chmod($mutex . '/ttl', 0755);
	    } 
	    if (is_readable($mutex . '/ttl') && is_writable($mutex . '/ttl')) {
		self::$mutex = $mutex;
	    } else {
		die(sprintf(__( 'Viocee AWS Media Library does\'t have write permissions into %s!', 'vaml-text-lang'), $mutex . '/ttl'));
	    }
	    register_shutdown_function(array(self::$instance, 'shutdown_free'));
	}
	return self::$instance;
    }
    
    private static function get_locker($name, $timeout = 10) {
	try{
	    return self::wt_add($name, $timeout);
	} catch(\Exception $e) {
	    throw new \Exception($e->getMessage());
	}
    }
    
    private static function free_locker($locker) {
	if(isset(self::$lockers[$locker])){
	    if(is_resource(self::$lockers[$locker])){
		flock( self::$lockers[$locker], LOCK_UN );
		fclose( self::$lockers[$locker] );
	    }
	    unset(self::$lockers[$locker]);
	    return true;
	}
	return false;
    }
    
    public static function blocker($name, $timeout = 10) {
	try{
	    return self::get_locker($name, $timeout);
	} catch(\Exception $e) {
	    throw new \Exception($e->getMessage());
	}
    }
    
    public static function release_blocker($locker) {
	return self::free_locker($locker);
    }
    
    public static function set_ttl($key, $duration, $force = false) {
	if(!self::locker_validate($key)){
	    throw new \Exception("Invalid Locker! Please use alphanumeric characters, underscores and dashes for locker with length less than 129.");
	    return;
	}
	try{
	    // make it a past time to prevent touch a ttl in future;
	    $t = time() - 86400;
	    $locker = self::get_locker('ttl_reader');
	    $path = self::$mutex .'/ttl/' . $key . '.ttl';
	    $expired = true;
	    if (file_exists($path)){
		// not expired, the ttl key is living, meaning taken
		if (false !== ($mt = filemtime($path))&& $mt > $t ){
		    $expired = false;
		} 
	    }
	    // file not exists, or expired, or filemtime is false, or if we must force set a ttl
	    if(($expired || $force) && !touch($path, ($t + $duration))){
		throw new \Exception("Failed to create TTL '" . $key . "' into '"  . dirname($path) .  "'!");
	    }
	    self::free_locker($locker);
	    return ($expired || $force);
	} catch(\Exception $e) {
	    throw new \Exception($e->getMessage());
	}
    }
    
    public static function clear_ttl($key) {
	try{
	    $locker = self::get_locker('ttl_reader');
	    $dir = self::$mutex .'/ttl';
	    $key = $key . '.ttl';
	    if($handler = opendir($dir)) {
		$t = time() - 86400;
		while (($f = readdir($handler)) !== FALSE) {
		    if ($f != '.' && $f != '..') {
			$mt = filemtime($dir . '/' . $f);
			if(($f == $key)||(false===$mt)||$mt <= $t){
			    unlink($dir . '/' . $f);
			}
		    }
		}
		closedir($handler);
	    }
	    self::free_locker($locker);
	} catch(\Exception $e) {
	    throw new \Exception($e->getMessage());
	}
    }
    
    // $group: which task gourp queue
    // $timeout: wait for how long;
    // $length: max concurrent process number for such group. this parameter should have a consistent value across processes, or it makes no sense.
    public static function wt_add($group, $timeout = 10, $length = 1) {
	if(is_null(self::$mutex)){
	    throw new \Exception("Class Mutex must have a directory specified by your with write permission for file lockers!");
	    return;
	}
	if($length < 1){
	    $length = 512;
	}
	if(in_array($group, self::$ttl_reader)){
	    $base = md5($group . strval($length));
	} else {
	    if(self::locker_validate($group)){
		// set a base name for task count, $group and $length as an integrated string
		$base = $group . '-' . $length;
		$current = self::mu_time();
		$aborted = array();
	    } else {
		throw new \Exception("Invalid Locker! Please use alphanumeric characters, underscores and dashes for locker with length less than 129.");
		return;
	    }
	}
	$t = time();
	$i = 0;
	$count = count(self::$lockers);
	$key = '';
	
	// loop to get the available non blocking lock, and the loop it self servers as a blocking lock
	while(true){
	    if($i > ($length - 1)){
		$i = 0;
	    }
	    if(isset($current)){
		// start from last mu_time;
		if(!in_array($i, $aborted)){
		    $locker = ($current - 1) . '-' . $base . '-' . $i . '.lock';
		} else {
		    $locker = $current . '-' . $base . '-' . $i . '.lock';
		}
	    } else {
		$locker = $base . '.lock';
	    }
	    $fp = fopen(self::$mutex .'/'. $locker, 'c+');
	    if ($fp){
		if (flock($fp, LOCK_EX|LOCK_NB)) {
		    if(isset($current)){
			if($locker == $current . '-' . $base . '-' . $i . '.lock'){
			    // while loop delay, and might encounter mu_time change;
			    $mt = self::mu_time();
			    if($current == $mt){
				// if as the initial setting of $current;
				$key = md5($locker).$count;
				self::$lockers[$key] = $fp;
				break;
			    } else {
				// if a new mu_time, change the initial setting(better)
				// or recursively calling this function;
				$current = $mt;
			    }
			} else {
			    $gc = '';
			    if($size = filesize(self::$mutex .'/'. $locker)){
				$gc = fread($fp, $size);
			    }
			    if('#' != $gc){
				//discart by inserting a '#'
				if ( fwrite( $fp, '#') ) {
				    $gc = '#';
				    ftruncate( $fp, ftell( $fp ) );
				    $aborted[] = $i;
				}
				fflush( $fp );
			    } else {
				$aborted[] = $i;
			    }
			    if('#' == $gc){
				// stay at this position
				$i = $i - 1;
			    }
			}
		    } else {
			$key = md5($locker).$count;
			self::$lockers[$key] = $fp;
			break;
		    }
		} 
		if(is_resource($fp)){
		    flock( $fp, LOCK_UN );
		    fclose( $fp );
		}
		// check if time out
		if(time() > $t + $timeout){
		    if($length > 1) {
			throw new \Exception("Timeout for wait group '" . $group . "'!");
		    } else {
			if(in_array($group, self::$ttl_reader)){
			    throw new \Exception("Timeout for ttl locker '" . $group . "'!");
			} else {
			    throw new \Exception("Timeout for locker '" . $group . "'!");
			}
		    }
		    break;
		}
	    } else {
		if($length > 1) {
		    throw new \Exception("Failed to create wait group '" . $group . "' into '" . self::$mutex . "'!");
		} else {
		    if(in_array($group, self::$ttl_reader)){
			throw new \Exception("Failed to create ttl locker '" . $group . "' into '" . self::$mutex . "'!");
		    } else {
			throw new \Exception("Failed to create locker '" . $group . "' into '" . self::$mutex . "'!");
		    }
		}
		break;
	    }
	    $i++;
	}
	if($key){
	    return $key;
	}
    }
    
    public static function wt_remove($pid) {
	return self::free_locker($pid);
    }
    
    //validate to see if it is to make a valid file name;
    private static function locker_validate($base) {
	if(preg_match('/^[a-z0-9]*(?:(_|-)[a-z0-9]+)*$/', $base) && strlen($base) < 129 ){
	    return true;
	}
	return false;
    }
    
    public static function mu_time($time_segment = null) {
	if(is_null($time_segment)){
	    $time_segment = self::$time_segment;
	}
	if($time_segment <= 3600 || $time_segment > 15552000){
	    // set a valid value
	    $time_segment = 3600;
	}
	return floor(time()/$time_segment);
    }
    
    public static function trash_lockers(){
	if(!is_null(self::$mutex)){
	    if($handler = opendir(self::$mutex)) {
		while (false !== ($f = readdir($handler))) {
		    if ($f != '.' && $f != '..' && $f != 'ttl') {
			$parts = explode('-', $f);
			if(count($parts) > 1 && (self::mu_time() - intval($parts[0])) > 1){
			    unlink(self::$mutex . '/' . $f);
			}
		    }
		}
	        closedir($handler);
	    }
	}
    }
    
    public static function shutdown_free() {
	if(is_array(self::$lockers)){
	    foreach(self::$lockers as $locker){
		if(is_resource($locker)){
		    flock( $locker, LOCK_UN );
		    fclose( $locker );
		}
	    }
	}
    }
}
?>