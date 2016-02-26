<?php

namespace Drupal\s3filesystem\Aws\S3;

use Aws\S3\S3Client;
use Drupal\Core\Database\Connection;

/**
 * Class DrupalAdaptor
 *
 * @package   Drupal\s3filesystem\Aws\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class DrupalAdaptor {

  /**
   * @var S3Client
   */
  protected $s3Client;

  /**
   * @var Connection
   */
  protected $database;

  function __construct(S3Client $s3Client, Connection $database) {
    $this->s3Client = $s3Client;
    $this->database = $database;
  }

  /**
   * @return S3Client
   */
  public function getS3Client() {
    return $this->s3Client;
  }

  /**
   * Refresh the local S3 cache
   *
   * @throws \Exception
   */
  public function refreshCache() {
    $config = \Drupal::config('s3filesystem.settings');

    $s3Config = $config->get('s3');

    // Set up the iterator that will loop over all the objects in the bucket.
    $iterator_args      = array('Bucket' => $s3Config['bucket']);

    // If the 'prefix' option has been set, retrieve from S3 only those files
    // whose keys begin with the prefix.
    if (!empty($s3Config['keyprefix'])) {
      $iterator_args['Prefix'] = $s3Config['keyprefix'];
    }
    $iterator_args['PageSize'] = 1000;

    $iterator = $this->s3Client->getIterator('ListObjects', $iterator_args);

    foreach ($iterator as $s3_metadata) {
      $uri = "s3://{$s3_metadata['Key']}";
      if(!is_dir($uri)) {
        $f = fopen($uri, 'r');
        fstat($f);
        fclose($f);
      }
    }
  }
} 
