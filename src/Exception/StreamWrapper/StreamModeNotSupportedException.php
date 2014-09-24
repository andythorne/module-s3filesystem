<?php

namespace Drupal\s3fs\Exception\StreamWrapper;

/**
 * Class ModeNotSupportedException
 *
 * @author Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class StreamModeNotSupportedException extends \RuntimeException
{
    public function __construct($mode, \Exception $previous = null)
    {
        $message = \Drupal::translation()->translate("Mode not supported: %mode. Use one 'r', 'w', 'a', or 'x'.", array('%mode' => $mode));
        parent::__construct($message, null, $previous);
    }
} 
