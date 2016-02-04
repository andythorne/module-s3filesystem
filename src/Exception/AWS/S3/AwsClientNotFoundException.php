<?php

namespace Drupal\s3filesystem\Exception\Aws\S3;

use Drupal\s3filesystem\Exception\S3FileSystemException;

/**
 * Class AwsClientNotFoundException
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class AwsClientNotFoundException extends S3FileSystemException {
  public function __construct(\Exception $previous = NULL) {
    $message = \Drupal::translation()
      ->translate("Cannot load Aws\\S3\\S3Client class. Please ensure that the awssdk2 is installed.");
    parent::__construct($message, NULL, $previous);
  }
} 
