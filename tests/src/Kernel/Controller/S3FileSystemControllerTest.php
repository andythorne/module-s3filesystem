<?php

namespace Drupal\Tests\s3filesystem\Kernel\Controller;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
use Drupal\s3filesystem\Controller\S3FileSystemController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class S3FileSystemControllerTest
 *
 * @author andy.thorne@timeinc.com
 * @group  s3filesystem
 */
class S3FileSystemControllerTest extends \Drupal\KernelTests\KernelTestBase {

  public static $modules = [
    's3filesystem',
    'image',
  ];

  protected function setUp() {
    parent::setUp();

    $this->installSchema('s3filesystem', ['file_s3filesystem']);
    $this->installConfig('s3filesystem');
  }

  public function testImageStyleDeliver() {
    $s3Client = $this->getMockBuilder('Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->setMethods(['headObject', 'getCommand', 'execute'])
      ->getMock();
    $this->container->set('s3filesystem.aws_client', $s3Client);

    $s3Client->expects($this->any())
      ->method('headObject')
      ->willReturn(new Result());

    $s3Client->expects($this->any())
      ->method('getCommand')
      ->willReturnCallback(
        function ($name, $args) {
          return new Command($name, $args);
        }
      );
    $s3Client->expects($this->once())
      ->method('execute')
      ->willReturn(new Result());

    $imageStyle = $this->getMockBuilder('Drupal\image\ImageStyleInterface')
      ->getMock();
    $imageStyle->expects($this->once())
      ->method('buildUri')
      ->willReturn('s3://test/test.jpeg');
    $imageStyle->expects($this->once())
      ->method('createDerivative')
      ->willReturn(TRUE);

    $request = new Request(
      [
        'file' => 'test.jpeg'
      ]
    );

    $controller = S3FileSystemController::create($this->container);
    $controller->deliver($request, $imageStyle);
  }

}
