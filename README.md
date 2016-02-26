# S3 Filesystem

S3 File System (s3filesystem) provides an additional file system to your drupal site,
alongside the public and private file systems, which stores files in Amazon's
Simple Storage Service (S3) (or any S3-compatible storage service). You can set
your site to use S3 File System as the default, or use it only for individual
fields. This functionality is designed for sites which are load-balanced across
multiple servers, as the mechanism used by Drupal's default file systems is not
viable under such a configuration.

# Dependencies and Other Requirements
- AWS SDK for PHP 3 (library) = http://aws.amazon.com/sdkforphp/
- PHP >=5.5 is required. The AWS SDK will not work on earlier versions.
- Your PHP must be configured with "allow_url_fopen = On" in your php.ini file.
  Otherwise, PHP will be unable to open files that are in your S3 bucket.

# Installation

1. Install AWS SDK via composer:
   ```json
   {
       "require": { "aws/aws-sdk-php": "^3.13" }
   }
   ```

2. Add the module to your modules folder


# Configuration
1. Configure your setttings for S3 File System (including your S3 bucket name) at
   `/admin/config/media/s3filesystem/settings`, or override the $settings array in your
   settings.php file

2. Import your images into the drupal's S3 cache by running `/admin/config/media/s3filesystem/actions`.
   This will copy the filenames and attributes for every
   existing file in your S3 bucket into Drupal's database. This can take a
   significant amount of time for very large buckets (thousands of files).

3. Switch Drupal's filesystem to S3 by visiting `/admin/config/media/file-system` and set the
   "Default download method" to "AWS S3 file storage stream wrapper (Provided by AWS SDK)"
   -- and/or --
   Add a field of type File, Image, etc and set the "Upload destination" to
   "AWS S3 file storage stream wrapper (Provided by AWS SDK)" in the "Field Settings" tab.

   This will configure your site to store *uploaded* files in S3. Files which your
   site creates automatically (such as aggregated CSS) will still be stored in the
   public filesystem, because Drupal is hard-coded to use public:// for such
   files. A future version of S3 File System *may* add support for storing these
   files in S3, but it's currently uncertain whether Drupal is designed in a way
   that will make this possible.
