<div class="wrap">
	<?php if($premium_link){ ?>
	<h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
	<a target="_blank" href="<?php echo esc_url($premium_link); ?>" class="page-title-action"><?php echo $text_get_premium; ?></a>
	<?php } else { ?>
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
	<?php } ?>
	<?php if ($essential_updated){ ?>
	<div class="notice notice-success is-dismissible">
	<p><?php echo $essential_updated; ?></p>
	</div>
	<?php } ?>
	<?php if ($notice_error){ ?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo $notice_error; ?></p>
	</div>
	<?php } ?>
	<?php if (!$recovery){ ?>
	<p><?php echo $s3_configured; ?></p>
	<form action="<?php echo esc_url($action_url); ?>" method="post" enctype="multipart/form-data">
		<table class="form-table">
			<tr>
				<th scope="row"><?php echo $default_settings; ?></th>
				<td>
				    <fieldset>
					    <label for="backup_upload">
						<?php if($backup_upload_field){ ?>
						<input name="backup_upload" type="checkbox" id="backup_upload" value="1" checked="checked" />
						<?php } else { ?>
						<input name="backup_upload" type="checkbox" id="backup_upload" value="1" />
						<?php } ?>
					        <?php echo $backup_upload; ?>
					    </label>
					    <p class="description"><?php echo $backup_upload_description; ?></p>
					    <br />
					    <label for="auto_upload">
						<?php if($auto_upload_field){ ?>
					        <input name="auto_upload" type="checkbox" id="auto_upload" value="1" checked="checked" />
					    <?php } else { ?>
							<input name="auto_upload" type="checkbox" id="auto_upload" value="1" />
					    <?php } ?>
						<?php echo $auto_upload; ?></label>
					    <br />
					    <p class="description"><?php echo $auto_upload_description; ?></p>
				    </fieldset>
			    </td>
		    </tr>
			<?php if($batch_upload_location){ ?>
			<tr>
				<th scope="row"><?php echo $batch_upload_location; ?></th>
				<td>
				    <input name="batch_upload_location" type="text" value="<?php echo esc_attr($location); ?>" class="regular-text" />
					<p class="description"><?php echo $batch_upload_location_description; ?></p>
				</td>
		    </tr>
			<?php } ?>
		</table>
		<?php if($very_important){ ?>
		<h2 class="title"><?php echo $very_important; ?></h2>
		<div class="viocee-notice viocee-warning">
			<p><?php echo $important_warning; ?></p>
		</div>
		<p><?php echo $delete_instruction; ?></p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="recovery_token"><?php echo $recovery_token; ?></label></th>
				<td>
					<input name="recovery_token" type="text" value="" class="regular-text" />
					<input name="recovery_token_value" type="hidden" value="<?php echo esc_attr($recovery_token_value); ?>" />
					<p class="description"><?php echo $recovery_token_description; ?></p>
				</td>
			</tr>
		</table>
		<?php } ?>
		<?php wp_nonce_field( $vaml_nonce_action, $vaml_nonce_name ); ?>
		<?php submit_button(); ?>
	</form>
	<h2 class="title"><?php echo $reset_credentials; ?></h2>
	<p><?php echo $reset_credentials_description; ?></p>
	<p><a href="<?php echo esc_url($update_credentials_href); ?>" class="button button-primary"><?php echo $update_credentials; ?></a></p>
	<?php } else { ?>
	<table class="form-table">
		<tr>
			<td colspan="2">
				<a href="<?php echo esc_url($recovery_cancel_url); ?>" class="button button-primary"><?php echo $recovery_stop; ?></a>
				<?php if($global_notice) { ?>
				&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?php echo esc_url($global_notice_url); ?>" class="button button-primary"><?php echo $global_notice; ?></a>
				<?php } ?>
			</td>
		</tr>
	</table>
	<?php } ?>
</div>