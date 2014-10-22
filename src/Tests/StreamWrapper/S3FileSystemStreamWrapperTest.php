<?php

namespace Drupal\S3FileSystem\Tests\StreamWrapper;

use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\s3filesystem\StreamWrapper\S3StreamWrapper;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;


/**
 * Class S3FileSystemStreamWrapperTest
 *
 * @author      Andy Thorne <andy.thorne@timeinc.com>
 * @copyright   Time Inc (UK) 2014
 *
 * @name        S3FileSystem
 * @group       Ensure that the remote file system functionality provided by S3 File System works correctly.
 * @description S3 File System
 */
class S3FileSystemStreamWrapperTest extends UnitTestCase {
  /**
   * @param array $methods
   *
   * @return S3StreamWrapper
   */
  protected function getWrapper(array $methods = NULL, \Closure $configClosure = NULL) {
    $wrapper = $this->getMockBuilder('Drupal\s3filesystem\StreamWrapper\S3StreamWrapper')
      ->disableOriginalConstructor()
      ->setMethods($methods)
      ->getMock();

    $s3Client      = $this->getMockBuilder('Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->getMock();
    $drupalAdaptor = new DrupalAdaptor($s3Client);

    // the flattened config array
    $testConfig = array(
      's3filesystem.settings' => array(
        's3.bucket'                  => 'test-bucket',
        's3.keyprefix'               => 'testprefix',
        's3.region'                  => 'eu-west-1',
        's3.force_https'             => FALSE,
        's3.ignore_cache'            => FALSE,
        's3.refresh_prefix'          => '',
        's3.custom_host.enabled'     => FALSE,
        's3.custom_host.hostname'    => NULL,
        's3.custom_cdn.enabled'      => FALSE,
        's3.custom_cdn.domain'       => 'assets.domain.co.uk',
        's3.custom_cdn.http_only'    => TRUE,
        's3.presigned_urls'          => array(),
        's3.saveas'                  => array(),
        's3.torrents'                => array(),
        's3.custom_s3_host.enabled'  => FALSE,
        's3.custom_s3_host.hostname' => '',
        'aws.use_instance_profile'   => FALSE,
        'aws.default_cache_config'   => '/tmp',
        'aws.access_key'             => 'INVALID',
        'aws.secret_key'             => 'INVALID',
        'aws.proxy.enabled'          => FALSE,
        'aws.proxy.host'             => 'proxy:8080',
        'aws.proxy.connect_timeout'  => 10,
        'aws.proxy.timeout'          => 20,
      )
    );

    if ($configClosure instanceof \Closure) {
      $configClosure($testConfig);
    }

    $config = $this->getConfigFactoryStub($testConfig)
      ->get('s3filesystem.settings');


    if (!$testConfig['s3filesystem.settings']['s3.custom_cdn.enabled']) {
      $s3Client->expects($this->any())
        ->method('getObjectUrl')
        ->willReturn('region.amazonaws.com/path/to/test.png');
    }

    $mimeTypeGuesser = $this->getMock('Drupal\Core\File\MimeType\MimeTypeGuesser');

    $request = new Request();

    /** @var $wrapper S3StreamWrapper */
    $wrapper->setUp(
      $request,
      $drupalAdaptor,
      $s3Client,
      $config,
      $mimeTypeGuesser,
      new NullLogger()
    );

    return $wrapper;
  }

  public function testSetUriWithPrefix() {
    $prefix  = 'testprefix';
    $wrapper = $this->getWrapper(NULL, function (&$config) use ($prefix) {
      $config['s3filesystem.settings']['s3.keyprefix'] = $prefix;
    });

    $wrapper->setUri('s3://test.png');
    $this->assertEquals('s3://' . $prefix . '/test.png', $wrapper->getUri());
  }

  public function testSetUriWithNoPrefix() {
    $wrapper = $this->getWrapper(NULL, function (&$config) {
      $config['s3filesystem.settings']['s3.keyprefix'] = NULL;
    });

    $wrapper->setUri('s3://test.png');
    $this->assertEquals('s3://test.png', $wrapper->getUri());
  }

  public function testExternalUrlWithCustomCDN() {
    $wrapper = $this->getWrapper(NULL, function (&$config) {
      $config['s3filesystem.settings']['s3.custom_cdn.enabled'] = TRUE;
      $config['s3filesystem.settings']['s3.custom_cdn.hostname'] = 'assets.domain.co.uk';
    });

    $wrapper->setUri('s3://test.png');
    $url = $wrapper->getExternalUrl();
    $this->assertEquals('http://assets.domain.co.uk/testprefix/test.png', $url);
  }

  public function testExternalUrl() {
    $wrapper = $this->getWrapper();

    $wrapper->setUri('s3://test.png');
    $url = $wrapper->getExternalUrl();
    $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
  }

  public function testExternalUrlWithTorrents() {
    $wrapper = $this->getWrapper(NULL, function (&$config) {
      $config['s3filesystem.settings']['s3.torrents'] = array(
        'torrent/'
      );
    });

    $wrapper->setUri('s3://torrent/test.png');
    $url = $wrapper->getExternalUrl();
    $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png?torrent', $url);
  }

  public function testExternalUrlWithSaveAs() {
    $wrapper = $this->getWrapper(NULL, function (&$config) {
      $config['s3filesystem.settings']['s3.saveas'] = array(
        'saveas/'
      );

      $config['s3filesystem.settings']['s3.torrents'] = array(
        'torrent/'
      );
    });

    $wrapper->setUri('s3://saveas/test.png');
    $url = $wrapper->getExternalUrl();
    $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
  }

  public function testExternalUrlWithPresignedUrl() {
    $wrapper = $this->getWrapper(NULL, function (&$config) {
      $config['s3filesystem.settings']['s3.presigned_url'] = array(
        'presigned_url/'
      );

      $config['s3filesystem.settings']['s3.torrents'] = array(
        'torrent/'
      );
    });

    $wrapper->setUri('s3://presigned_url/test.png');
    $url = $wrapper->getExternalUrl();
    $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
  }

  public function testDirectoryPath() {
    $wrapper       = $this->getWrapper();
    $directoryPath = $wrapper->getDirectoryPath();

    $this->assertEquals('s3/files', $directoryPath);
  }


  public function testChmod() {
    $wrapper = $this->getWrapper();
    $this->assertTrue($wrapper->chmod('s3://test.png'));
  }

  public function testRealpath() {
    $wrapper = $this->getWrapper();
    $this->assertFalse($wrapper->realpath('s3://test.png'));
  }

  public function testDirname() {
    $wrapper = $this->getWrapper();

    $dirName = $wrapper->dirname('s3://directory/subdirectory/test.png');
    $this->assertEquals('s3://directory/subdirectory', $dirName);

    $dirName = $wrapper->dirname($dirName);
    $this->assertEquals('s3://directory', $dirName);
  }
}
