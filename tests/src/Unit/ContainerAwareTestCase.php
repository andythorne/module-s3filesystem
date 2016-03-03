<?php

namespace Drupal\Tests\s3filesystem\Unit;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Tests\UnitTestCase;

/**
 * Class ContainerAwareTestCase
 *
 * @author andy.thorne@timeinc.com
 */
abstract class ContainerAwareTestCase extends UnitTestCase {

  /**
   * The mock container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerBuilder|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $container;

  /**
   * Map of service names to objects
   *
   * @var array
   */
  protected $serviceMap = [];

  /**
   * @var array
   */
  protected $testConfig = [];

  /**
   * @var ConfigFactory
   */
  protected $configFactory;

  protected function setUp() {
    parent::setUp();

    $this->container = $this->getMock(
      'Symfony\Component\DependencyInjection\ContainerInterface'
    );
    \Drupal::setContainer($this->container);

    $this->setupConfigFactory();
  }

  protected function setupConfigFactory(array $config = [])
  {
    $this->testConfig = S3ConfigFactory::buildConfig($config);
    $this->configFactory = $this->getConfigFactoryStub($this->testConfig);
    $this->setMockContainerService('config.factory', $this->configFactory);
  }

  /**
   * Sets up a mock expectation for the container get() method.
   *
   * @param string $service_name
   *   The service name to expect for the get() method.
   * @param mixed  $return
   *   The value to return from the mocked container get() method.
   */
  protected function setMockContainerService($service_name, $return = NULL) {
    $this->serviceMap[$service_name] = [$service_name, 1, $return];

    $this->container = $this->getMock(
      'Symfony\Component\DependencyInjection\ContainerInterface'
    );
    $this->container->expects($this->any())
      ->method('get')
      ->willReturnMap(
        $this->serviceMap
      );

    \Drupal::setContainer($this->container);
  }
}
