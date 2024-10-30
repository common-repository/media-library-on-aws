<?php
if(isset($s3_bucket)){
    $arn = $s3_bucket . '/' . $s3_bucket_prefix . '/*';
} else {
    $arn = '<span>your_bucket/your_prefix/</span>*';
}
?>
<p class="viocee-help-heading"><?php _e( 'Permissions Code', 'vaml-text-lang'); ?></p>
<?php if(isset($s3_bucket)){ ?>
<p><?php echo (sprintf(__( 'Current configuration for AWS S3: Server Region ( %s ) , S3 Bucket ( %s ) , Prefix ( %s ). They can\'t be reconfigured. The following permissions can be applied to a specified IAM user for current configuration.', 'vaml-text-lang'), $region, $s3_bucket, $s3_bucket_prefix)); ?></p>
<?php } else { ?>
<p><?php echo _e( 'Please replace the red high-lighted with your own bucket and prefix.', 'vaml-text-lang'); ?></p>
<?php } ?>
<pre class="viocee-syntax-highlight">
{
    "Version": "2012-10-17",
	"Statement": [
	    {
		"Sid":"vamlvioceeawsmedialibrary",
		"Effect": "Allow",
		"Action": [
		    "s3:DeleteObject",
		    "s3:DeleteObjectTagging",
		    "s3:PutObject",
		    "s3:PutObjectAcl",
		    "s3:PutObjectTagging",
		    "s3:GetObject",
		    "s3:GetObjectAcl",
		    "s3:GetObjectTagging",
		    "s3:AbortMultipartUpload",
		    "s3:ListMultipartUploadParts"
		],
		"Resource": [
		    "arn:aws:s3:::<?php echo $arn; ?>"
		]
	    }
	]
}
</pre>
<br />				