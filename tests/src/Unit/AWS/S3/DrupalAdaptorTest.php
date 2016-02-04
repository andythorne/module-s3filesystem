<?php

namespace Drupal\Tests\s3filesystem\Unit\Aws\S3;

use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
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

    $adaptor = new DrupalAdaptor($client, $this->container->get('database'));

    $adaptorClient = $adaptor->getS3Client();

    $this->assertInstanceOf('\Aws\S3\S3Client', $adaptorClient);
  }
}
