<div class="wrap">
	<?php if($premium_link && !$aws_credentials){ ?>
	<h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
	<a target="_blank" href="<?php echo esc_url($premium_link); ?>" class="page-title-action"><?php echo $text_get_premium; ?></a>
	<?php } else { ?>
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
	<?php } ?>
	<?php if ($ssl_warning) { ?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<?php if (!$aws_credentials) { ?>
			<?php _e(sprintf(__('None SSL posting for highly confidential information is not recommended. If your site can use SSL, click to go %s page for this action!', 'vaml-text-lang'), $link_ssl)); ?>
		    <?php } else { ?>
			<?php _e(sprintf(__('Your are going to reset your AWS account credentials. None SSL posting for highly confidential information is not recommended. If your site can use SSL, click to go %s page for this action!', 'vaml-text-lang'), $link_ssl)); ?>
		    <?php } ?>
		</p>
	</div>
	<?php } ?>
	<?php if ($post_error) { ?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo $post_error; ?></p>
	</div>
	<?php } ?>
	<?php if (!$aws_credentials) { ?>
	<p><?php _e('If you already have credentials by your AWS account, Please fill the following blanks before integrating your current site with Viocee AWS Media Library.', 'vaml-text-lang' ); ?></p>
	<p><?php _e('It is highly recommended that your have an exclusive S3 bucket responded alone by a specific IAM user for this current WordPress site.', 'vaml-text-lang' ); ?></p>
	<p><?php _e('Please click the right-top help tab for more details before submitting.', 'vaml-text-lang' ); ?></p>
	<?php } else { ?>
	<p><?php _e('Your AWS credentials can be reset, but as for your AWS S3 settings, such as bucket, bucket_prefix, they are fixed unless you reinstall this plugin.', 'vaml-text-lang' ); ?></p>
	<?php } ?>
	<form action="<?php echo esc_url($action_url); ?>" method="post" enctype="multipart/form-data">
	<table class="form-table permalink-structure">
		<tr>
			<th scope="row"><label for="aws_access_key_id"><?php _e( 'AWS Access Key ID', 'vaml-text-lang' ); ?></label></th>
			<td>
				<input type="text" name="aws_access_key_id" id="aws_access_key_id" value="<?php echo esc_attr($aws_access_key_id); ?>" class="regular-text" />
			</td>
        </tr>
		<tr>
			<th scope="row"><label for="aws_secret_access_key"><?php _e( 'AWS Secret Access Key', 'vaml-text-lang' ); ?></label></th>
			<td>
				<input type="text" name="aws_secret_access_key" id="aws_secret_access_key" value="<?php echo esc_attr($aws_secret_access_key); ?>" class="regular-text" />
			</td>
        </tr>
		<?php if (!$aws_credentials) { ?>
		<tr>
			<th colspan="2" align="left"><?php _e('The following settings can not be reset or altered if submitted successfully. You have to be careful!', 'vaml-text-lang' ); ?></th>
        </tr>
		<tr>
			<th scope="row"><label for="aws_secret_access_key"><?php _e( 'Server Region', 'vaml-text-lang' ); ?></label></th>
			<td>
				<select name="region" id="region">
				<?php foreach ($regions as $region => $region_name) { ?>
				<?php if ($region == $region_select) { ?>
				<option value="<?php echo esc_attr($region);?>" selected="selected"><?php echo $region_name;?></option>
				<?php } else { ?>
				<option value="<?php echo esc_attr($region);?>"><?php echo $region_name;?></option>
				<?php } ?>
				<?php } ?>
			        </select>
				<p class="description"><?php _e('Select one on which to deploy your media library on S3', 'vaml-text-lang'); ?></p>
			</td>
        </tr>
		<tr>
			<th scope="row"><label for="s3_bucket"><?php _e( 'S3 Bucket', 'vaml-text-lang' ); ?></label></th>
			<td>
				<input type="text" name="s3_bucket" id="s3_bucket" value="<?php echo esc_attr($s3_bucket); ?>" class="regular-text" />
				<p class="description"><?php _e('It\'s your sole responsibility to create such an exclusive bucket. The plugin needs no other extra permissions', 'vaml-text-lang'); ?></p>
			</td>
        </tr>
		<tr>
			<th scope="row"><label for="s3_bucket_prefix"><?php _e( 'Prefix', 'vaml-text-lang' ); ?></label></th>
			<td>
				<input type="text" name="s3_bucket_prefix" id="s3_bucket_prefix" value="<?php echo esc_attr($s3_bucket_prefix); ?>" class="regular-text" />
				<p class="description"><?php _e('www_abc_com, for example. Your media library files shall be put under sucn prefix ( folder )', 'vaml-text-lang'); ?></p>
			</td>
        </tr>
		<?php } ?>
	</table>
	<?php if ($ssl_warning) { ?>
	<br />
	<br />
	<div>
	<input name="none_ssl_agree" type="checkbox" id="none_ssl_agree" value="1" /> <?php _e('If you know the risk of None SSL posting, and you\'d rather post from here, please check the box!', 'vaml-text-lang'); ?>
	</div>
	<p class="submit viocee-hide"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr('Submit'); ?>"  /></p>
	<script type="text/javascript">
	    (function($){
			$('input#none_ssl_agree').click(function (event) {
				if (this.checked) {
					$('p.submit').removeClass('viocee-hide');
				} else {
					$('p.submit').addClass('viocee-hide');
				}
			});
		})(jQuery)
	</script>
	<?php } else { ?>
	<?php wp_nonce_field( $vaml_nonce_action, $vaml_nonce_name ); ?>
	<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr('Submit'); ?>"  /></p>
	<?php } ?>
	</form>
</div>