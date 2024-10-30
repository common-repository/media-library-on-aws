<?php
$slash = '/';
if('/' == $uploads){
    $slash = '';
} 
?>
<p class="viocee-help-heading"><?php _e( 'Rewrite Rules', 'vaml-text-lang'); ?></p>
<p><?php echo _e( 'If your site runs on Nginx or use Nginx as reverse proxy, you have to manually add the follwing code to the end of location blocks of the Nginx configuration.', 'vaml-text-lang'); ?></p>
<pre class="viocee-syntax-highlight">
location <?php echo $slash . $uploads; ?> {
    try_files $uri @aws;
}
	
location @aws {
    rewrite ^<?php echo $slash . $uploads . $slash; ?>(.*)$ https://<?php echo $s3_bucket; ?>.s3-<?php echo $region; ?>.amazonaws.com/<?php echo $s3_bucket_prefix; ?>/$1 redirect;
}
</pre>
<br />				