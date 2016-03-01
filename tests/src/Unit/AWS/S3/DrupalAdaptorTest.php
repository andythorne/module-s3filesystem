<?php

namespace Drupal\Tests\s3filesystem\Unit\Aws\S3;

use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
use Drupal\Tests\UnitTestCase;

/**
 * Class DrupalAdaptorTest
 *
 * @group s3filesystem
 */
class DrupalAdaptorTest extends UnitTestCase
{

    /**
     * The mock container.
     *
     * @var \Symfony\Component\DependencyInjection\ContainerBuilder|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $container;

    protected function setUp()
    {
        parent::setUp();

        $container = $this->getMock('Symfony\Component\DependencyInjection\ContainerInterface');
        \Drupal::setContainer($container);
    }


    /**
     * Sets up a mock expectation for the container get() method.
     *
     * @param string $service_name
     *   The service name to expect for the get() method.
     * @param mixed $return
     *   The value to return from the mocked container get() method.
     */
    protected function setMockContainerService($service_name, $return = null)
    {
        $expects = $this->container->expects($this->once())
            ->method('get')
            ->with($service_name);

        if (isset($return)) {
            $expects->will($this->returnValue($return));
        } else {
            $expects->will($this->returnValue(true));
        }

        \Drupal::setContainer($this->container);
    }

    public function testS3Client()
    {
        $client = $this->getMockBuilder('\Aws\S3\S3Client')
            ->disableOriginalConstructor()
            ->getMock();

        $database = $this->getMockBuilder('\Drupal\Core\Database\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $adaptor = new DrupalAdaptor($client, $database);

        $this->assertSame($client, $adaptor->getS3Client());
    }
}
