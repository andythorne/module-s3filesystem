<?php

namespace Drupal\s3filesystem\StreamWrapper;

use Aws\Result;
use Aws\S3\Exception\NoSuchKeyException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\StreamWrapper;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\Config;
use Drupal\Core\Database\Connection;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData;
use Drupal\s3filesystem\Exception\S3FileSystemException;
use Psr\Log\LoggerInterface;

/**
 * Class S3StreamWrapper
 *
 * @package Drupal\s3filesystem\StreamWrapper
 */
class S3StreamWrapper extends StreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;

  /**
   * @var string
   */
  protected $uri;

  /**
   * S3filesystem config object
   *
   * @var Config
   */
  protected $config;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * @var DrupalAdaptor
   */
  protected $drupalAdaptor;

  /**
   * @var Connection
   */
  protected $database;

  /**
   * Stream wrapper constructor.
   *
   * Creates the Aws\S3\S3Client client object and activates the options
   * specified on the S3 File System Settings page.
   *
   * @codeCoverageIgnore
   *
   * @throws \Drupal\s3filesystem\Exception\S3FileSystemException
   */
  public function __construct() {
    $this->setUp(
      \Drupal::service('s3filesystem.client'),
      \Drupal::config('s3filesystem.settings'),
      \Drupal::logger('s3filesystem'),
      \Drupal::database()
    );
  }

  /**
   * Set up the StreamWrapper
   *
   * @param DrupalAdaptor                    $drupalAdaptor
   * @param Config                           $config
   * @param LoggerInterface                  $logger
   * @param \Drupal\Core\Database\Connection $database
   */
  public function setUp(DrupalAdaptor $drupalAdaptor, Config $config, LoggerInterface $logger = NULL, Connection $database) {
    $this->drupalAdaptor = $drupalAdaptor;
    $this->config        = $config;
    $this->logger        = $logger;
    $this->database      = $database;

    $protocol = 's3';
    $this->register($this->drupalAdaptor->getS3Client(), $protocol);

    $default                   = stream_context_get_options(stream_context_get_default());
    $default[$protocol]['ACL'] = 'public-read';
    stream_context_set_default($default);
  }


  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'S3 Stream Wrapper (SDK)';
  }

  /**
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function getDescription() {
    return 'AWS S3 file storage stream wrapper (Provided by AWS SDK)';
  }

  /**
   * {@inheritdoc}
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * Sets the stream resource URI. URIs are formatted as "s3://key".
   *
   * {@inheritdoc}
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * Returns the stream resource URI. URIs are formatted as "s3://key".
   *
   * {@inheritdoc}
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * Log debug messages
   *
   * @codeCoverageIgnore
   *
   * @param $name
   * @param $arguments
   */
  protected function log($name, $arguments) {
    $this->logger->debug($name . ' -> [' . implode(', ', $arguments) . ']');
  }

  /**
   * Not supported in S3
   *
   * {@inheritdoc}
   */
  public function stream_lock($operation) {
    return FALSE;
  }

  /**
   * Not supported in S3
   *
   * {@inheritdoc}
   */
  public function stream_metadata($uri, $option, $value) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_set_option($option, $arg1, $arg2) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function stream_truncate($new_size) {
    return FALSE;
  }

  /**
   * Not Supporeted in S3
   *
   * {@inheritdoc}
   */
  public function realpath() {
    return FALSE;
  }

  /**
   * Returns a web accessible URL for the resource.
   *
   * This function should return a URL that can be embedded in a web page
   * and accessed from a browser. For example, the external URL of
   * "youtube://xIpLd0WQKCY" might be
   * "http://www.youtube.com/watch?v=xIpLd0WQKCY".
   *
   * @throws S3FileSystemException
   * @return string
   *   Returns a string containing a web accessible URL for the resource.
   */
  public function getExternalUrl() {
    $filename    = str_replace('s3://', '', $this->uri);
    $s3_filename = trim($filename, '/');

    // Image styles support:
    // If an image derivative URL (e.g. styles/thumbnail/blah.jpg) is requested
    // and the file doesn't exist, provide a URL to s3filesystem's special version of
    // image_style_deliver(), which will create the derivative when that URL
    // gets requested.
    $path_parts = explode('/', $s3_filename);
    if ($path_parts[0] == 'styles') {
      if (!file_exists($this->uri)) {
        list(, $imageStyle, $scheme) = array_splice($path_parts, 0, 3);

        return Url::fromRoute(
          'image.style_s3',
          [
            'image_style' => $imageStyle,
            'file'        => implode('/', $path_parts),
          ]
        )->toString();
      }
    }

    // If the filename contains a query string do not use cloudfront
    // It won't work!!!
    $cdnEnabled = $this->config->get('s3.custom_cdn.enabled');
    $cdnDomain  = $this->config->get('s3.custom_cdn.domain');
    if (strpos($s3_filename, "?") !== FALSE) {
      $cdnEnabled = FALSE;
      $cdnDomain  = NULL;
    }

    // Set up the URL settings from the Settings page.
    $url_settings = [
      'torrent'       => FALSE,
      'presigned_url' => FALSE,
      'timeout'       => 60,
      'forced_saveas' => FALSE,
      'api_args'      => ['Scheme' => $this->config->get('s3.force_https') ? 'https' : 'http'],
    ];

    // Presigned URLs.
    foreach ($this->config->get('s3.presigned_urls') as $line) {

      $blob    = trim($line);
      $timeout = 60;
      if ($blob && preg_match('/(.*)\|(.*)/', $blob, $matches)) {
        $blob    = $matches[2];
        $timeout = $matches[1];
      }
      // ^ is used as the delimeter because it's an illegal character in URLs.
      if (preg_match("^$blob^", $s3_filename)) {
        $url_settings['presigned_url'] = TRUE;
        $url_settings['timeout']       = $timeout;
        break;
      }
    }
    // Forced Save As.
    foreach ($this->config->get('s3.saveas') as $blob) {
      if (preg_match("^$blob^", $s3_filename)) {
        $filename                                               = basename($s3_filename);
        $url_settings['api_args']['ResponseContentDisposition'] = "attachment; filename=\"$filename\"";
        $url_settings['forced_saveas']                          = TRUE;
        break;
      }
    }

    if ($cdnEnabled) {
      $cdnHttpOnly = (bool) $this->config->get('s3.custom_cdn.http_only');
      $request     = \Drupal::request();

      if ($cdnDomain && (!$cdnHttpOnly || ($cdnHttpOnly && !$request->isSecure()))) {
        $domain = Html::escape(UrlHelper::stripDangerousProtocols($cdnDomain));
        if (!$domain) {
          throw new S3FileSystemException($this->t('The "Use custom CDN" option is enabled, but no Domain Name has been set.'));
        }

        // If domain is set to a root-relative path, add the hostname back in.
        if (strpos($domain, '/') === 0) {
          $domain = $request->getHttpHost() . $domain;
        }
        $scheme    = $request->isSecure() ? 'https' : 'http';
        $cdnDomain = "$scheme://$domain";
      }

      $url = "{$cdnDomain}/{$this->prefixPath($s3_filename, FALSE, FALSE)}";
    }
    else {
      $expires = NULL;
      if ($url_settings['presigned_url']) {
        $expires = "+{$url_settings['timeout']} seconds";
      }
      else {
        // Due to Amazon's security policies (see Request Parameters section @
        // http://docs.aws.amazon.com/AmazonS3/latest/API/RESTObjectGET.html),
        // only signed requests can use request parameters.
        // Thus, we must provide an expiry time for any URLs which specify
        // Response* API args. Currently, this only includes "Forced Save As".
        foreach ($url_settings['api_args'] as $key => $arg) {
          if (strpos($key, 'Response') === 0) {
            $expires = "+10 years";
            break;
          }
        }
      }

      $url = $this->drupalAdaptor->getS3Client()->getObjectUrl(
        $this->config->get('s3.bucket'),
        $this->prefixPath($s3_filename, FALSE, FALSE)
      );
    }

    // Torrents can only be created for publicly-accessible files:
    // https://forums.aws.amazon.com/thread.jspa?threadID=140949
    // So Forced SaveAs and Presigned URLs cannot be served as torrents.
    if (!$url_settings['forced_saveas'] && !$url_settings['presigned_url']) {
      foreach ($this->config->get('s3.torrents') as $blob) {
        if (preg_match("^$blob^", $s3_filename)) {
          // A torrent URL is just a plain URL with "?torrent" on the end.
          $url .= '?torrent';
          break;
        }
      }
    }

    return $url;
  }

  /**
   * Fetch and cache the meta data
   *
   * @param $uri
   *
   * @return ObjectMetaData|false
   */
  protected function fetchMetaData($uri) {

    list($scheme, $path) = explode('://', $uri);
    $params = [
      'Bucket' => $this->config->get('s3.bucket'),
      'Key'    => $this->prefixPath($path, FALSE, FALSE)
    ];

    try {
      $result = $this->drupalAdaptor->getS3Client()->headObject($params);

      if ($result instanceof Result) {
        if ((int) $result->get('ContentLength') === 0 && ($path === '' || substr($path, -1) === '/')) {
          $meta = [
            'Directory' => TRUE,
          ];
        }
        else {
          $meta = $result->toArray();
        }
        $resultObjectMeta = new ObjectMetaData($uri, $meta);
        $this->drupalAdaptor->writeCache($resultObjectMeta);

        return $resultObjectMeta;
      }
    } catch (S3Exception $e) {
      // Maybe this isn't an actual key, but a prefix. Do a prefix listing of objects to determine.
      $params['Prefix']  = rtrim($params['Key'], '/') . '/';
      $params['Key']     = NULL;
      $params['MaxKeys'] = 1;

      $result = $this->drupalAdaptor->getS3Client()->listObjects($params);
      if (isset($result['Contents']) && count($result['Contents']) === 1) {
        $resultObjectMeta = new ObjectMetaData(
          $uri, [
            'Directory' => TRUE,
          ]
        );
        $this->drupalAdaptor->writeCache($resultObjectMeta);

        return $resultObjectMeta;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function dirname($uri = NULL) {
    if ($uri === NULL) {
      $uri = $this->uri;
    }

    $data = explode('://', $uri, 2);

    // Remove erroneous leading or trailing forward-slashes and backslashes.
    $target = isset($data[1]) ? trim($data[1], '\/') : FALSE;

    $dirname = dirname($target);

    // When the dirname() call above is given 's3://', it returns '.'.
    // But 's3://.' is invalid, so we convert it to '' to get "s3://".
    if ($dirname == '.') {
      $dirname = '';
    }

    return "s3://$dirname";
  }

  /**
   * @inheritDoc
   */
  public function dir_opendir($path, $options) {
    $this->uri = $path = $this->prefixPath($path);

    return parent::dir_opendir($path, $options); // TODO: Change the autogenerated stub
  }


  /**
   * AWS SDK StreamWrapper does not implement the rmdir method correctly.
   * It also clears the caching table of removed objects/paths.
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function rmdir($path, $options) {
    $this->uri = $path = $this->prefixPath($path);
    $return = parent::rmdir($path, $options);

    // flush cache of deleted files
    $sqlPath = trim($path, '/') . '/';
    $files   = $this->database->select('file_s3filesystem', 's')
      ->fields('s')
      ->condition('uri', $this->database->escapeLike($sqlPath) . '%', 'LIKE')
      ->execute()
      ->fetchAllKeyed();
    $this->drupalAdaptor->deleteCache($files);

    return $return;
  }

  /**
   * @inheritDoc
   */
  public function mkdir($path, $mode, $options) {
    $this->uri = $path = $this->prefixPath($path);
    return parent::mkdir($path, $mode, $options); // TODO: Change the autogenerated stub
  }

  /**
   * Store the uri for when we write the file
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path) {
    $this->uri = $path;
    $path      = $this->prefixPath($path);

    return parent::stream_open($path, $mode, $options, $opened_path);
  }

  /**
   * Write cache after a flush
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function stream_flush() {
    $return = parent::stream_flush();

    $pathParts = explode('/', $this->uri);
    array_splice($pathParts, 0, 2);
    $this->drupalAdaptor->getS3Client()->waitUntil(
      'ObjectExists',
      [
        'Bucket' => $this->config->get('s3.bucket'),
        'Key' => $this->prefixPath(implode('/', $pathParts), false, false),
      ]
    );

    $this->fetchMetaData($this->uri);

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags) {
    // check the cache
    $this->uri = $path;
    $path      = $this->prefixPath($path);

    $cache = $this->drupalAdaptor->readCache($this->uri);
    if ($cache instanceof ObjectMetaData) {
      $args = $cache->isDirectory() ? NULL : $cache->getMeta();
      $stat = $this->formatUrlStat($args);
    }
    else {
      // check the remote server
      $remote = $this->fetchMetaData($this->uri);
      if ($remote instanceof ObjectMetaData) {
        $args = $remote->isDirectory() ? NULL : $remote->getMeta();
        $stat = $this->formatUrlStat($args);
      }
      else {
        $stat = parent::url_stat($path, $flags);
      }
    }

    return $stat;
  }

  /**
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function rename($path_from, $path_to) {
    $return = parent::rename($path_from, $path_to);
    if (!$return) {
      return FALSE;
    }

    // update the meta cache
    $metadata = $this->drupalAdaptor->readCache($path_from);
    if (!$metadata) {
      return FALSE;
    }

    $metadata->setUri($path_to);
    $this->drupalAdaptor->writeCache($metadata);

    return TRUE;
  }

  /**
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function unlink($path) {
    $return = parent::unlink($path);

    // remove any cache
    if ($return) {
      $this->drupalAdaptor->deleteCache($path);
    }

    return $return;
  }

  /**
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  protected function triggerError($errors, $flags = NULL) {
    $this->logger->error($errors);

    // This is triggered with things like file_exists()
    if ($flags & STREAM_URL_STAT_QUIET) {
      return $flags & STREAM_URL_STAT_LINK
        // This is triggered for things like is_link()
        ? $this->formatUrlStat(FALSE)
        : FALSE;
    }

    // This is triggered when doing things like lstat() or stat()
    trigger_error(implode("\n", (array) $errors), E_USER_WARNING);

    return FALSE;
  }

  /**
   * Prefix a path with
   *
   * @param string $path
   * @param bool   $includeStream
   * @param bool   $includeBucket
   *
   * @return string
   */
  protected function prefixPath($path, $includeStream = TRUE, $includeBucket = TRUE) {
    if (strpos($path, 's3://') === 0) {
      $path = str_replace('s3://', '', $path);
    }

    if (strpos($path, $this->config->get('s3.keyprefix')) === FALSE) {
      $path = rtrim($this->config->get('s3.keyprefix'), '/') . '/' . $path;
    }

    if ($includeBucket && strpos($path, $this->config->get('s3.bucket')) === FALSE) {
      $path = rtrim($this->config->get('s3.bucket'), '/') . '/' . $path;
    }

    if ($includeStream) {
      $path = 's3://' . $path;
    }

    return $path;
  }

  /**
   * Gets a URL stat template with default values
   *
   * @return array
   */
  private function getStatTemplate() {
    return [
      0         => 0,
      'dev'     => 0,
      1         => 0,
      'ino'     => 0,
      2         => 0,
      'mode'    => 0,
      3         => 0,
      'nlink'   => 0,
      4         => 0,
      'uid'     => 0,
      5         => 0,
      'gid'     => 0,
      6         => -1,
      'rdev'    => -1,
      7         => 0,
      'size'    => 0,
      8         => 0,
      'atime'   => 0,
      9         => 0,
      'mtime'   => 0,
      10        => 0,
      'ctime'   => 0,
      11        => -1,
      'blksize' => -1,
      12        => -1,
      'blocks'  => -1,
    ];
  }

  private function formatUrlStat($result = NULL) {
    $stat = $this->getStatTemplate();
    switch (gettype($result)) {
      case 'NULL':
      case 'string':
        // Directory with 0777 access - see "man 2 stat".
        $stat['mode'] = $stat[2] = 0040777;
        break;
      case 'array':
        // Regular file with 0777 access - see "man 2 stat".
        $stat['mode'] = $stat[2] = 0100777;
        // Pluck the content-length if available.
        if (isset($result['ContentLength'])) {
          $stat['size'] = $stat[7] = $result['ContentLength'];
        }
        elseif (isset($result['Size'])) {
          $stat['size'] = $stat[7] = $result['Size'];
        }
        if (isset($result['LastModified'])) {
          // ListObjects or HeadObject result
          $stat['mtime'] = $stat[9] = $stat['ctime'] = $stat[10]
            = strtotime($result['LastModified']);
        }
    }

    return $stat;
  }
} 
