<?php

namespace Drupal\s3filesystem\Exception\AWS\S3;

/**
 * Class UploadFailedException
 *
 * @author Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class UploadFailedException extends \Exception
{
    public function __construct($uri, \Exception $previous = null)
    {
        $message = \Drupal::translation()->translate("Uploading the file %uri to S3 failed", array('%uri' => $uri));
        parent::__construct($message, null, $previous);
    }
} 
