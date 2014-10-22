<?php

namespace Drupal\s3filesystem\Exception\StreamWrapper;

/**
 * Class StreamModeInvalidReadWriteException
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class StreamModeInvalidReadWriteException extends StreamModeInvalidException {
  public function __construct(\Exception $previous = NULL) {
    parent::__construct('Cannot simultaneously read and write.', NULL, $previous);
  }
} 
