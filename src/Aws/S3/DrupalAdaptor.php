<?php

namespace Drupal\s3filesystem\Aws\S3;

use Aws\S3\S3Client;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\s3filesystem\StreamWrapper\S3StreamWrapper;

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
   * @var Config
   */
  protected $config;

  function __construct(
    S3Client $s3Client,
    ConfigFactoryInterface $configFactory
  ) {
    $this->s3Client = $s3Client;
    $this->config = $configFactory->get('s3filesystem.settings');
  }

  /**
   * @return S3Client
   */
  public function getS3Client() {
    return $this->s3Client;
  }

  /**
   * @param $key
   *
   * @return Config
   */
  public function getConfigValue($key) {
    return $this->config->get($key);
  }

  /**
   * Refresh the local S3 cache
   *
   * @param \Drupal\Core\StreamWrapper\StreamWrapperInterface $stream
   */
  public function refreshCache(StreamWrapperInterface $stream = null) {
    // Set up the iterator that will loop over all the objects in the bucket.
    $iterator_args = array(
      'Bucket' => $this->config->get('s3.bucket'),
      'PageSize' => 1000,
    );

    // If the 'prefix' option has been set, retrieve from S3 only those files
    // whose keys begin with the prefix.
    if ($this->config->get('s3.keyprefix')) {
      $iterator_args['Prefix'] = $this->config->get('s3.keyprefix');
    }

    $iterator = $this->s3Client->getIterator('ListObjects', $iterator_args);

    $stream = $stream ?: new S3StreamWrapper();

    foreach ($iterator as $s3_metadata) {
      $stream->url_stat('s3://' . $s3_metadata['Key'], 0);
    }
  }
} 
