<?php

namespace Drupal\s3filesystem\StreamWrapper {

  function file_exists() {
    return FALSE;
  }
}

namespace Drupal\Tests\s3filesystem\Unit\StreamWrapper {

  use Aws\Command;
  use Aws\CommandInterface;
  use Aws\Result;
  use Aws\S3\S3Client;
  use Drupal\Core\Config\ConfigFactory;
  use Drupal\Core\StreamWrapper\StreamWrapperInterface;
  use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
  use Drupal\s3filesystem\StreamWrapper\S3StreamWrapper;
  use Drupal\Tests\s3filesystem\Unit\S3ConfigFactory;
  use PHPUnit_Framework_MockObject_MockObject;
  use Psr\Log\NullLogger;
  use Symfony\Component\DependencyInjection\ContainerBuilder;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;


  /**
   * Class S3FileSystemStreamWrapperTest
   *
   * @author andy.thorne@timeinc.com
   * @group  s3filesystem
   */
  class S3FileSystemStreamWrapperTest extends \Drupal\Tests\UnitTestCase {

    /**
     * The mock container.
     *
     * @var ContainerBuilder|PHPUnit_Framework_MockObject_MockObject
     */
    protected $container;

    /**
     * @var DrupalAdaptor|PHPUnit_Framework_MockObject_MockObject
     */
    protected $drupalAdaptor;

    /**
     * @var S3Client|PHPUnit_Framework_MockObject_MockObject
     */
    protected $s3Client;

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

      $this->s3Client = NULL;
      $this->container = $this->getMock(
        'Symfony\Component\DependencyInjection\ContainerInterface'
      );

      \Drupal::setContainer($this->container);
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

    protected function setupS3Client(array $s3Methods = []) {
      $this->s3Client = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->setMethods(
          array_unique(
            array_merge(
              $s3Methods,
              ['getObjectUrl', 'getCommand', 'execute', 'getPaginator']
            )
          )
        )
        ->getMock();

      $this->s3Client->expects($this->any())
        ->method('getObjectUrl')
        ->will(
          $this->returnCallback(
            function ($bucket, $key, $expires = NULL, array $args = array()) {
              return 'region.amazonaws.com/' . $key;
            }
          )
        );

      if (!in_array('getCommand', $s3Methods)) {
        $this->s3Client->expects($this->any())
          ->method('getCommand')
          ->willReturnCallback(
            function ($name, $args) {
              return new Command($name, $args);
            }
          );
      }
      if (!in_array('execute', $s3Methods)) {
        $this->s3Client->expects($this->any())
          ->method('execute')
          ->willReturn(new Result());
      }
    }

    /**
     * @param array $methods
     * @param array $configOverride
     *
     * @return \Drupal\s3filesystem\StreamWrapper\S3StreamWrapper
     */
    protected function getWrapper(
      array $methods = NULL,
      array $configOverride = []
    ) {

      $wrapper = $this->getMockBuilder(
        'Drupal\s3filesystem\StreamWrapper\S3StreamWrapper'
      )
        ->disableOriginalConstructor()
        ->setMethods($methods)
        ->getMock();

      $this->testConfig = S3ConfigFactory::buildConfig($configOverride);
      $this->configFactory = $this->getConfigFactoryStub($this->testConfig);

      if (!$this->s3Client instanceof S3Client) {
        $this->setupS3Client();
      }

      $this->drupalAdaptor = new DrupalAdaptor(
        $this->s3Client,
        $this->configFactory
      );

      /** @var $wrapper S3StreamWrapper */
      $wrapper->setUp(
        $this->drupalAdaptor,
        NULL,
        new NullLogger()
      );

      return $wrapper;
    }

    public function testName() {
      $wrapper = $this->getWrapper();

      $name = $wrapper->getName();
      $this->assertEquals('S3 Stream Wrapper (SDK)', $name);
    }

    public function testType() {
      $wrapper = $this->getWrapper();

      $type = $wrapper->getType();
      $this->assertEquals(StreamWrapperInterface::NORMAL, $type);
    }

    public function testGetSetUri() {
      $wrapper = $this->getWrapper();

      $wrapper->setUri('s3://test.png');
      $this->assertEquals('s3://test.png', $wrapper->getUri());
    }

    public function testStreamLock() {
      $wrapper = $this->getWrapper();
      $this->assertFalse($wrapper->stream_lock(NULL));
    }

    public function testStreamMetaData() {
      $wrapper = $this->getWrapper();
      $this->assertTrue($wrapper->stream_metadata(NULL, NULL, NULL));
    }

    public function testStreamSetOption() {
      $wrapper = $this->getWrapper();
      $this->assertFalse($wrapper->stream_set_option(NULL, NULL, NULL));
    }

    public function testStreamTruncate() {
      $wrapper = $this->getWrapper();
      $this->assertFalse($wrapper->stream_truncate(NULL));
    }

    public function testExternalUrl() {
      $wrapper = $this->getWrapper();

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('region.amazonaws.com/testprefix/test.png', $url);
    }

    public function testExternalUrlWithCustomCDN() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.custom_cdn.enabled' => TRUE,
          's3.custom_cdn.hostname' => 'assets.domain.co.uk',
        ]
      );

      $requestStack = new RequestStack();
      $requestStack->push(new Request());
      $this->setMockContainerService('request_stack', $requestStack);

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'http://assets.domain.co.uk/testprefix/test.png',
        $url
      );
    }

    public function testExternalUrlWithInvalidCustomCDN() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.custom_cdn.enabled' => TRUE,
          's3.custom_cdn.hostname' => 'wtf://hello',
        ]
      );

      $requestStack = new RequestStack();
      $requestStack->push(new Request());
      $this->setMockContainerService('request_stack', $requestStack);

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'http://assets.domain.co.uk/testprefix/test.png',
        $url
      );
    }

    public function testExternalUrlWithRelativeCustomCDN() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.custom_cdn.enabled' => TRUE,
          's3.custom_cdn.hostname' => 'wtf://',
        ]
      );

      $requestStack = new RequestStack();
      $requestStack->push(new Request());
      $this->setMockContainerService('request_stack', $requestStack);

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'http://assets.domain.co.uk/testprefix/test.png',
        $url
      );
    }

    public function testExternalUrlWithCustomCDNAndQueryString() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.custom_cdn.enabled' => TRUE,
          's3.custom_cdn.hostname' => 'assets.domain.co.uk',
        ]
      );

      $wrapper->setUri('s3://test.png?query_string');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'region.amazonaws.com/testprefix/test.png?query_string',
        $url
      );
    }

    public function testExternalUrlImageStyle() {
      $wrapper = $this->getWrapper();

      $urlGenerator = $this->getMockBuilder('\Drupal\Core\Routing\UrlGenerator')
        ->disableOriginalConstructor()
        ->getMock();

      $phpunit = $this;

      $this->getMock('file_exists');
      $urlGenerator->expects($this->once())
        ->method('generateFromRoute')
        ->will(
          $this->returnCallback(
            function ($route, $params) use ($phpunit) {
              $phpunit->assertEquals('image.style_s3', $route);
              $phpunit->assertArrayHasKey('image_style', $params);
              $phpunit->assertArrayHasKey('file', $params);

              return '/s3/files/' . $params['image_style'] . '?file=' . $params['file'];
            }
          )
        );
      $this->setMockContainerService('url_generator', $urlGenerator);

      $wrapper->setUri('s3://styles/large/s3/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('/s3/files/large?file=test.png', $url);
    }

    public function testExternalUrlWithTorrents() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.torrents' => [
            'torrent/'
          ],
        ]
      );

      $wrapper->setUri('s3://torrent/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'region.amazonaws.com/testprefix/torrent/test.png?torrent',
        $url
      );
    }

    public function testExternalUrlWithSaveAs() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.saveas' => [
            'saveas/'
          ],
          's3.torrents' => [
            'torrent/'
          ],
        ]
      );

      $wrapper->setUri('s3://saveas/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'region.amazonaws.com/testprefix/saveas/test.png',
        $url
      );
    }

    public function testExternalUrlWithPresignedUrl() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.presigned_urls' => [
            'presigned_url/',
            'presigned_url_timeout/|30'
          ],
          's3.torrents' => [
            'torrent/'
          ],
        ]
      );

      $wrapper->setUri('s3://presigned_url/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'region.amazonaws.com/testprefix/presigned_url/test.png',
        $url
      );

      $wrapper->setUri('s3://presigned_url_timeout/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals(
        'region.amazonaws.com/testprefix/presigned_url_timeout/test.png',
        $url
      );
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

    public function testDirnameWithUri() {
      $wrapper = $this->getWrapper();

      $wrapper->setUri('s3://directory/subdirectory/test.png');
      $dirName = $wrapper->dirname();
      $this->assertEquals('s3://directory/subdirectory', $dirName);
    }

    public function testDirnameAtRoot() {
      $wrapper = $this->getWrapper();

      $dirName = $wrapper->dirname('s3://test.png');
      $this->assertEquals('s3://', $dirName);
    }

    public function testDirOpen() {
      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $wrapper->dir_opendir('s3://unlink.png', NULL);

      $this->assertEquals(
        's3://unlink.png',
        $wrapper->getUri()
      );
    }

    public function testRmDir() {
      $this->setupS3Client(['headObject']);

      $this->s3Client->expects($this->any())
        ->method('headObject')
        ->willReturn(new Result());

      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $wrapper->rmdir('s3://unlink.png', NULL);

      $this->assertEquals(
        's3://unlink.png',
        $wrapper->getUri()
      );
    }

    public function testMkDir() {
      $this->setupS3Client(['doesObjectExist', 'putObject']);

      $this->s3Client->expects($this->once())
        ->method('doesObjectExist')
        ->willReturn(false);
      $this->s3Client->expects($this->once())
        ->method('putObject');

      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $wrapper->mkdir('s3://new/link.png', NULL, NULL);

      $this->assertEquals(
        's3://new/link.png',
        $wrapper->getUri()
      );
    }

    public function testStreamOpen() {
      $this->setupS3Client(['execute']);

      $this->s3Client->expects($this->once())
        ->method('execute')
        ->willReturnCallback(
          function (CommandInterface $command) {
            return [
              'ContentLength' => 0,
              'Body' => \GuzzleHttp\Psr7\stream_for(),
            ];
          }
        );

      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $nullRef = NULL;
      $wrapper->stream_open('s3://unlink.png', 'r', NULL, $nullRef);

      $this->assertEquals(
        's3://unlink.png',
        $wrapper->getUri()
      );
    }

    public function testUrlStat() {
      $this->setupS3Client(['headObject']);

      $this->s3Client->expects($this->any())
        ->method('headObject')
        ->willReturn(new Result());

      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $wrapper->url_stat('s3://unlink.png', NULL);

      $this->assertEquals(
        's3://unlink.png',
        $wrapper->getUri()
      );
    }

    public function testUnlink() {
      $this->setupS3Client(['deleteObject']);

      $this->s3Client->expects($this->any())
        ->method('deleteObject')
        ->willReturn(new Result());

      $wrapper = $this->getWrapper(
        NULL,
        [
          's3.keyprefix' => 'prefix',
          's3.bucket' => 'testbucket',
        ]
      );

      $wrapper->unlink('s3://unlink.png');

      $this->assertEquals(
        's3://unlink.png',
        $wrapper->getUri()
      );
    }
  }
}
