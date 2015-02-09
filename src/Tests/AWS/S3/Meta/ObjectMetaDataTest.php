<?php
use Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData;
use Drupal\Tests\UnitTestCase;


/**
 * Class ObjectMetaDataTest
 *
 * @see Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData
 */
class ObjectMetaDataTest extends UnitTestCase {

  protected function getTestMeta($size = NULL, $dir = NULL, $time = NULL, $file='s3://file.png') {
    if ($size === NULL) {
      $size = 1337;
    }
    if ($dir === NULL) {
      $dir = FALSE;
    }
    if ($time === NULL) {
      $time = time();
    }

    return new ObjectMetaData($file, array(
      'ContentLength' => $size,
      'Directory'     => $dir,
      'LastModified'  => $time,
    ));
  }

  public function testConstructorWithTimestamp() {
    $meta = $this->getTestMeta(1337, false, time());

    $this->assertInstanceOf('\Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData', $meta);
  }

  public function testConstructorWithDate() {
    $now  = time();
    $meta = $this->getTestMeta(1337, false, strftime("%c", $now));

    $this->assertInstanceOf('\Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData', $meta);
  }

  public function testDirectory() {
    $meta = $this->getTestMeta(null, false);

    $this->assertFalse($meta->isDirectory());

    $meta->setDirectory(true);

    $this->assertTrue($meta->isDirectory());
  }

  public function testSize() {
    $meta = $this->getTestMeta(1337);

    $this->assertEquals(1337, $meta->getSize());

    $meta->setSize(999);

    $this->assertEquals(999, $meta->getSize());
  }

  public function testTimestamp() {
    $now = time();
    $meta = $this->getTestMeta(null, null, $now);

    $this->assertEquals($now, $meta->getTimestamp());

    $future = $now + 10000;
    $meta->setTimestamp($future);

    $this->assertEquals($future, $meta->getTimestamp());
  }

  public function testUri() {
    $file = 's3://uri/test.png';
    $meta = $this->getTestMeta(null, null, null, $file);

    $this->assertEquals($file, $meta->getUri());

    $newFile = 's3://newuri/test.png';
    $meta->setUri($newFile);

    $this->assertEquals($newFile, $meta->getUri());
  }

  public function testGetMeta()
  {
    $file = 's3://uri/test.png';
    $now = time();
    $meta = $this->getTestMeta(1337, false, $now, $file);

    $parsedMeta = $meta->getMeta();

    $this->assertTrue(is_array($parsedMeta));

    $this->assertArrayHasKey('ContentLength', $parsedMeta);
    $this->assertEquals(1337, $parsedMeta['ContentLength']);

    $this->assertArrayHasKey('Directory', $parsedMeta);
    $this->assertEquals(false, $parsedMeta['Directory']);

    $this->assertArrayHasKey('LastModified', $parsedMeta);
    $this->assertEquals(strftime("%c", $now), $parsedMeta['LastModified']);
  }

  public function testFromCache()
  {
    $data = array(
      'uri' => 's3://uri/test.png',
      'filesize' => 1337,
      'dir' => false,
      'timestamp' => time(),
    );
    $meta = ObjectMetaData::fromCache($data);

    $this->assertInstanceOf('\Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData', $meta);
    $this->assertEquals($data['uri'], $meta->getUri());
    $this->assertEquals($data['timestamp'], $meta->getTimestamp());
    $this->assertEquals($data['dir'], $meta->isDirectory());
    $this->assertEquals($data['filesize'], $meta->getSize());

  }
} 
