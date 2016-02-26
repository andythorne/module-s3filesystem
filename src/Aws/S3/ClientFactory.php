<?php

namespace Drupal\s3filesystem\Aws\S3;

use Aws\S3\S3Client;
use Drupal\Core\Config\Config;
use Drupal\s3filesystem\Exception\S3FileSystemException;

/**
 * Class ClientFactory
 *
 * @package   Drupal\s3filesystem\Aws\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class ClientFactory {
  public static function create(Config $s3filesystemConfig = NULL) {
    if (!$s3filesystemConfig instanceof Config) {
      $s3filesystemConfig = \Drupal::config('s3filesystem.settings');
    }

    // if there is no bucket, don't use the stream
    if (!$s3filesystemConfig->get('s3.bucket')) {
      throw new S3FileSystemException();
    }

    $use_instance_profile = $s3filesystemConfig->get('aws.use_instance_profile');
    $access_key           = $s3filesystemConfig->get('aws.access_key');
    $secret_key           = $s3filesystemConfig->get('aws.secret_key');
    $default_cache_config = $s3filesystemConfig->get('aws.default_cache_config');

    // Create and configure the S3Client object.
    $config = [
      'region'  => $s3filesystemConfig->get('s3.region'),
      'version' => 'latest',
      'http' => [
        /*'sink' => new SeekableCachingStream(
          new Stream(fopen('php://temp', 'r+'))
        ),*/
      ],
    ];
    if ($use_instance_profile) {
      $config['default_cache_config'] = $default_cache_config;
    }
    else {
      $config['credentials'] = [
        'key'    => $access_key,
        'secret' => $secret_key,
      ];
    }

    if ($s3filesystemConfig->get('aws.proxy.enabled')) {
      $config['request.options'] = [
        'proxy'           => $s3filesystemConfig->get('aws.proxy.host'),
        'timeout'         => $s3filesystemConfig->get('aws.proxy.timeout'),
        'connect_timeout' => $s3filesystemConfig->get('aws.proxy.connect_timeout')
      ];
    }

    $s3 = new S3Client($config);

    if ($s3filesystemConfig->get('s3.custom_host.enabled') && $s3filesystemConfig->get('s3.custom_host.hostname')) {
      $s3->setBaseURL($s3filesystemConfig->get('s3.custom_host.hostname'));
    }

    return $s3;
  }

} 
