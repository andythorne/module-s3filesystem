<?php

namespace Drupal\Tests\s3filesystem\Unit\Aws\S3;

use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
use Drupal\Tests\s3filesystem\Unit\ContainerAwareTestCase;

/**
 * Class DrupalAdaptorTest
 *
 * @author andy.thorne@timeinc.com
 * @group s3filesystem
 */
class DrupalAdaptorTest extends ContainerAwareTestCase {

  public function testS3Client() {
    $client = $this->getMockBuilder('\Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->getMock();

    $adaptor = new DrupalAdaptor(
      $client,
      $this->configFactory
    );

    $this->assertSame($client, $adaptor->getS3Client());
  }

  /**
   * @dataProvider refreshCacheDataProvider
   *
   * @param array $config
   */
  public function testRefreshCache(array $config) {

    $testConfig = $config + $this->testConfig;
    $testConfig = $testConfig['s3filesystem.settings'];

    $client = $this->getMockBuilder('\Aws\S3\S3Client')
      ->disableOriginalConstructor()
      ->setMethods(['getIterator'])
      ->getMock();

    $expectedListObjectsParams = [
      'Bucket' => $testConfig['s3.bucket'],
      'PageSize' => 1000,
    ];
    if ($testConfig['s3.keyprefix']) {
      $expectedListObjectsParams['Prefix'] = $testConfig['s3.keyprefix'];
    }

    $client->expects($this->once())
      ->method('getIterator')
      ->with(
        'ListObjects',
        $this->identicalTo($expectedListObjectsParams)
      )
      ->willReturn(
        [
          ['Key' => 'test.png'],
          ['Key' => 'test.pdf'],
          ['Key' => 'test.md'],
        ]
      );

    $adaptor = new DrupalAdaptor(
      $client,
      $this->configFactory
    );

    $stream = $this->getMockBuilder(
      '\Drupal\s3filesystem\StreamWrapper\S3StreamWrapper'
    )
      ->disableOriginalConstructor()
      ->getMock();
    $stream->expects($this->exactly(3))
      ->method('url_stat');

    $adaptor->refreshCache($stream);
  }

  /**
   * test the refreshCache method both with and without prefixes
   *
   * @return array
   */
  public function refreshCacheDataProvider() {
    return [
      [
        's3filesystem.settings' => [
          's3.keyprefix' => 'testprefix'
        ]
      ],
      [
        's3filesystem.settings' => [
          's3.keyprefix' => NULL
        ]
      ],
    ];
  }
}


