<?php

/**
 * We need to standardise the time response so we can assert TTLs
 */
namespace Drupal\s3filesystem\Aws {
  function time(){
    return 0;
  }
}

namespace Drupal\Tests\s3filesystem\Kernel\Aws {

  use Drupal\KernelTests\KernelTestBase;
  use Drupal\s3filesystem\Aws\StreamCache;

  /**
   * Class StreamCacheTest
   *
   * @author andy.thorne@timeinc.com
   * @group  s3filesystem
   */
  class StreamCacheTest extends KernelTestBase {

    public static $modules = [
      's3filesystem',
    ];

    protected function setUp() {
      parent::setUp();

      $this->installSchema('s3filesystem', ['file_s3filesystem']);
    }

    public function testSetAndGetNoTtl() {
      $streamCache = new StreamCache(
        \Drupal::database()
      );

      $storeValue = ['Key' => 'test.png'];
      $streamCache->set('s3://test.png', $storeValue);

      $this->assertEquals($storeValue, $streamCache->get('s3://test.png'));
    }

    /**
     * @dataProvider ttlDataProvider
     * @param int $ttl
     */
    public function testSetAndGetWithTtl($ttl) {
      $database = \Drupal::database();
      $streamCache = new StreamCache(
        $database
      );

      $key = 's3://test.png';
      $storeValue = ['Key' => 'test.png'];
      $streamCache->set($key, $storeValue, $ttl);

      $this->assertEquals($storeValue, $streamCache->get($key));

      $row = $database->select('file_s3filesystem', 's')
        ->fields('s')
        ->condition('uri', $key, '=')
        ->execute()
        ->fetchAssoc();

      if(!is_numeric($ttl) || $ttl <= 0){
        $expectedTtl = $streamCache::$futureCacheTime;
      } else {
        $expectedTtl = $ttl;
      }

      $this->assertEquals($expectedTtl, $row['expires']);
    }

    /**
     * Provide TTLs to test set method
     * @return array
     */
    public function ttlDataProvider()
    {
      return [
        [null],
        [-1000000],
        [-10],
        [0],
        [10],
        [1000000],
      ];
    }

    public function testGetExpired()
    {
      $database = \Drupal::database();
      $database->merge('file_s3filesystem')
        ->key(array('uri' => 's3://test.png'))
        ->fields(array(
          'stat'  => json_encode(['Key' => 'test.png']),
          'expires' => 0,
        ))
        ->execute();

      $streamCache = new StreamCache(
        $database
      );

      $this->assertFalse($streamCache->get('s3://test.png'));
    }

    public function testGetNotFound() {
      $streamCache = new StreamCache(
        \Drupal::database()
      );

      $this->assertFalse($streamCache->get('s3://test.png'));
    }

    public function testRemove() {
      $streamCache = new StreamCache(
        \Drupal::database()
      );

      $storeValue = ['Key' => 'test.png'];
      $streamCache->set('s3://test.png', $storeValue);

      $this->assertTrue($streamCache->remove('s3://test.png'));
      $this->assertFalse($streamCache->get('s3://test.png'));
    }

    public function testRemoveNotFound() {
      $streamCache = new StreamCache(
        \Drupal::database()
      );

      $this->assertFalse($streamCache->get('s3://test.png'));
      $this->assertFalse($streamCache->remove('s3://test.png'));
    }
  }
}
