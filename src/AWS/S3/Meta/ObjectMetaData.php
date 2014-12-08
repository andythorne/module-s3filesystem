<?php

namespace Drupal\s3filesystem\AWS\S3\Meta;

/**
 * Class ObjectMetaData
 */
class ObjectMetaData {

  /**
   * @var string
   */
  protected $uri;

  /**
   * @var int
   */
  protected $timestamp;

  /**
   * @var int
   */
  protected $size;

  /**
   * @var boolean
   */
  protected $directory;

  function __construct($uri, array $meta) {
    $this->uri       = $uri;
    $this->size      = isset($meta['ContentLength']) ? $meta['ContentLength'] : 0;
    $this->directory = isset($meta['Directory']) ? $meta['Directory'] : 0;
    $this->timestamp = isset($meta['LastModified']) ? (is_numeric($meta['LastModified']) ? $meta['LastModified'] : strtotime($meta['LastModified'])) : time();
  }

  /**
   * Create a ObjectMetaData from a db cache result
   *
   * @param array  $cache
   *
   * @return static
   */
  static function fromCache(array $cache) {
    return new static(
      $cache['uri'],
      array(
        'ContentLength' => isset($cache['filesize']) ? (int)$cache['filesize'] : 0,
        'Directory'     => isset($cache['dir']) ? (bool)$cache['dir'] : 0,
        'LastModified'  => isset($cache['timestamp']) ? (int)$cache['timestamp'] : 0,
      )
    );
  }

  /**
   * Convert the meta data back into a amazon meta format
   *
   * @return array
   */
  public function getMeta() {
    return array(
      'ContentLength' => $this->size,
      'Directory'     => $this->directory,
      'LastModified'  => strftime("%c", $this->timestamp),
    );
  }

  /**
   * @return boolean
   */
  public function isDirectory() {
    return $this->directory;
  }

  /**
   * @param boolean $directory
   */
  public function setDirectory($directory) {
    $this->directory = $directory;
  }

  /**
   * @return int
   */
  public function getSize() {
    return $this->size;
  }

  /**
   * @param int $size
   */
  public function setSize($size) {
    $this->size = $size;
  }

  /**
   * @return int
   */
  public function getTimestamp() {
    return $this->timestamp;
  }

  /**
   * @param int $timestamp
   */
  public function setTimestamp($timestamp) {
    $this->timestamp = $timestamp;
  }

  /**
   * @return string
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * @param string $uri
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

} 
