<?php

namespace Drupal\s3filesystem\Controller;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\image\ImageStyleInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Class S3FileSystemController
 *
 * @package   Controller
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class S3FileSystemController extends ControllerBase {

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ImageStyleDownloadController object.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Psr\Log\LoggerInterface               $logger
   *   A logger instance.
   */
  public function __construct(LockBackendInterface $lock, LoggerInterface $logger) {
    $this->lock   = $lock;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lock'),
      $container->get('logger.factory')->get('s3filesystem')
    );
  }

  public function deliver(Request $request, ImageStyleInterface $image_style, $path) {
    $parts  = explode('/', $path);
    $scheme = array_shift($parts);
    $s3Path = implode('/', $parts);

    $imageUri = "{$scheme}://{$s3Path}";

    // check the base image exists in the cache table. If we have no base image,
    // check s3 (via file_exists). If s3 has no image, 404.
    $row = db_select('{file_s3filesystem}', 'f')
      ->fields('f', array('uri'))
      ->where('uri = ? AND dir = 0', array($imageUri))
      ->execute();

    if ((!$image = $row->fetchAssoc()) && !file_exists($imageUri)) {
      return new Response(NULL, 404);
    }

    $derivativeUri    = $image_style->buildUri($imageUri);
    $derivativeExists = file_exists($derivativeUri);

    // Don't start generating the image if the derivative already exists or if
    // generation is in progress in another thread.
    if (!$derivativeExists) {
      $lockName     = 'image_style_deliver:' . $path . ':' . Crypt::hashBase64($imageUri);
      $lockAcquired = $this->lock->acquire($lockName);
      if (!$lockAcquired) {
        // Tell client to retry again in 3 seconds. Currently no browsers are
        // known to support Retry-After.
        throw new ServiceUnavailableHttpException(3, $this->t('Image generation in progress. Try again shortly.'));
      }

      $derivativeExists = $image_style->createDerivative($imageUri, $derivativeUri);


var_dump($derivativeExists, is_dir("s3://styles/large/s3"), file_exists($derivativeExists), $imageUri, $derivativeUri);
      $this->lock->release($lockName);
    }

    if ($derivativeExists) {
      return $this->redirectToAWS($derivativeUri);
    }
    else {
      $this->logger->notice('Unable to generate the derived image located at %path.', array('%path' => $derivativeUri));

      return new Response($this->t('Error generating image.'), 500);
    }
  }

  /**
   * Redirect the user to aws
   *
   * @param $uri
   *
   * @return Response
   */
  private function redirectToAWS($uri) {
    return new Response(NULL, 302, array(
      'location'      => file_create_url($uri),
      'Cache-Control' => 'must-revalidate, no-cache, post-check=0, pre-check=0, private',
      'Expires'       => 'Sun, 19 Nov 1978 05:00:00 GMT',
    ));
  }
}
