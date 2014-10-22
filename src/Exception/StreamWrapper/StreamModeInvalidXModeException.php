<?php

namespace Drupal\s3filesystem\Exception\StreamWrapper;

/**
 * Class StreamModeInvalidXModeException
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class StreamModeInvalidXModeException extends StreamModeInvalidException {
  public function __construct($uri, \Exception $previous = NULL) {
    $message = \Drupal::translation()
      ->translate("%uri already exists in your S3 bucket, so it cannot be opened with mode 'x'.", array('%uri' => $uri));
    parent::__construct($message, NULL, $previous);
  }
} 
