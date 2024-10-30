=== Viocee AWS Media Library ===
Contributors: Kevin Cheng
Donate link: https://www.viocee.com/
Tags: AWS, S3, Viocee, cloud, media on s3, files on s3, media on aws, files on aws, upload to s3, upload to aws
Requires at least: 4.0
Tested up to: 4.8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The plugin will silently host your WordPress media library on AWS (Amazon Web Services) S3(Amazon Simple Storage Service). If with the premium version of this plugin installed, the media library of WordPress will be enhenced with upload of large files as well as batch addition of files from user specified server location.

== Description ==
The plugin relies on Apache server's mod_rewrite and AWS as a service. If your WordPress site runs on Apache server with mod_rewrite enabled, while you have an account with AWS, you can use it.

Hosted on AWS S3, besides the unlimited storage, your WordPress media library could alse be CDN delivered for best significant performance with Amazon CloudFront.

Why Viocee AWS Media Library?
Viocee AWS Media Library's philosophy is Rest as Concurrency.

As php is single_threaded blocking process by web(http) request, a considerable slowdown is inevitable if the uploaded media file will be again uploaded to AWS S3 from WordPress's default space.
However, with the idea of Rest as Concurrency, the plugin will just invoke a restful api call for the transference of uploaded media files to AWS S3, and without waiting for the response. Even if a network failure occurs, the transference of uploaded media files to AWS S3 will be done eventually with periodic cron jobs.

The plugin just hosts your WordPress's media library on AWS S3, while the user interface and database keep intact. If a media file found from its default space, serve it, or else serve from AWS S3 instead, it's a trick by mod_rewrite of Apache server.

With the premium version of this plugin installed, you will get more. See major features.

Major features in Viocee AWS Media Library include:

* Automatically transfer uploaded media files to AWS S3 in restful process.
* For those old media files AWS S3 not hosted yet, periodic cron jobs will be invoked for the transference of them to AWS S3 if auto upload configured as true from the plugin's setting page.
* Local backup for media files hosted on AWS S3, if you wish to keep a copy of the media files hosted by AWS S3 on your own server.
* Media files can be recover from AWS S3 to their default space by configured cron jobs before you try to uninstall the plugin with the notice of recovery process.
* Large file (less than 5G, limited by AWS S3) uploading (premium version only).
* Batch addition of files from user specified server location to the media library(premium version only).
* The plugin requires Apache server with mod-rewrite enabled which, fitting for most cases of WordPress site.
* The plugin relies AWS as a service, you must have an account with AWS.
* 100% network failure tolerance.

== Installation ==
1. Download the plugin and unzip it.
2. Upload the folder viocee-aws/ to your /wp-content/plugins/ folder.
3. Activate the plugin from your WordPress admin panel.
4. Installation finished.

Go to the plugin's setting page, have your AWS Access Key ID and AWS Secret Access Key filled, choose a location nearest the host server of your WordPress site, specified a bucket and a bucket prefix, the plugin is ready for use with all these set.
Important: It is highly recommended that your have an exclusive S3 bucket responded alone by a specific IAM user for your WordPress site. The plugin needs no other extra permissions beyond your specified a bucket and a bucket prefix. It is very safe!

== Screenshots ==
None. :)

== Changelog ==

= 1.0.1 =
* readme.txt update

= 1.0.0 =
* Initial release