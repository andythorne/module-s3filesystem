<?php

/**
 * @file
 * Drupal stream wrapper implementation for S3 File System.
 *
 * Implements DrupalStreamWrapperInterface to provide an Amazon S3 wrapper
 * using the "s3://" scheme.
 */

namespace Drupal\s3filesystem\StreamWrapper;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\Config;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\File\MimeType\MimeTypeGuesser;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\s3filesystem\Exception\AWS\S3\UploadFailedException;
use Drupal\s3filesystem\Exception\S3FileSystemException;
use Drupal\s3filesystem\Exception\StreamWrapper\StreamModeInvalidReadWriteException;
use Drupal\s3filesystem\Exception\StreamWrapper\StreamModeInvalidXModeException;
use Drupal\s3filesystem\Exception\StreamWrapper\StreamModeNotSupportedException;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Stream\PhpStreamRequestFactory;
use Guzzle\Stream\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class S3StreamWrapper
 *
 * The stream wrapper class.
 *
 * @package   Drupal\s3filesystem\StreamWrapper\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class S3StreamWrapper implements StreamWrapperInterface {

  use StringTranslationTrait;

  /**
   * @var StreamInterface
   */
  public $body;

  /**
   * @var array
   */
  public $params;

  /**
   * @var string
   */
  public $mode;

  /**
   * Directory listing used by the dir_* methods.
   *
   * @var array
   */
  public $dir = NULL;

  /**
   * Stream context (this is set by PHP when a context is used).
   *
   * @var resource
   */
  public $context = NULL;

  /**
   * Instance URI referenced as "s3://key".
   *
   * @var string
   */
  protected $uri = NULL;

  /**
   * @var Config
   */
  protected $config;

  /**
   * The AWS SDK S3Client object.
   *
   * @var S3Client
   */
  public $s3Client = NULL;

  /**
   * @var DrupalAdaptor
   */
  public $drupalAdaptor;

  /**
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * @var MimeTypeGuesser
   */
  protected $mimeGuesser;

  /**
   * @var Request
   */
  protected $request;

  /**
   * Stream wrapper constructor.
   *
   * Creates the Aws\S3\S3Client client object and activates the options
   * specified on the S3 File System Settings page.
   *
   * @throws \Drupal\s3filesystem\Exception\S3FileSystemException
   */
  public function __construct() {

    $settings      = \Drupal::config('s3filesystem.settings');
    $drupalAdaptor = \Drupal::service('s3filesystem.client');
    $s3Client      = $drupalAdaptor->getS3Client();
    $mimeGuesser   = \Drupal::service('file.mime_type.guesser');
    $logger        = \Drupal::logger('s3filesystem');
    $request       = \Drupal::request();

    $this->setUp($request, $drupalAdaptor, $s3Client, $settings, $mimeGuesser, $logger);
  }

  /**
   * Returns the type of stream wrapper.
   *
   * @return int
   */
  public static function getType() {
    return StreamWrapperInterface::NORMAL;
  }

  /**
   * Returns the name of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper name.
   */
  public function getName() {
    return 'S3 File System';
  }

  /**
   * Returns the description of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper description.
   */
  public function getDescription() {
    return 'AWS S3 file storage stream wrapper';
  }


  /**
   * Setup the stream wrapper
   *
   * @param Request                  $request
   * @param DrupalAdaptor            $drupalAdaptor
   * @param S3Client                 $s3Client
   * @param Config                   $config
   * @param MimeTypeGuesserInterface $mimeGuesser
   * @param LoggerInterface          $logger
   */
  public function setUp(Request $request, DrupalAdaptor $drupalAdaptor, S3Client $s3Client, Config $config, MimeTypeGuesserInterface $mimeGuesser, LoggerInterface $logger = NULL) {
    $this->drupalAdaptor = $drupalAdaptor;
    $this->s3Client      = $s3Client;
    $this->config        = $config;
    $this->mimeGuesser   = $mimeGuesser;
    $this->logger        = $logger;
    $this->request       = $request;
  }

  /**
   * @return S3Client
   */
  public function getS3Client() {
    return $this->s3Client;
  }

  protected function log($message, $level = LogLevel::DEBUG, $context = array()) {
    if (!$this->logger instanceof LoggerInterface) {
      $this->logger = new NullLogger();
    }

    $this->logger->log($level, $message, $context);
  }


  /**
   * Sets metadata on the stream.
   *
   * @param string $uri
   *     A string containing the URI to the file to set metadata on.
   * @param int    $option
   *     One of:
   *     - STREAM_META_TOUCH: The method was called in response to touch().
   *     - STREAM_META_OWNER_NAME: The method was called in response to chown()
   *     with string parameter.
   *     - STREAM_META_OWNER: The method was called in response to chown().
   *     - STREAM_META_GROUP_NAME: The method was called in response to chgrp().
   *     - STREAM_META_GROUP: The method was called in response to chgrp().
   *     - STREAM_META_ACCESS: The method was called in response to chmod().
   * @param mixed  $value
   *     If option is:
   *     - STREAM_META_TOUCH: Array consisting of two arguments of the touch()
   *     function.
   *     - STREAM_META_OWNER_NAME or STREAM_META_GROUP_NAME: The name of the owner
   *     user/group as string.
   *     - STREAM_META_OWNER or STREAM_META_GROUP: The value of the owner
   *     user/group as integer.
   *     - STREAM_META_ACCESS: The argument of the chmod() as integer.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure. If $option is not
   *   implemented, FALSE should be returned.
   *
   * @see http://www.php.net/manual/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($uri, $option, $value) {
    $this->log("stream_metadata({$uri}, {$option}, {$value}) called");

    return TRUE;
  }

  /**
   * Static function to determine a file's media type.
   *
   * Uses Drupal's mimetype mapping, unless a different mapping is specified.
   *
   * @param string      $uri
   * @param null|string $mapping
   *
   * @return string
   *   Returns a string representing the file's MIME type, or
   *   'application/octet-stream' if no type cna be determined.
   */
  public function getMimeType($uri, $mapping = NULL) {
    $this->log("getMimeType($uri, $mapping) called.");

    return $this->mimeGuesser->guess($uri);
  }

  /**
   * Sets the stream resource URI. URIs are formatted as "s3://key".
   *
   * @param string $uri A string containing the URI that should be used for this instance.
   */
  public function setUri($uri) {
    $this->log("setUri($uri) called.");
    if (strlen($this->config->get('s3.keyprefix')) && strpos($uri, $this->config->get('s3.keyprefix')) === FALSE) {
      $uri = str_replace('s3://', 's3://' . $this->config->get('s3.keyprefix') . '/', $uri);
    }
    $this->uri = $uri;
  }

  /**
   * Returns the stream resource URI. URIs are formatted as "s3://key".
   *
   * @return string
   *   Returns the current URI of the instance.
   */
  public function getUri() {
    $this->log("getUri() called for {$this->uri}.");

    return $this->uri;
  }

  /**
   * Returns a web accessible URL for the resource.
   *
   * The format of the returned URL will be different depending on how the S3
   * integration has been configured on the S3 File System admin page.
   *
   * @throws S3FileSystemException
   * @return string
   *   Returns a string containing a web accessible URL for the resource.
   */
  public function getExternalUrl() {
    $this->log("getExternalUri() called for {$this->uri}.");

    $s3_filename = $this->uriToS3Filename2($this->uri);

    // Image styles support:
    // If an image derivative URL (e.g. styles/thumbnail/blah.jpg) is requested
    // and the file doesn't exist, provide a URL to s3filesystem's special version of
    // image_style_deliver(), which will create the derivative when that URL
    // gets requested.
    $path_parts = explode('/', $s3_filename);
    if ($path_parts[0] == 'styles') {
      if (!$this->getObjectMetadata($this->uri) && !$this->getObjectMetadata(str_replace($this->config->get('s3.keyprefix') . '/', '', $this->uri))) {
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
    if (strpos($s3_filename, "?") !== FALSE) {
      $this->config->set('s3.custom_host', NULL);
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

    $keyPrefix = $this->config->get('s3.keyprefix') ? trim($this->config->get('s3.keyprefix'), '/') . '/' : '';
    if ($this->config->get('s3.custom_cdn.enabled')) {
      $cdnDomain   = $this->config->get('s3.custom_cdn.domain');
      $cdnHttpOnly = (bool) $this->config->get('s3.custom_cdn.http_only');
      if ($cdnDomain && (!$cdnHttpOnly || ($cdnHttpOnly && !$this->request->isSecure()))) {
        $domain = String::checkPlain(UrlHelper::stripDangerousProtocols($cdnDomain));
        if (!$domain) {
          throw new S3FileSystemException($this->t('The "Use custom CDN" option is enabled, but no Domain Name has been set.'));
        }

        // If domain is set to a root-relative path, add the hostname back in.
        if (strpos($domain, '/') === 0) {
          $domain = $this->request->getHttpHost() . $domain;
        }
        $scheme    = $this->request->isSecure() ? 'https' : 'http';
        $cdnDomain = "$scheme://$domain";
      }

      $url = "{$cdnDomain}/{$s3_filename}";
      if (strpos($url, '/' . $keyPrefix) === FALSE && strpos($url, '/styles/') !== FALSE) {
        $url = str_replace('/styles/', '/' . $keyPrefix . 'styles/', $url);
      }
      else {
        if (strpos($url, '/' . $keyPrefix) === FALSE && strpos($url, '/styles/') === FALSE) {
          $url = str_replace($cdnDomain . '/', $cdnDomain . '/' . $keyPrefix, $url);
        }
      }
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
      $url = $this->s3Client->getObjectUrl($this->config->get('s3.bucket'), $s3_filename, $expires, $url_settings['api_args']);
      if (strpos($url, $keyPrefix) === FALSE && strpos($url, '/styles/') !== FALSE) {
        $url = str_replace('/styles/', '/' . $keyPrefix . 'styles/', $url);
      }
      else {
        if (strpos($url, $keyPrefix) === FALSE && strpos($url, '/styles/') === FALSE) {
          $url = str_replace('amazonaws.com/', 'amazonaws.com/' . $keyPrefix, $url);
        }
      }
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
   * Returns the local writable target of the resource within the stream.
   *
   * This function should be used in place of calls to realpath() or similar
   * functions when attempting to determine the location of a file. While
   * functions like realpath() may return the location of a read-only file, this
   * method may return a URI or path suitable for writing that is completely
   * separate from the URI used for reading.
   *
   * @param string $uri
   *   Optional URI.
   *
   * @return array
   *   Returns a string representing a location suitable for writing of a file,
   *   or FALSE if unable to write to the file such as with read-only streams.
   */
  protected function getTarget($uri = NULL) {
    $this->log("getTarget($uri) called.");

    if (!isset($uri)) {
      $uri = $this->uri;
    }
    $data = explode('://', $uri, 2);

    // Remove erroneous leading or trailing forward-slashes and backslashes.
    return isset($data[1]) ? trim($data[1], '\/') : FALSE;
  }

  /**
   * Gets the path that the wrapper is responsible for.
   *
   * @return string
   *   The empty string. Since this is a remote stream wrapper,
   *   it has no directory path.
   */
  public function getDirectoryPath() {
    $this->log("getDirectoryPath() called.");

    return 's3/files';
  }

  /**
   * Changes permissions of the resource.
   *
   * This wrapper doesn't support the concept of filesystem permissions.
   *
   * @param int $mode
   *   Integer value for the permissions. Consult PHP chmod() documentation
   *   for more information.
   *
   * @return bool
   *   Returns TRUE.
   */
  public function chmod($mode) {
    $octal_mode = decoct($mode);
    $this->log("chmod($octal_mode) called. S3StreamWrapper does not support this function.");

    return TRUE;
  }

  /**
   * This wrapper does not support realpath().
   *
   * @return bool
   *   Returns FALSE.
   */
  public function realpath() {
    $this->log("realpath() called for {$this->uri}. S3StreamWrapper does not support this function.");

    return FALSE;
  }

  /**
   * Gets the name of the directory from a given path.
   *
   * This method is usually accessed through drupal_dirname(), which wraps
   * around the normal PHP dirname() function, since it doesn't support stream
   * wrappers.
   *
   * @param string $uri
   *   An optional URI.
   *
   * @return string
   *   A string containing the directory name, or FALSE if not applicable.
   *
   * @see drupal_dirname()
   */
  public function dirname($uri = NULL) {
    $this->log("dirname($uri) called.");

    if (!isset($uri)) {
      $uri = $this->uri;
    }
    $target  = $this->getTarget($uri);
    $dirname = dirname($target);

    // When the dirname() call above is given 's3://', it returns '.'.
    // But 's3://.' is invalid, so we convert it to '' to get "s3://".
    if ($dirname == '.') {
      $dirname = '';
    }

    return "s3://$dirname";
  }

  /**
   * Support for fopen(), file_get_contents(), file_put_contents() etc.
   *
   * @param string $uri
   *   A string containing the URI of the file to open.
   * @param string $mode
   *   The file mode. Only "r", "w", "a", and "x" are supported.
   * @param int    $options
   *   A bit mask of STREAM_USE_PATH and STREAM_REPORT_ERRORS.
   * @param string $opened_path
   *   A string containing the path actually opened.
   *
   * @return bool
   *   Returns TRUE if file was opened successfully.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-open.php
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->log("stream_open($uri, $mode, $options, $opened_path) called.");

    try {
      $this->uri = $uri;
      // We don't care about the binary flag, so strip it out.
      $this->mode   = $mode = rtrim($mode, 'bt');
      $this->params = $this->getStreamParams($uri);
      $errors       = array();

      if (strpos($mode, '+') !== FALSE) {
        throw new StreamModeInvalidReadWriteException();
      }
      if (!in_array($mode, array('r', 'w', 'a', 'x'))) {
        throw new StreamModeNotSupportedException($mode);
      }
      // When using mode "x", validate if the file exists first.
      if ($mode == 'x' && $this->readCache('s3://' . $this->params['Key'])) {
        throw new StreamModeInvalidXModeException($uri);
      }

      if ($mode == 'r') {
        $this->openReadStream($this->params, $errors);
      }
      elseif ($mode == 'a') {
        $this->openAppendStream($this->params, $errors);
      }
      else {
        $this->openWriteStream($this->params, $errors);
      }

      return TRUE;
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Support for fclose().
   *
   * Clears the object buffer.
   *
   * @return bool
   *   TRUE
   *
   * @see http://php.net/manual/en/streamwrapper.stream-close.php
   */
  public function stream_close() {
    $this->log("stream_close() called for {$this->params['Key']}.");

    $this->body   = NULL;
    $this->params = NULL;

    return TRUE;
  }

  /**
   * This wrapper does not support flock().
   *
   * @param string $operation
   *
   * @return bool
   *   returns FALSE.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-lock.php
   */
  public function stream_lock($operation) {
    $this->log("stream_lock($operation) called. S3StreamWrapper doesn't support this function.");

    return FALSE;
  }

  /**
   * Support for fread(), file_get_contents() etc.
   *
   * @param int $count
   *   Maximum number of bytes to be read.
   *
   * @return string
   *   The string that was read, or FALSE in case of an error.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-read.php
   */
  public function stream_read($count) {
    $this->log("stream_read($count) called for {$this->uri}.");

    return $this->body->read($count);
  }

  /**
   * Support for fwrite(), file_put_contents() etc.
   *
   * @param string $data
   *   The string to be written.
   *
   * @return int
   *   The number of bytes written.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-write.php
   */
  public function stream_write($data) {
    return $this->body->write($data);
  }

  /**
   * Support for feof().
   *
   * @return bool
   *   TRUE if end-of-file has been reached.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-eof.php
   */
  public function stream_eof() {
    $this->log("stream_eof() called for {$this->params['Key']}.");

    return $this->body->feof();
  }

  /**
   * Support for fseek().
   *
   * @param int $offset
   *   The byte offset to got to.
   * @param int $whence
   *   SEEK_SET, SEEK_CUR, or SEEK_END.
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-seek.php
   */
  public function stream_seek($offset, $whence) {
    $this->log("stream_seek($offset, $whence) called.");

    return $this->body->seek($offset, $whence);
  }

  /**
   * Support for fflush(). Flush current cached stream data to a file in S3.
   *
   * @throws \Exception
   * @return bool
   *   TRUE if data was successfully stored (or there was no data to store).
   *
   * @see http://php.net/manual/en/streamwrapper.stream-flush.php
   */
  public function stream_flush() {
    $this->log("stream_flush() called for {$this->params['Key']}.");

    if ($this->mode == 'r') {
      return FALSE;
    }

    try {
      // Prep the upload parameters.
      $this->body->rewind();
      $upload_params         = $this->params;
      $upload_params['Body'] = $this->body;
      // All files uploaded to S3 must be set to public-read, or users' browsers
      // will get PermissionDenied errors, and torrent URLs won't work.
      $upload_params['ACL']         = 'public-read';
      $upload_params['ContentType'] = $this->getMimeType($this->uri);

      $this->s3Client->putObject($upload_params);
      $this->s3Client->waitUntilObjectExists($this->params);
      $metadata = $this->getMetadataFromS3($this->uri);
      if ($metadata === FALSE) {
        throw new UploadFailedException($this->uri);
      }
      $this->writeCache($metadata);
      clearstatcache(TRUE, $this->uri);

      return TRUE;
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Support for ftell().
   *
   * @return int
   *   The current offset in bytes from the beginning of file.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-tell.php
   */
  public function stream_tell() {
    $this->log("stream_tell() called.");

    return $this->body->ftell();
  }

  /**
   * Support for fstat().
   *
   * @return array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  public function stream_stat() {
    $this->log("stream_stat() called for {$this->params['Key']}.");

    $stat = fstat($this->body->getStream());
    // Add the size of the underlying stream if it is known.
    if ($this->mode == 'r' && $this->body->getSize()) {
      $stat[7] = $stat['size'] = $this->body->getSize();
    }

    return $stat;
  }

  /**
   * Cast the stream to return the underlying file resource
   *
   * @param int $cast_as
   *   STREAM_CAST_FOR_SELECT or STREAM_CAST_AS_STREAM
   *
   * @return resource
   */
  public function stream_cast($cast_as) {
    $this->log("stream_cast($cast_as) called.");

    return $this->body->getStream();
  }

  /**
   * Support for unlink().
   *
   * @param string $uri
   *   A string containing the uri to the resource to delete.
   *
   * @return bool
   *   TRUE if resource was successfully deleted, regardless of whether or not
   *   the file actually existed.
   *   FALSE if the call to S3 failed, in which case the file will not be
   *   removed from the cache.
   *
   * @see http://php.net/manual/en/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    $this->log("unlink($uri) called.");

    try {
      $this->s3Client->deleteObject($this->getStreamParams($uri));
      $this->deleteCache($uri);
      clearstatcache(TRUE, $uri);

      return TRUE;
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Support for rename().
   *
   * If $to_uri exists, this file will be overwritten. This behavior is
   * identical to the PHP rename() function.
   *
   * @param string $from_uri
   *   The uri to the file to rename.
   * @param string $to_uri
   *   The new uri for file.
   *
   * @return bool
   *   TRUE if file was successfully renamed.
   *
   * @see http://php.net/manual/en/streamwrapper.rename.php
   */
  public function rename($from_uri, $to_uri) {
    $this->log("rename($from_uri, $to_uri) called.");

    try {
      $from_params = $this->getStreamParams($from_uri);
      $to_params   = $this->getStreamParams($to_uri);
      clearstatcache(TRUE, $from_uri);
      clearstatcache(TRUE, $to_uri);

      // Add the copyObject() parameters.
      $to_params['CopySource']        = "/{$from_params['Bucket']}/" . rawurlencode($from_params['Key']);
      $to_params['MetadataDirective'] = 'COPY';
      $to_params['ACL']               = 'public-read';

      // Copy the original object to the specified destination.
      $this->s3Client->copyObject($to_params);
      // Copy the original object's metadata.
      $metadata        = $this->readCache($from_uri);
      $metadata['uri'] = $to_uri;
      $this->writeCache($metadata);

      // Delete the original object.
      return $this->unlink($from_uri);
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Support for mkdir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to create.
   * @param int    $mode
   *   Permission flags - see mkdir().
   * @param int    $options
   *   A bit mask of STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
   *
   * @return bool
   *   TRUE if directory was successfully created.
   *
   * @see http://php.net/manual/en/streamwrapper.mkdir.php
   */
  public function mkdir($uri, $mode, $options) {
    $this->log("mkdir($uri, $mode, $options) called.");

    clearstatcache(TRUE, $uri);
    // If this URI already exists in the cache, return TRUE if it's a folder
    // (so that recursive calls won't improperly report failure when they
    // reach an existing ancestor), or FALSE if it's a file (failure).
    $test_metadata = $this->readCache($uri);
    if ($test_metadata) {
      return (bool) $test_metadata['dir'];
    }

    // S3 is a flat file system, with no concept of directories (just files
    // with slashes in their names). We store folders in the metadata cache,
    // but don't create anything in S3.
    $metadata              = $this->drupalAdaptor->convertMetadata($uri, array());
    $metadata['timestamp'] = date('U', time());
    $this->writeCache($metadata);

    // If the STREAM_MKDIR_RECURSIVE option was specified, also create all the
    // ancestor folders of this uri.
    $parent_dir = drupal_dirname($uri);
    if (($options & STREAM_MKDIR_RECURSIVE) && $parent_dir != 's3://') {
      return $this->mkdir($parent_dir, $mode, $options);
    }

    return TRUE;
  }

  /**
   * Support for rmdir().
   *
   * @param string $uri
   *   A string containing the URI to the folder to delete.
   * @param int    $options
   *   A bit mask of STREAM_REPORT_ERRORS.
   *
   * @return bool
   *   TRUE if folder is successfully removed.
   *   FALSE if $uri isn't a folder, or the folder is not empty.
   *
   * @see http://php.net/manual/en/streamwrapper.rmdir.php
   */
  public function rmdir($uri, $options) {
    $this->log("rmdir($uri, $options) called.");


    try {
      if (!$this->isUriDir($uri)) {
        return FALSE;
      }

      // We need a version of the URI with no / (folders are cached with no /),
      // and a version with the /, in case it's an object in S3, and to
      // differentiate it from files with this folder's name as a substring.
      // e.g. rmdir('s3://foo/bar') should ignore s3://foo/barbell.jpg.
      $bare_uri  = rtrim($uri, '/');
      $slash_uri = $bare_uri . '/';

      // Check if the folder is empty.
      $files = db_select('file_s3filesystem', 's')
        ->fields('s')
        ->condition('uri', db_like($slash_uri) . '%', 'LIKE')
        ->execute()
        ->fetchAllKeyed();

      // If the folder is empty, it's eligible for deletion.
      if (empty($files)) {
        $result = $this->deleteCache($bare_uri);
        clearstatcache(TRUE, $bare_uri);

        // Also delete the object from S3, if it's there.
        $params = $this->getStreamParams($slash_uri);
        if ($this->s3Client->doesObjectExist($params['Bucket'], $params['Key'])) {
          $this->s3Client->deleteObject($params);
        }

        return (bool) $result;
      }

      // The folder is non-empty.
      return FALSE;
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Support for stat().
   *
   * @param string $uri
   *   A string containing the URI to get information about.
   * @param int    $flags
   *   A bit mask of STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
   *   S3StreamWrapper ignores this value.
   *
   * @return array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.url-stat.php
   */
  public function url_stat($uri, $flags) {
    $this->log("url_stat($uri, $flags) called.");

    return $this->stat($uri);
  }

  /**
   * Support for opendir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to open.
   * @param int    $options
   *   A flag used to enable safe_mode.
   *   This parameter is ignored: this wrapper doesn't support safe_mode.
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-opendir.php
   */
  public function dir_opendir($uri, $options = NULL) {
    $this->log("dir_opendir($uri, $options) called.");

    if (!$this->isUriDir($uri)) {
      return FALSE;
    }

    $bare_uri  = rtrim($uri, '/');
    $slash_uri = $bare_uri . '/';

    // If this URI was originally s3://, the above code removed *both* slashes
    // but only added one back. So we need to add back the second slash.
    if ($slash_uri == 's3:/') {
      $slash_uri = 's3://';
    }

    // Get the list of uris for files and folders which are children of the
    // specified folder, but not grandchildren.
    $and = db_and();
    $and->condition('uri', db_like($slash_uri) . '%', 'LIKE');
    $and->condition('uri', db_like($slash_uri) . '%/%', 'NOT LIKE');
    $child_uris = db_select('file_s3filesystem', 's')
      ->fields('s', array('uri'))
      ->condition($and)
      ->execute()
      ->fetchCol(0);

    $this->dir = array();
    foreach ($child_uris as $child_uri) {
      $this->dir[] = basename($child_uri);
    }

    return TRUE;
  }

  /**
   * Support for readdir().
   *
   * @return string
   *   The next filename, or FALSE if there are no more files in the directory.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-readdir.php
   */
  public function dir_readdir() {
    $this->log("dir_readdir() called.");

    $entry = each($this->dir);

    return $entry ? $entry['value'] : FALSE;
  }

  /**
   * Support for rewinddir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-rewinddir.php
   */
  public function dir_rewinddir() {
    $this->log("dir_rewinddir() called.");

    reset($this->dir);

    return TRUE;
  }

  /**
   * Support for closedir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-closedir.php
   */
  public function dir_closedir() {
    $this->log("dir_closedir() called.");

    unset($this->dir);

    return TRUE;
  }

  /**
   * Convert a URI into a valid S3 filename.
   *
   * @param string $uri
   *
   * @return string
   */
  protected function uriToS3Filename($uri) {
    $filename = str_replace('s3://', '', $uri);
    if (strpos($filename, $this->config->get('s3.keyprefix')) === FALSE) {
      $filename = rtrim($this->config->get('s3.keyprefix'), '/') . '/' . $filename;
    }

    // Remove both leading and trailing /s. S3 filenames never start with /,
    // and a $uri for a folder might be specified with a trailing /, which
    // we'd need to remove to be able to retrieve it from the cache.
    return trim($filename, '/');
  }

  /**
   * Convert a URI into a valid S3 filename.
   *
   * @param string $uri
   *
   * @return string
   */
  protected function uriToS3Filename2($uri) {
    $filename = str_replace('s3://' . $this->config->get('s3.keyprefix') . '/', '', $uri);
    // Remove both leading and trailing /s. S3 filenames never start with /,
    // and a $uri for a folder might be specified with a trailing /, which
    // we'd need to remove to be able to retrieve it from the cache.
    return trim($filename, '/');
  }

  /**
   * Get the status of the file with the specified URI.
   *
   * @param string $uri
   *
   * @return array
   *   An array with file status, or FALSE if the file doesn't exist.
   *   See fstat() for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  protected function stat($uri) {
    $this->log("stat($uri) called.");

    $metadata = $this->getObjectMetadata($uri);
    if ($metadata) {
      $stat     = array();
      $stat[0]  = $stat['dev'] = 0;
      $stat[1]  = $stat['ino'] = 0;
      $stat[2]  = $stat['mode'] = $metadata['mode'];
      $stat[3]  = $stat['nlink'] = 0;
      $stat[4]  = $stat['uid'] = 0;
      $stat[5]  = $stat['gid'] = 0;
      $stat[6]  = $stat['rdev'] = 0;
      $stat[7]  = $stat['size'] = 0;
      $stat[8]  = $stat['atime'] = 0;
      $stat[9]  = $stat['mtime'] = 0;
      $stat[10] = $stat['ctime'] = 0;
      $stat[11] = $stat['blksize'] = 0;
      $stat[12] = $stat['blocks'] = 0;

      if (!$metadata['dir']) {
        $stat[4]  = $stat['uid'] = $metadata['uid'];
        $stat[7]  = $stat['size'] = $metadata['filesize'];
        $stat[8]  = $stat['atime'] = $metadata['timestamp'];
        $stat[9]  = $stat['mtime'] = $metadata['timestamp'];
        $stat[10] = $stat['ctime'] = $metadata['timestamp'];
      }

      return $stat;
    }

    return FALSE;
  }

  /**
   * Determine whether the $uri is a directory.
   *
   * @param string $uri
   *   A string containing the uri to the resource to check. If none is given
   *   defaults to $this->uri
   *
   * @return bool
   *   TRUE if the resource is a directory
   */
  protected function isUriDir($uri) {
    if ($uri == 's3://' || $uri == 's3:') {
      return TRUE;
    }

    // Folders only exist in the cache, so we don't need to query S3.
    // Since they're stored with no ending slash, so we need to trim it.
    $uri      = rtrim($uri, '/');
    $metadata = $this->readCache($uri);

    return $metadata ? $metadata['dir'] : FALSE;
  }

  /**
   * Try to fetch an object from the metadata cache.
   *
   * If that file isn't in the cache, we assume it doesn't exist.
   *
   * @param string $uri
   *   A string containing the uri of the resource to check.
   *
   * @return bool
   *   An array if the $uri exists, otherwise FALSE.
   */
  protected function getObjectMetadata($uri) {
    $this->log("getObjectMetadata($uri) called.");

    try {
      // For the root directory, just return metadata for a generic folder.
      if ($uri == 's3://' || $uri == 's3:') {
        return $this->drupalAdaptor->convertMetadata('/', array());
      }

      // Trim any trailing '/', in case this is a folder request.
      $uri = rtrim($uri, '/');

      // Check if this URI is in the cache.
      $metadata = $this->readCache($uri);

      // If cache ignore is enabled, query S3 for all URIs which aren't in the
      // cache, and non-folder URIs which are.
      if (!$this->config->get('s3.ignore_cache') && !$metadata['dir']) {
        // If getMetadataFromS3() returns FALSE, the file doesn't exist.
        $metadata = $this->getMetadataFromS3($uri);
      }

      return $metadata;
    }
    catch(\Exception $e) {
      $this->handleException($e);

      return FALSE;
    }
  }

  /**
   * Fetch an object from the file metadata cache table.
   *
   * @param string $uri
   *   A string containing the uri of the resource to check.
   *
   * @return array
   *   An array of metadata if the $uri is in the cache, otherwise FALSE.
   */
  protected function readCache($uri) {
    $this->log("readCache($uri) called.");

    $record = db_select('file_s3filesystem', 's')
      ->fields('s')
      ->condition('uri', $uri, '=')
      ->execute()
      ->fetchAssoc();

    return $record ? $record : FALSE;
  }

  /**
   * Write an object's (and its ancestor folders') metadata to the cache.
   *
   * @param array $metadata
   *     An associative array of file metadata, in this format:
   *     'uri' => The full URI of the file, including 's3://'.
   *     'filesize' => The size of the file, in bytes.
   *     'timestamp' => The file's create/update timestamp.
   *     'dir' => A boolean indicating whether the object is a directory.
   *     'mode' => The octal mode of the file.
   *     'uid' => The uid of the owner of the S3 object.
   *
   * @throws
   *   Exceptions which occur in the database call will percolate.
   */
  protected function writeCache($metadata) {
    $this->log("writeCache({$metadata['uri']}) called.");

    db_merge('file_s3filesystem')
      ->key(array('uri' => $metadata['uri']))
      ->fields($metadata)
      ->execute();

    $dirname = $this->dirname($metadata['uri']);
    if ($dirname != 's3://') {
      $this->mkdir($dirname, NULL, STREAM_MKDIR_RECURSIVE);
    }
  }

  /**
   * Delete an object's metadata from the cache.
   *
   * @param mixed $uri
   *   A string (or array of strings) containing the URI(s) of the object(s)
   *   to be deleted.
   *
   * @return StatementInterface|null
   * @throws
   *   Exceptions which occur in the database call will percolate.
   */
  protected function deleteCache($uri) {
    $this->log("deleteCache($uri) called.");

    $delete_query = db_delete('file_s3filesystem');
    if (is_array($uri)) {
      // Build an OR condition to delete all the URIs in one query.
      $or = db_or();
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

  /**
   * Get the stream context options available to the current stream.
   *
   * @return array
   */
  protected function getStreamOptions() {
    $context = isset($this->context) ? $this->context : stream_context_get_default();
    $options = stream_context_get_options($context);

    return isset($options['s3']) ? $options['s3'] : array();
  }

  /**
   * Get a specific stream context option
   *
   * @param string $name Name of the option to retrieve
   *
   * @return mixed|null
   */
  protected function getStreamOption($name) {
    $options = $this->getStreamOptions();

    return isset($options[$name]) ? $options[$name] : NULL;
  }

  /**
   * Get the Command parameters for the specified URI.
   *
   * @param string $uri
   *   The URI of the file.
   *
   * @return array
   *   A Command parameters array, including 'Bucket', 'Key', and
   *   context params.
   */
  protected function getStreamParams($uri) {
    $params = $this->getStreamOptions();
    unset($params['seekable']);
    unset($params['throw_exceptions']);

    $params['Bucket'] = $this->config->get('s3.bucket');
    // Strip s3:// from the URI to get the S3 Key.
    $params['Key'] = $this->uriToS3Filename($uri);

    return $params;
  }

  /**
   * Initialize the stream wrapper for a read only stream.
   *
   * @param array $params
   *   A Command parameters array.
   * @param array $errors
   *   Array to which encountered errors should be appended.
   *
   * @return bool
   */
  protected function openReadStream($params, &$errors) {
    $this->log("openReadStream({$params['Key']}) called.");

    // Create the command and serialize the request.
    $request = $this->getSignedRequest($this->s3Client->getCommand('GetObject', $params));
    // Create a stream that uses the EntityBody object.
    $factory = $this->getStreamOption('stream_factory');
    if (empty($factory)) {
      $factory = new PhpStreamRequestFactory();
    }
    $body = $factory->fromRequest($request, array(), array('stream_class' => 'Guzzle\Http\EntityBody'));

    // Wrap the body in an S3SeekableCachingEntityBody, so that seeks can
    // go to not-yet-read sections of the file.
    $this->body = new S3SeekableCachingEntityBody($body);

    return TRUE;
  }

  /**
   * Initialize the stream wrapper for an append stream.
   *
   * @param array $params
   *   A Command parameters array.
   * @param array $errors
   *   Array to which encountered errors should be appended.
   *
   * @return bool
   */
  protected function openAppendStream($params, &$errors) {
    $this->log("openAppendStream({$params['Key']}) called.");

    try {
      // Get the body of the object
      $this->body = $this->s3Client->getObject($params)->get('Body');
      $this->body->seek(0, SEEK_END);
    }
    catch(S3Exception $e) {
      // The object does not exist, so use a simple write stream.
      $this->openWriteStream($params, $errors);
    }

    return TRUE;
  }

  /**
   * Initialize the stream wrapper for a write only stream.
   *
   * @param array $params
   *   A Command parameters array.
   * @param array $errors
   *   Array to which encountered errors should be appended.
   *
   * @return bool
   */
  protected function openWriteStream($params, &$errors) {
    $this->log("openWriteStream({$params['Key']}) called.");

    $this->body = new EntityBody(fopen('php://temp', 'r+'));
  }

  /**
   * Serialize and sign a command, returning a request object
   *
   * @param CommandInterface $command Command to sign
   *
   * @return RequestInterface
   */
  protected function getSignedRequest($command) {
    $this->log("getSignedRequest() called.");

    $request = $command->prepare();
    $request->dispatch('request.before_send', array('request' => $request));

    return $request;
  }

  /**
   * Returns the converted metadata for an object in S3.
   *
   * @param string $uri
   *   The URI for the object in S3.
   *
   * @return array
   *   An array of DB-compatible file metadata.
   *
   * @throws \Exception
   *   Any exception raised by the listObjects() S3 command will percolate
   *   out of this function.
   */
  function getMetadataFromS3($uri) {
    $this->log("getMetadataFromS3($uri) called.");

    $params = $this->getStreamParams($uri);
    // I wish we could call headObject(), rather than listObjects(), but
    // headObject() doesn't return the object's owner ID.
    $result = $this->s3Client->listObjects(array(
      'Bucket'  => $params['Bucket'],
      'Prefix'  => $params['Key'],
      'MaxKeys' => 1,
    ));

    // $result['Contents'][0] is the s3 metadata. If it's unset, there is no
    // file in S3 matching this URI.
    if (isset($result['Contents'][0])) {
      return $this->drupalAdaptor->convertMetadata($uri, $result['Contents'][0]);
    }

    return FALSE;
  }

  /**
   * Triggers one or more errors.
   *
   * @param \Exception $e     The thrown Exception
   * @param mixed      $flags If set to STREAM_URL_STAT_QUIET, then no error or exception occurs.
   *
   * @throws \Exception
   */
  protected function handleException(\Exception $e, $flags = NULL) {
    $this->log('Error: ' . $e->getMessage());

    if ($flags != STREAM_URL_STAT_QUIET) {
      if ($this->getStreamOption('throw_exceptions')) {
        throw $e;
      }
      else {
        trigger_error($e->getMessage(), E_USER_WARNING);
      }
    }
  }
}


