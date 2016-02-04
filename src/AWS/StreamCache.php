<?php

namespace Drupal\s3filesystem\AWS;

use Aws\CacheInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;

/**
 * Class StreamCache
 */
class StreamCache implements CacheInterface {

  /**
   * @var Connection
   */
  protected $database;

  /**
   * StreamCache constructor.
   *
   * @param Connection $database
   */
  public function __construct(Connection $database)
  {
    $this->database = $database;
  }

  /**
   * @inheritDoc
   */
  public function get($key) {
    $record = $this->database->select('file_s3filesystem', 's')
      ->fields('s')
      ->condition('uri', $key, '=')
      ->execute()
      ->fetchAssoc();

    if ($record) {
      if($record['expires'] <= time()){
        $this->remove($key);

        return false;
      }
      return json_decode($record['stat'], true);
    }

    return false;
  }

  /**
   * @inheritDoc
   */
  public function set($key, $value, $ttl = 0) {
    if($ttl <= 0) {
      $expires = time() + 31557600;
    } else {
      $expires = time() + $ttl;
    }
    $result = $this->database->merge('file_s3filesystem')
      ->key(array('uri' => $key))
      ->fields(array(
        'stat'  => json_encode($value),
        'expires' => $expires,
      ))
      ->execute();

    return $result;
  }

  /**
   * @inheritDoc
   */
  public function remove($key) {
    $delete_query = $this->database->delete('file_s3filesystem');
    $uri = rtrim($key, '/');
    if (is_array($uri)) {
      // Build an OR condition to delete all the URIs in one query.
      $or = new Condition('OR');
      foreach ($uri as $u) {
        $or->condition('uri', $u, '=');
      }
      $delete_query->condition($or);
    }
    else {
      $delete_query->condition('uri', $uri, '=');
    }

    return $delete_query->execute();
  }

}
