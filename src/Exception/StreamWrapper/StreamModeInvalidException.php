<?php

namespace Drupal\s3fs\Exception\StreamWrapper;

/**
 * Class ModeNotSupportedException
 *
 * @author Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class StreamModeInvalidException extends \RuntimeException
{
    public function __construct($reason, \Exception $previous = null)
    {
        $message = \Drupal::translation()->translate("The S3 File System stream wrapper is invalid: %reason", array('%reason' => $reason));
        parent::__construct($message, null, $previous);
    }
} 
