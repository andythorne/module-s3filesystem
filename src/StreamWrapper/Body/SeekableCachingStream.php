<?php

namespace Drupal\s3filesystem\StreamWrapper\Body;

use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * Class SeekableCachingStream
 *
 * @package   Drupal\s3filesystem\SteamWrapper\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 *
 * A replacement class for CachingEntityBody that serves better for s3filesystem.
 *
 * Any instantiation of this class must be wrapped in a check for its
 * existence, since it may not be defined under certain circumstances.
 */
class SeekableCachingStream extends CachingStream {

  /** @var StreamInterface Stream being wrapped */
  private $remoteStream;

  /**
   * @inheritDoc
   */
  public function __construct(
    StreamInterface $stream,
    StreamInterface $target = null
  ) {
    $this->remoteStream = $stream;
    if(!$target){
      $target = new Stream(fopen('php://temp', 'r+'));
    }

    parent::__construct($stream, $target);
  }

  /**
   * This version of seek() allows seeking past the end of the cache.
   *
   * If the caller attempts to seek more than 50 megs into the file,
   * though, an exception will be thrown, because that would take up too
   * much memory.
   *
   * @param int $offset
   * @param int $whence
   *
   * @return bool
   */
  public function seek($offset, $whence = SEEK_SET) {

    if ($whence == SEEK_SET) {
      $byte = $offset;
    }
    else {
      if ($whence == SEEK_CUR) {
        $byte = $offset + $this->tell();
      }
      else {
        throw new \RuntimeException(__CLASS__ . ' supports only SEEK_SET and SEEK_CUR seek operations');
      }
    }

    if ($byte > 52428800) {
      throw new \RuntimeException(
        "Seeking more than 50 megabytes into a remote file is not supported, due to memory constraints.
                  If you need to bypass this error, please contact the maintainers of S3 File System."
      );
    }

    // If the caller tries to seek past the end of the currently cached
    // data, read in enough of the remote stream to let the seek occur.
    while ($byte > $this->stream->getSize() && !($this->stream->eof() && $this->remoteStream->eof())) {
      $this->read(16384);
    }

    return $this->stream->seek($byte);
  }
}
