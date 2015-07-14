<?php

namespace Drupal\s3filesystem\StreamWrapper;

use Aws\S3\Exception\NoSuchKeyException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\StreamWrapper;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\Config;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\s3filesystem\AWS\S3\Meta\ObjectMetaData;
use Drupal\s3filesystem\Exception\S3FileSystemException;
use Drupal\s3filesystem\StreamWrapper\Body\S3SeekableCachingEntityBody;
use Guzzle\Service\Resource\Model;
use Psr\Log\LoggerInterface;

/**
 * Class S3StreamWrapper
 *
 * @package Drupal\s3filesystem\StreamWrapper
 */
class S3StreamWrapper extends StreamWrapper implements DrupalS3StreamWrapperInterface {

  use StringTranslationTrait;

  /**
   * @var S3SeekableCachingEntityBody
   */
  public $body;

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
      \Drupal::logger('s3filesystem')
    );
  }

  /**
   * Set up the StreamWrapper
   *
   * @param DrupalAdaptor   $drupalAdaptor
   * @param Config          $config
   * @param LoggerInterface $logger
   */
  public function setUp(DrupalAdaptor $drupalAdaptor, Config $config, LoggerInterface $logger = NULL) {
    $this->drupalAdaptor = $drupalAdaptor;
    $this->config        = $config;
    $this->logger        = $logger;

    $this->register($this->drupalAdaptor->getS3Client());
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
        list(, $imageStyle) = array_splice($path_parts, 0, 2);

        return \Drupal::urlGenerator()
          ->generateFromRoute('image.style_s3', array(
            'image_style' => $imageStyle,
            'path'        => implode('/', $path_parts),
          ));
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
    $url_settings = array(
      'torrent'       => FALSE,
      'presigned_url' => FALSE,
      'timeout'       => 60,
      'forced_saveas' => FALSE,
      'api_args'      => array('Scheme' => $this->config->get('s3.force_https') ? 'https' : 'http'),
    );

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
        $domain = SafeMarkup::checkPlain(UrlHelper::stripDangerousProtocols($cdnDomain));
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

      $url = "{$cdnDomain}/{$this->prefixPath($s3_filename)}";
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

      $url = self::$client->getObjectUrl($this->config->get('s3.bucket'), $this->prefixPath($s3_filename), $expires, $url_settings['api_args']);
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
    $params = $this->getParams($uri);

    try {
      /** @var Model $result */
      $result = self::$client->headObject($params);

      if ($result) {
        $resultObjectMeta = new ObjectMetaData($uri, $result->toArray());
        $this->drupalAdaptor->writeCache($resultObjectMeta);

        return $resultObjectMeta;
      }
    }
    catch(NoSuchKeyException $e) {
      // Maybe this isn't an actual key, but a prefix. Do a prefix listing of objects to determine.
      $result = static::$client->listObjects(array(
        'Bucket'  => $params['Bucket'],
        'Prefix'  => rtrim($params['Key'], '/') . '/',
        'MaxKeys' => 1
      ));
      if (isset($result['Contents']) && count($result['Contents']) === 1) {
        $resultObjectMeta = new ObjectMetaData($uri, array(
          'Directory' => TRUE,
        ));
        $this->drupalAdaptor->writeCache($resultObjectMeta);

        return $resultObjectMeta;
      }
    }
    catch(S3Exception $e) {
      // ignore any other S3 error
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
   * Inject the ACL permission into the stream options
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  protected function getOptions() {
    $options        = parent::getOptions();
    $options['ACL'] = 'public-read';

    return $options;
  }


  /**
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  protected function getParams($path) {
    $params = $this->getOptions();

    $filename = str_replace('s3://', '', $path);
    if (strpos($filename, $this->config->get('s3.keyprefix')) === FALSE) {
      $filename = rtrim($this->config->get('s3.keyprefix'), '/') . '/' . $filename;
    }

    // Remove both leading and trailing /s. S3 filenames never start with /,
    // and a $uri for a folder might be specified with a trailing /, which
    // we'd need to remove to be able to retrieve it from the cache.
    $s3path = trim($filename, '/');

    unset($params['seekable']);
    unset($params['throw_exceptions']);

    return array(
      'Bucket' => $this->config->get('s3.bucket'),
      'Key'    => $s3path
    ) + $params;
  }

  /**
   * AWS SDK StreamWrapper does not implement the rmdir method correctly.
   * It also clears the caching table of removed objects/paths.
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  public function rmdir($path, $options){
    $return = parent::rmdir($path, $options);

    // flush cache of deleted files
    $sqlPath = trim($path, '/') . '/';
    $files   = db_select('file_s3filesystem', 's')
      ->fields('s')
      ->condition('uri', db_like($sqlPath) . '%', 'LIKE')
      ->execute()
      ->fetchAllKeyed();
    $this->drupalAdaptor->deleteCache($files);

    return $return;
  }


  /**
   * We need to be able to fetch to the end of the file for image size reading.
   * By default, the AWS SDK does not do this. See github issue for more detail.
   *
   * @see https://github.com/aws/aws-sdk-php/issues/192
   *
   * @codeCoverageIgnore
   *
   * {@inheritdoc}
   */
  protected function openReadStream(array $params, array &$errors) {
    $r          = parent::openReadStream($params, $errors);
    $this->body = new S3SeekableCachingEntityBody($this->body);

    return $r;
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

    self::$client->waitUntilObjectExists($this->params);
    $this->fetchMetaData($this->uri);

    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags) {
    // check the cache

    $cache = $this->drupalAdaptor->readCache($path);
    if ($cache instanceof ObjectMetaData) {
      $args = $cache->isDirectory() ? NULL : $cache->getMeta();
      $stat = $this->formatUrlStat($args);
    }
    else {
      // check the remote server
      $remote = $this->fetchMetaData($path);
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
    if(!$return)
    {
      return false;
    }

    // update the meta cache
    $metadata = $this->drupalAdaptor->readCache($path_from);
    if(!$metadata){
      return false;
    }

    $metadata->setUri($path_to);
    $this->drupalAdaptor->writeCache($metadata);

    return true;
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

    return parent::triggerError($errors, $flags);
  }

  /**
   * Prefix a path with
   *
   * @param $path
   *
   * @return string
   */
  protected function prefixPath($path) {
    if (strpos($path, 's3://') === 0) {
      $path = str_replace('s3://', '', $path);
    }

    $keyPrefix = $this->config->get('s3.keyprefix') ? trim($this->config->get('s3.keyprefix'), '/') . '/' : '';
    if (strpos($path, $keyPrefix) === FALSE) {
      $path = $keyPrefix . $path;
    }

    return $path;
  }
} 
