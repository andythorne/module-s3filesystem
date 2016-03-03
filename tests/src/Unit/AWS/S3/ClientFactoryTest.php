<?php

namespace Drupal\Tests\s3filesystem\Unit\Aws\S3;

use Drupal\s3filesystem\Aws\S3\ClientFactory;
use Drupal\Tests\s3filesystem\Unit\ContainerAwareTestCase;

/**
 * Class Test
 *
 * @author andy.thorne@timeinc.com
 * @group s3filesystem
 */
class ClientFactoryTest extends ContainerAwareTestCase {

  public function testCreateInstanceProfile() {
    $this->setupConfigFactory(
      [
        'aws.use_instance_profile' => TRUE,
        'aws.default_cache_config' => '/tmp/cache'
      ]
    );
    $client = ClientFactory::create(
      function ($config) {
        $this->assertArrayHasKey('credentials', $config);
        $this->assertEquals(
          '/tmp/cache',
          $config['default_cache_config']
        );
      }
    );

    $this->assertInstanceOf('\Aws\S3\S3Client', $client);
  }

  public function testCreateCredentialKeys() {
    $this->setupConfigFactory(
      [
        'aws.use_instance_profile' => FALSE,
        'aws.access_key' => '123',
        'aws.secret_key' => 'abc',
      ]
    );
    $client = ClientFactory::create(
      function ($config) {
        $this->assertArrayHasKey('credentials', $config);
      }
    );

    $this->assertInstanceOf('\Aws\S3\S3Client', $client);
  }

  public function testCreateNoBucket() {
    $this->setExpectedException(
      '\Drupal\s3filesystem\Exception\S3FileSystemException'
    );

    $this->setupConfigFactory(
      [
        's3.bucket' => NULL,
      ]
    );

    ClientFactory::create();
  }

  public function testCreateWithProxy() {
    $this->setupConfigFactory(
      [
        'aws.proxy.enabled' => TRUE,
        'aws.proxy.host' => 'proxy:8080',
        'aws.proxy.timeout' => 10,
        'aws.proxy.connect_timeout' => 5,
      ]
    );

    $client = ClientFactory::create(
      function ($config) {
        $this->assertArrayHasKey('request.options', $config);
        $this->assertArraySubset(
          [
            'proxy' => 'proxy:8080',
            'timeout' => 10,
            'connect_timeout' => 5,
          ],
          $config['request.options']
        );
      }
    );

    $this->assertInstanceOf('\Aws\S3\S3Client', $client);
  }

  public function testCreateWithCustomS3Host() {
    $this->setupConfigFactory(
      [
        's3.custom_host.enabled' => TRUE,
        's3.custom_host.hostname' => 'http://customhost',
      ]
    );

    $client = ClientFactory::create(
      function ($config) {
        $this->assertArrayHasKey('base_url', $config);
        $this->assertEquals('http://customhost', $config['base_url']);
      }
    );

    $this->assertInstanceOf('\Aws\S3\S3Client', $client);
  }

}
