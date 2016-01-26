<?php

namespace Drupal\Tests\s3filesystem\Unit\StreamWrapper {
  function file_exists() {
    return FALSE;
  }
}

namespace Drupal\Tests\s3filesystem\Unit\StreamWrapper {

  use Drupal\Core\StreamWrapper\StreamWrapperInterface;
  use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
  use Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData;
  use Drupal\s3filesystem\StreamWrapper\S3StreamWrapper;
  use Drupal\Tests\UnitTestCase;
  use Psr\Log\NullLogger;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\RequestStack;


  /**
   * Class S3FileSystemStreamWrapperTest
   *
   * @group s3filesystem
   */
  class S3FileSystemStreamWrapperTest extends UnitTestCase {

    /**
     * The mock container.
     *
     * @var \Symfony\Component\DependencyInjection\ContainerBuilder|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $container;

    /**
     * @var DrupalAdaptor
     */
    protected $drupalAdaptor;

    protected function setUp() {
      $this->container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerBuilder')
        ->setMethods(array('get'))
        ->getMock();
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

      $s3Client            = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->getMock();
      $this->drupalAdaptor = $this->getMockBuilder('\Drupal\s3filesystem\AWS\S3\DrupalAdaptor')
        ->setConstructorArgs(array($s3Client))
        ->setMethods(array(
          'readCache',
          'writeCache',
          'deleteCache',
        ))
        ->getMock();

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

      $s3Client->expects($this->any())
        ->method('getObjectUrl')
        ->will($this->returnCallback(function ($bucket, $key, $expires = NULL, array $args = array()) {
          return 'region.amazonaws.com/' . $key;
        }));

      $db = $this->getMockBuilder('\Drupal\Core\Database\Connection')
        ->disableOriginalConstructor()
        ->getMock();

      /** @var $wrapper S3StreamWrapper */
      $wrapper->setUp(
        $this->drupalAdaptor,
        $config,
        new NullLogger(),
        $db
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
      $this->assertFalse($wrapper->stream_lock(null));
    }

    public function testStreamMetaData() {
      $wrapper = $this->getWrapper();
      $this->assertTrue($wrapper->stream_metadata(null, null, null));
    }

    public function testExternalUrl() {
      $wrapper = $this->getWrapper();

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('region.amazonaws.com/testprefix/test.png', $url);
    }

    public function testExternalUrlWithCustomCDN() {
      $wrapper = $this->getWrapper(NULL, function (&$config) {
        $config['s3filesystem.settings']['s3.custom_cdn.enabled']  = TRUE;
        $config['s3filesystem.settings']['s3.custom_cdn.hostname'] = 'assets.domain.co.uk';
      });

      $requestStack = new RequestStack();
      $requestStack->push(new Request());
      $this->setMockContainerService('request_stack', $requestStack);

      $wrapper->setUri('s3://test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('http://assets.domain.co.uk/testprefix/test.png', $url);
    }

    public function testExternalUrlWithCustomCDNAndQueryString() {
      $wrapper = $this->getWrapper(NULL, function (&$config) {
        $config['s3filesystem.settings']['s3.custom_cdn.enabled']  = TRUE;
        $config['s3filesystem.settings']['s3.custom_cdn.hostname'] = 'assets.domain.co.uk';
      });

      $wrapper->setUri('s3://test.png?query_string');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('region.amazonaws.com/testprefix/test.png?query_string', $url);
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
        ->will($this->returnCallback(function ($route, $params) use ($phpunit) {
          $phpunit->assertEquals('image.style_s3', $route);
          $phpunit->assertArrayHasKey('image_style', $params);
          $phpunit->assertArrayHasKey('file', $params);

          return '/s3/files/' . $params['image_style'] . '?file=' . $params['path'];
        }));
      $this->setMockContainerService('url_generator', $urlGenerator);

      $wrapper->setUri('s3://styles/large/s3/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('/s3/files/large/s3/test.png', $url);
    }

    public function testExternalUrlWithTorrents() {
      $wrapper = $this->getWrapper(NULL, function (&$config) {
        $config['s3filesystem.settings']['s3.torrents'] = array(
          'torrent/'
        );
      });

      $wrapper->setUri('s3://torrent/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('region.amazonaws.com/testprefix/torrent/test.png?torrent', $url);
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
      $this->assertEquals('region.amazonaws.com/testprefix/saveas/test.png', $url);
    }

    public function testExternalUrlWithPresignedUrl() {
      $wrapper = $this->getWrapper(NULL, function (&$config) {
        $config['s3filesystem.settings']['s3.presigned_urls'] = array(
          'presigned_url/'
        );

        $config['s3filesystem.settings']['s3.torrents'] = array(
          'torrent/'
        );
      });

      $wrapper->setUri('s3://presigned_url/test.png');
      $url = $wrapper->getExternalUrl();
      $this->assertEquals('region.amazonaws.com/testprefix/presigned_url/test.png', $url);
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

//    public function testUnlinkSuccess() {
//      $wrapper = $this->getWrapper();
//
//      $this->drupalAdaptor->expects($this->once)
//        ->method('deleteCache')
//        ->will($this->returnValue(true));
//
//      $wrapper->unlink('s3://unlink.png');
//    }
//
//    public function testUnlinkFail() {
//      $wrapper = $this->getWrapper();
//
//      $this->drupalAdaptor->expects($this->once())
//        ->method('deleteCache')
//        ->will($this->returnValue(true));
//
//      $wrapper->unlink('s3://unlink.png');
//    }


    public function testUrlStatFileCacheHit() {
      $modified = time();
      $wrapper  = $this->getWrapper();
      $meta     = new ObjectMetaData('s3://cache/hit.png', array(
        'ContentLength' => 1337,
        'Directory'     => FALSE,
        'LastModified'  => $modified,
      ));
      $this->drupalAdaptor->expects($this->once())
        ->method('readCache')
        ->will($this->returnValue($meta));

      $stat = $wrapper->url_stat('s3://cache/hit.png', 0);

      $this->assertTrue(is_array($stat));

      $this->assertArrayHasKey('size', $stat);
      $this->assertEquals(1337, $stat['size']);

      $this->assertArrayHasKey('mtime', $stat);
      $this->assertEquals($modified, $stat['mtime']);

      $this->assertArrayHasKey('mode', $stat);
      $this->assertEquals(33279, $stat['mode']);
    }

    public function testUrlStatDirCacheHit() {
      $modified = time();
      $wrapper  = $this->getWrapper();
      $meta     = new ObjectMetaData('s3://cache/dir', array(
        'ContentLength' => 0,
        'Directory'     => TRUE,
        'LastModified'  => $modified,
      ));
      $this->drupalAdaptor->expects($this->once())
        ->method('readCache')
        ->will($this->returnValue($meta));

      $stat = $wrapper->url_stat('s3://cache/dir', 0);

      $this->assertTrue(is_array($stat));

      $this->assertArrayHasKey('size', $stat);
      $this->assertEquals(0, $stat['size']);

      $this->assertArrayHasKey('mtime', $stat);
      $this->assertEquals(0, $stat['mtime']);

      $this->assertArrayHasKey('mode', $stat);
      $this->assertEquals(16895, $stat['mode']);
    }
  }
}
