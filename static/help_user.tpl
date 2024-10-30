<p class="viocee-help-heading"><?php _e('Create an Exlusive User', 'vaml-text-lang'); ?></p>
<ul class="viocee-help-list">
	<li><?php _e( 'From your AWS account console, click "Security, Identity & Compliance/IAM" to enter', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Pick "Users" in the left column and then click "Add user" button', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Enter user name and pick "Programmatic access" as AWS access type, and then go next', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Just pick "Attach existing policies directly", and then go next', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Ignore warning "This user has no permissions" and click "Create user" button', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'This will be your only chance to have the Access Key ID and Secret Access Key. Either copy, paste, and save them, or download "credentials.csv". Once you\'ve saved the creds, close that page', 'vaml-text-lang'); ?></li>
</ul>
<p class="viocee-help-heading"><?php _e( 'Attach Exclusive Permissions', 'vaml-text-lang'); ?></p>
<ul class="viocee-help-list">
	<li><?php _e( 'Click the user you just created from the list of users', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Click "Add inline policy" under the "Permissions" tab', 'vaml-text-lang'); ?></li>
	<li><?php _e( 'Pick "Custom Policy" and then click "select" button', 'vaml-text-lang'); ?></li>
	<?php if(isset($s3_bucket)){ ?>
	<li><?php _e( 'Fill "Policy Name" and copy the Permissions Code into the textarea as "Policy Document"', 'vaml-text-lang'); ?></li>
	<?php } else { ?>
	<li><?php _e( 'Fill "Policy Name" and copy the Permissions Code into the textarea as "Policy Document". Please replace the red high-lighted with your own bucket and prefix', 'vaml-text-lang'); ?></li>
	<?php } ?>
	<li><?php _e( 'Check "Use autoformatting for policy editing" box and click "Apply Policy" button', 'vaml-text-lang'); ?></li>
</ul>
<br />				