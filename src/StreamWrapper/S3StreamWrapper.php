<?php

namespace Drupal\s3filesystem\StreamWrapper;

use Aws\CacheInterface;
use Aws\S3\StreamWrapper;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\s3filesystem\Aws\S3\DrupalAdaptor;
use Drupal\s3filesystem\Exception\S3FileSystemException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
   * @var DrupalAdaptor
   */
  protected $adaptor;

  /**
   * @var Connection
   */
  protected $database;

  /**
   * @var LoggerInterface
   */
  protected $logger;

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
      \Drupal::service('s3filesystem.adaptor'),
      \Drupal::service('s3filesystem.aws_client.s3.cache'),
      \Drupal::service('logger.factory')->get('s3filesystem')
    );
  }

  /**
   * Set up the StreamWrapper
   *
   * @param DrupalAdaptor   $adaptor
   * @param CacheInterface  $cache
   * @param LoggerInterface $logger
   */
  public function setUp(
    DrupalAdaptor $adaptor,
    CacheInterface $cache = NULL,
    LoggerInterface $logger = NULL
  ) {
    $this->adaptor = $adaptor;
    $this->logger = $logger ?: new NullLogger();

    $protocol = 's3';
    $this->register($this->adaptor->getS3Client(), $protocol, $cache);

    $default = stream_context_get_options(stream_context_get_default());
    $default[$protocol]['ACL'] = 'public-read';
    $default[$protocol]['seekable'] = TRUE;
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

    $filename = str_replace('s3://', '', $this->uri);
    $s3_filename = trim($filename, '/');

    // Image styles support:
    // If an image derivative URL (e.g. styles/thumbnail/blah.jpg) is requested
    // and the file doesn't exist, provide a URL to s3filesystem's special version of
    // image_style_deliver(), which will create the derivative when that URL
    // gets requested.
    $path_parts = explode('/', $s3_filename);
    if ($path_parts[0] == 'styles' && !file_exists($this->uri)) {
      list(, $imageStyle, $scheme) = array_splice($path_parts, 0, 3);

      return Url::fromRoute(
        'image.style_s3',
        [
          'image_style' => $imageStyle,
          'file' => implode('/', $path_parts),
        ]
      )->toString();
    }

    // If the filename contains a query string do not use cloudfront
    // It won't work!!!
    $cdnEnabled = $this->adaptor->getConfigValue('s3.custom_cdn.enabled');
    $cdnDomain = $this->adaptor->getConfigValue('s3.custom_cdn.domain');
    if (strpos($s3_filename, "?") !== FALSE) {
      $cdnEnabled = FALSE;
      $cdnDomain = NULL;
    }

    // Set up the URL settings from the Settings page.
    $url_settings = [
      'torrent' => FALSE,
      'presigned_url' => FALSE,
      'timeout' => 60,
      'forced_saveas' => FALSE,
      'api_args' => [
        'Scheme' => $this->adaptor->getConfigValue(
          's3.force_https'
        ) ? 'https' : 'http'
      ],
    ];

    // Presigned URLs.
    foreach ($this->adaptor->getConfigValue('s3.presigned_urls') as $line) {

      $blob = trim($line);
      $timeout = 60;
      if ($blob && preg_match('/(.*)\|(.*)/', $blob, $matches)) {
        $blob = $matches[2];
        $timeout = $matches[1];
      }
      // ^ is used as the delimeter because it's an illegal character in URLs.
      if (preg_match("^$blob^", $s3_filename)) {
        $url_settings['presigned_url'] = TRUE;
        $url_settings['timeout'] = $timeout;
        break;
      }
    }
    // Forced Save As.
    foreach ($this->adaptor->getConfigValue('s3.saveas') as $blob) {
      if (preg_match("^$blob^", $s3_filename)) {
        $filename = basename($s3_filename);
        $url_settings['api_args']['ResponseContentDisposition'] = "attachment; filename=\"$filename\"";
        $url_settings['forced_saveas'] = TRUE;
        break;
      }
    }

    if ($cdnEnabled) {
      $cdnHttpOnly = (bool) $this->adaptor->getConfigValue(
        's3.custom_cdn.http_only'
      );
      $request = \Drupal::request();

      if ($cdnDomain && (!$cdnHttpOnly || ($cdnHttpOnly && !$request->isSecure(
            )))
      ) {
        $domain = Html::escape(UrlHelper::stripDangerousProtocols($cdnDomain));
        if (!$domain) {
          throw new S3FileSystemException(
            $this->t(
              'The "Use custom CDN" option is enabled, but no Domain Name has been set.'
            )
          );
        }

        // If domain is set to a root-relative path, add the hostname back in.
        if (strpos($domain, '/') === 0) {
          $domain = $request->getHttpHost() . $domain;
        }
        $scheme = $request->isSecure() ? 'https' : 'http';
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

      $url = $this->adaptor->getS3Client()->getObjectUrl(
        $this->adaptor->getConfigValue('s3.bucket'),
        $this->prefixPath($s3_filename, FALSE, FALSE)
      );
    }

    // Torrents can only be created for publicly-accessible files:
    // https://forums.aws.amazon.com/thread.jspa?threadID=140949
    // So Forced SaveAs and Presigned URLs cannot be served as torrents.
    if (!$url_settings['forced_saveas'] && !$url_settings['presigned_url']) {
      foreach ($this->adaptor->getConfigValue('s3.torrents') as $blob) {
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
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::dir_opendir($path, $options);
  }


  /**
   * {@inheritdoc}
   */
  public function rmdir($path, $options) {
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::rmdir($path, $options);
  }

  /**
   * @inheritDoc
   */
  public function mkdir($path, $mode, $options) {
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::mkdir($path, $mode, $options);
  }

  /**
   * Store the uri for when we write the file
   *
   * {@inheritdoc}
   */
  public function stream_open($path, $mode, $options, &$opened_path) {
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::stream_open($path, $mode, $options, $opened_path);
  }

  public function unlink($path) {
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::unlink($path); // TODO: Change the autogenerated stub
  }


  /**
   * {@inheritdoc}
   */
  public function url_stat($path, $flags) {
    $this->uri = $path;
    $path = $this->prefixPath($path);

    return parent::url_stat($path, $flags);
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
  protected function prefixPath(
    $path,
    $includeStream = TRUE,
    $includeBucket = TRUE
  ) {
    if (strpos($path, 's3://') === 0) {
      $path = str_replace('s3://', '', $path);
    }

    if ($this->adaptor->getConfigValue('s3.keyprefix') && strpos(
        $path,
        $this->adaptor->getConfigValue('s3.keyprefix')
      ) === FALSE
    ) {
      $path = rtrim(
          $this->adaptor->getConfigValue('s3.keyprefix'),
          '/'
        ) . '/' . $path;
    }

    if ($includeBucket && $this->adaptor->getConfigValue('s3.bucket') && strpos(
        $path,
        $this->adaptor->getConfigValue('s3.bucket')
      ) === FALSE
    ) {
      $path = rtrim(
          $this->adaptor->getConfigValue('s3.bucket'),
          '/'
        ) . '/' . $path;
    }

    if ($includeStream) {
      $path = 's3://' . $path;
    }

    return $path;
  }
} 
