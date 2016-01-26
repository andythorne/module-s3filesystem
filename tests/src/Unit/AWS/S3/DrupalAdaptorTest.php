<?php

namespace Drupal\Tests\s3filesystem\Unit\AWS\S3;

use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\Tests\UnitTestCase;

/**
 * Class DrupalAdaptorTest
 *
 * @group s3filesystem
 */
class DrupalAdaptorTest extends UnitTestCase {

  /**
   * The mock container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerBuilder|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $container;

  /**
   * Sets up a mock expectation for the container get() method.
   *
   * @param string $service_name
   *   The service name to expect for the get() method.
   * @param mixed  $return
   *   The value to return from the mocked container get() method.
   */
  protected function setMockContainerService($service_name, $return = NULL) {
    $expects = $this->container->expects($this->once())
      ->method('get')
      ->with($service_name);

    if (isset($return)) {
      $expects->will($this->returnValue($return));
    }
    else {
      $expects->will($this->returnValue(TRUE));
    }

    \Drupal::setContainer($this->container);
  }

  public function testS3Client() {
    $client = $this->getMockBuilder('\Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->getMock();

    $adaptor = new DrupalAdaptor($client);

    $adaptorClient = $adaptor->getS3Client();

    $this->assertInstanceOf('\Aws\S3\S3Client', $adaptorClient);
  }

  public function testConvertMetadataUriWithoutMeta() {
    $client = $this->getMockBuilder('\Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->getMock();

    $adaptor = new DrupalAdaptor($client);

    $file = 's3://test';
    $meta = $adaptor->convertMetadata($file);

    $this->assertTrue(is_array($meta));

    $this->assertArrayHasKey('uri', $meta);
    $this->assertEquals($file, $meta['uri']);

    $this->assertArrayHasKey('dir', $meta);
    $this->assertEquals(1, $meta['dir']);

    $this->assertArrayHasKey('filesize', $meta);
    $this->assertEquals(0, $meta['filesize']);

    $this->assertArrayHasKey('uid', $meta);
    $this->assertEquals('S3 File System', $meta['uid']);

    $this->assertArrayHasKey('mode', $meta);
    $this->assertEquals(0040000 | 0777, $meta['mode']);
  }

  public function testConvertMetadataUriWithMeta() {
    $client = $this->getMockBuilder('\Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->getMock();

    $now     = time();
    $adaptor = new DrupalAdaptor($client);

    $file = 's3://test';
    $meta = $adaptor->convertMetadata($file, array(
      'Size'         => 1337,
      'LastModified' => $now,
      'Owner'        => array(
        'ID' => 99
      ),
    ));

    $this->assertTrue(is_array($meta));

    $this->assertArrayHasKey('uri', $meta);
    $this->assertEquals($file, $meta['uri']);

    $this->assertArrayHasKey('dir', $meta);
    $this->assertEquals(0, $meta['dir']);

    $this->assertArrayHasKey('filesize', $meta);
    $this->assertEquals(1337, $meta['filesize']);

    $this->assertArrayHasKey('uid', $meta);
    $this->assertEquals(99, $meta['uid']);

    $this->assertArrayHasKey('mode', $meta);
    $this->assertEquals(0100000 | 0777, $meta['mode']);
  }


}
