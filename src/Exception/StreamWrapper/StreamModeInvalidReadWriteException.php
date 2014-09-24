<?php

namespace Drupal\s3fs\Exception\StreamWrapper;

/**
 * Class ModeNotSupportedException
 *
 * @author Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class StreamModeInvalidReadWriteException extends StreamModeInvalidException
{
    public function __construct(\Exception $previous = null)
    {
        parent::__construct('Cannot simultaneously read and write.', null, $previous);
    }
} 
