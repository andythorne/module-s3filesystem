<?php

namespace Drupal\s3filesystem\Exception\AWS\S3;

use Drupal\s3filesystem\Exception\S3FileSystemException;

/**
 * Class AwsCredentialsInvalidException
 *
 * @author Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class AwsCredentialsInvalidException extends S3FileSystemException
{
    public function __construct($error, \Exception $previous = null)
    {
        $message = \Drupal::translation()->translate("Your AWS credentials have not been properly configured: {$error}");
        parent::__construct($message, null, $previous);
    }
} 
