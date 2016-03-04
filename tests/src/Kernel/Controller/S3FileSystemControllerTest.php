<?php

namespace Drupal\s3filesystem\Controller {

  use Drupal\Tests\s3filesystem\Kernel\Controller\S3FileSystemControllerTest;

  function file_exists($file) {
    return (bool) S3FileSystemControllerTest::$fileExistsMocker[$file];
  }
}

namespace Drupal\Tests\s3filesystem\Kernel\Controller {

  use Drupal\s3filesystem\Controller\S3FileSystemController;
  use Symfony\Component\HttpFoundation\Request;

  /**
   * Class S3FileSystemControllerTest
   *
   * @author andy.thorne@timeinc.com
   * @group  s3filesystem
   */
  class S3FileSystemControllerTest extends \Drupal\KernelTests\KernelTestBase {

    /**
     * @var array
     */
    public static $fileExistsMocker = [];

    public static $modules = [
      's3filesystem',
      'image',
    ];

    protected function setUp() {
      parent::setUp();

      $this->installSchema('s3filesystem', ['file_s3filesystem']);
      $this->installConfig('s3filesystem');

      // add test bucket and regions
      $s3fsConfig = $this->config('s3filesystem.settings');
      $s3fsConfig->set('s3.bucket', 'testbucket');
      $s3fsConfig->set('s3.region', 'eu-west-1');
      $s3fsConfig->save(TRUE);
    }

    public function testImageStyleDeliverImageNotProvided() {

      $request = new Request();
      $imageStyle = $this->getMock('Drupal\image\ImageStyleInterface');

      $controller = S3FileSystemController::create($this->container);
      $response = $controller->deliver($request, $imageStyle);

      $this->assertEquals(500, $response->getStatusCode());
    }

    public function testImageStyleDeliverImageNotExists() {

      $request = new Request(
        [
          'file' => 'test.jpeg'
        ]
      );
      $imageStyle = $this->getMock('Drupal\image\ImageStyleInterface');

      self::$fileExistsMocker['s3://test.jpeg'] = FALSE;

      $controller = S3FileSystemController::create($this->container);
      $response = $controller->deliver($request, $imageStyle);

      $this->assertEquals(404, $response->getStatusCode());
    }

    public function testImageStyleDeliverImageExistsAndStyleExists() {
      $s3Client = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->getMock();
      $this->container->set('s3filesystem.aws_client', $s3Client);


      $imageStyle = $this->getMockBuilder('Drupal\image\ImageStyleInterface')
        ->getMock();
      $imageStyle->expects($this->once())
        ->method('buildUri')
        ->willReturn('s3://testbucket/style/test.jpeg');
      $imageStyle->expects($this->never())
        ->method('createDerivative');

      $request = new Request(
        [
          'file' => 'test.jpeg'
        ]
      );

      self::$fileExistsMocker['s3://test.jpeg'] = TRUE;
      self::$fileExistsMocker['s3://testbucket/style/test.jpeg'] = TRUE;

      $controller = S3FileSystemController::create($this->container);
      $response = $controller->deliver($request, $imageStyle);

      $this->assertEquals(302, $response->getStatusCode());
    }

    public function testImageStyleDeliverImageExistsAndStyleLocked() {
      $this->setExpectedException(
        '\Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException'
      );

      $s3Client = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->getMock();
      $this->container->set('s3filesystem.aws_client', $s3Client);

      $imageStyle = $this->getMockBuilder('Drupal\image\ImageStyleInterface')
        ->getMock();
      $imageStyle->expects($this->once())
        ->method('buildUri')
        ->willReturn('s3://testbucket/style/test.jpeg');
      $imageStyle->expects($this->never())
        ->method('createDerivative');

      $lock = $this->getMock('\Drupal\Core\Lock\LockBackendInterface');
      $lock->expects($this->once())
        ->method('acquire')
        ->willReturn(FALSE);
      $this->container->set('lock', $lock);

      self::$fileExistsMocker['s3://test.jpeg'] = TRUE;
      self::$fileExistsMocker['s3://testbucket/style/test.jpeg'] = FALSE;

      $request = new Request(
        [
          'file' => 'test.jpeg'
        ]
      );

      $controller = S3FileSystemController::create($this->container);
      $controller->deliver($request, $imageStyle);
    }

    public function testImageStyleDeliverImageExistsAndStyleGenerateFails() {
      $s3Client = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->getMock();
      $this->container->set('s3filesystem.aws_client', $s3Client);

      $imageStyle = $this->getMockBuilder('Drupal\image\ImageStyleInterface')
        ->getMock();
      $imageStyle->expects($this->once())
        ->method('buildUri')
        ->willReturn('s3://testbucket/style/test.jpeg');
      $imageStyle->expects($this->once())
        ->method('createDerivative')
        ->willReturn(false);

      $lock = $this->getMock('\Drupal\Core\Lock\LockBackendInterface');
      $lock->expects($this->once())
        ->method('acquire')
        ->willReturn(TRUE);
      $this->container->set('lock', $lock);

      self::$fileExistsMocker['s3://test.jpeg'] = TRUE;
      self::$fileExistsMocker['s3://testbucket/style/test.jpeg'] = FALSE;

      $request = new Request(
        [
          'file' => 'test.jpeg'
        ]
      );

      $controller = S3FileSystemController::create($this->container);
      $response = $controller->deliver($request, $imageStyle);

      $this->assertEquals(500, $response->getStatusCode());
    }

    public function testImageStyleDeliverImageExistsAndStyleGenerateSuccess() {
      $s3Client = $this->getMockBuilder('Aws\S3\S3Client')
        ->disableOriginalConstructor()
        ->getMock();
      $this->container->set('s3filesystem.aws_client', $s3Client);

      $imageStyle = $this->getMockBuilder('Drupal\image\ImageStyleInterface')
        ->getMock();
      $imageStyle->expects($this->once())
        ->method('buildUri')
        ->willReturn('s3://testbucket/style/test.jpeg');
      $imageStyle->expects($this->once())
        ->method('createDerivative')
        ->willReturn(true);

      self::$fileExistsMocker['s3://test.jpeg'] = TRUE;
      self::$fileExistsMocker['s3://testbucket/style/test.jpeg'] = FALSE;

      $request = new Request(
        [
          'file' => 'test.jpeg'
        ]
      );

      $controller = S3FileSystemController::create($this->container);
      $response = $controller->deliver($request, $imageStyle);

      $this->assertEquals(302, $response->getStatusCode());
    }

  }
}
