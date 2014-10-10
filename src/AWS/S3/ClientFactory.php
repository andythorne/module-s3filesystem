<?php

namespace Drupal\s3filesystem\AWS\S3;

use Aws\S3\S3Client;
use Drupal\Core\Config\Config;
use Drupal\Core\Link;
use Drupal\s3filesystem\Exception\AWS\S3\AwsClientNotFoundException;
use Drupal\s3filesystem\Exception\AWS\S3\AwsCredentialsInvalidException;
use Drupal\s3filesystem\Exception\S3FileSystemException;

/**
 * Class ClientFactory
 *
 * @package   Drupal\s3filesystem\AWS\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class ClientFactory {
  public static function t($string, array $args = array(), array $options = array()) {
    $translator = \Drupal::translation();

    return $translator->translate($string, $args, $options);
  }

  public static function l(Link $link) {
    $linker = \Drupal::linkGenerator();

    return $linker->generateFromLink($link);
  }

  public static function create(Config $s3filesystemConfig = NULL) {
    if (!$s3filesystemConfig instanceof Config) {
      $s3filesystemConfig = \Drupal::config('s3filesystem.settings');
    }

    $awsConfig = $s3filesystemConfig->get('aws');
    $s3Config  = $s3filesystemConfig->get('s3');

    // if there is no bucket, dont use the stream
    if (!$s3Config['bucket']) {
      throw new S3FileSystemException();
    }

    $use_instance_profile = $awsConfig['use_instance_profile'];
    $access_key           = $awsConfig['access_key'];
    $secret_key           = $awsConfig['secret_key'];
    $default_cache_config = $awsConfig['default_cache_config'];


    if (!class_exists('Aws\S3\S3Client')) {
      throw new AwsClientNotFoundException();
    }
    elseif (!$use_instance_profile && (!$secret_key || !$access_key)) {
      throw new AwsCredentialsInvalidException("Secret and Access key must be set");
    }
    elseif ($use_instance_profile && empty($default_cache_config)) {
      throw new AwsCredentialsInvalidException("You are attempting to use instance profile credentials but you have not set a default cache location.");
    }

    // Create and configure the S3Client object.
    $config = array();
    if ($use_instance_profile) {
      $config['default_cache_config'] = $default_cache_config;
    }
    else {
      $config['key']    = $access_key;
      $config['secret'] = $secret_key;
    }

    if ($awsConfig['proxy']['enabled']) {
      $config['request.options'] = array(
        'proxy'           => $awsConfig['proxy']['host'],
        'timeout'         => $awsConfig['timeout'],
        'connect_timeout' => $awsConfig['connect_timeout']
      );
    }

    $s3 = S3Client::factory($config);
    if (!empty($s3Config['region'])) {
      $s3->setRegion($s3Config['region']);
    }

    if ($s3Config['custom_host']['enabled'] && !empty($s3Config['custom_host']['hostname'])) {
      $s3->setBaseURL($s3Config['custom_host']['hostname']);
    }

    return $s3;
  }

} 
