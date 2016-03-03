<?php

namespace Drupal\Tests\s3filesystem\Unit;

/**
 * Class S3ConfigFactory
 *
 * @author andy.thorne@timeinc.com
 */
class S3ConfigFactory {
  static function buildConfig(array $config = []) {
    return array(
      's3filesystem.settings' => $config + array(
          's3.bucket' => 'test-bucket',
          's3.keyprefix' => 'testprefix',
          's3.region' => 'eu-west-1',
          's3.force_https' => FALSE,
          's3.ignore_cache' => FALSE,
          's3.refresh_prefix' => '',
          's3.custom_host.enabled' => FALSE,
          's3.custom_host.hostname' => NULL,
          's3.custom_cdn.enabled' => FALSE,
          's3.custom_cdn.domain' => 'assets.domain.co.uk',
          's3.custom_cdn.http_only' => TRUE,
          's3.presigned_urls' => array(),
          's3.saveas' => array(),
          's3.torrents' => array(),
          's3.custom_s3_host.enabled' => FALSE,
          's3.custom_s3_host.hostname' => '',
          'aws.use_instance_profile' => FALSE,
          'aws.default_cache_config' => '/tmp',
          'aws.access_key' => 'INVALID',
          'aws.secret_key' => 'INVALID',
          'aws.proxy.enabled' => FALSE,
          'aws.proxy.host' => 'proxy:8080',
          'aws.proxy.connect_timeout' => 10,
          'aws.proxy.timeout' => 20,
        )
    );
  }
}
