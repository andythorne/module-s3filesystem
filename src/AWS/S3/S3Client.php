<?php

namespace Drupal\s3filesystem\AWS\S3;

use Aws\CommandInterface;
use Aws\Result;
use Drupal\s3filesystem\StreamWrapper\Body\SeekableCachingStream;
use Psr\Http\Message\StreamInterface;

/**
 * Class S3Client
 * @package Drupal\s3filesystem\AWS\S3
 */
class S3Client extends \Aws\S3\S3Client {

  public function execute(CommandInterface $command) {
    $result = parent::execute($command);

    if($result instanceof Result){
      if($result->hasKey('Body')){
        $body = $result->get('Body');
        if($body instanceof StreamInterface){
          $result->offsetSet('Body', new SeekableCachingStream($body));
        }
      }
    }

    return $result;
  }

}
