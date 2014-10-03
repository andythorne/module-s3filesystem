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
class S3FileSystemController extends ControllerBase
{

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
    public function __construct(LockBackendInterface $lock, LoggerInterface $logger)
    {
        $this->lock   = $lock;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('lock'),
            $container->get('logger.factory')->get('s3filesystem')
        );
    }

    public function deliver(Request $request, ImageStyleInterface $image_style, $path)
    {
        $parts  = explode('/', $path);
        $scheme = array_shift($parts);
        $s3Path = implode('/', $parts);

        $image_uri = "{$scheme}://{$s3Path}";

        $row = db_select('{file_s3filesystem}', 'f')
            ->fields('f', array('uri'))
            ->where('uri = ? AND dir = 0', array($image_uri))
            ->execute();

        $derivative_uri        = $image_style->buildUri($image_uri);
        $derivative_public_uri = file_create_url($derivative_uri);

        if(!$image = $row->fetchAssoc())
        {
            return $this->redirectToAWS($derivative_public_uri);
        }

        // Don't start generating the image if the derivative already exists or if
        // generation is in progress in another thread.
        $lock_name = 'image_style_deliver:' . $path . ':' . Crypt::hashBase64($image_uri);
        if(!file_exists($derivative_uri))
        {
            $lock_acquired = $this->lock->acquire($lock_name);
            if(!$lock_acquired)
            {
                // Tell client to retry again in 3 seconds. Currently no browsers are
                // known to support Retry-After.
                throw new ServiceUnavailableHttpException(3, $this->t('Image generation in progress. Try again shortly.'));
            }
        }

        // Try to generate the image, unless another thread just did it while we
        // were acquiring the lock.
        $success = file_exists($derivative_uri) || $image_style->createDerivative($image_uri, $derivative_uri);

        if(!empty($lock_acquired))
        {
            $this->lock->release($lock_name);
        }

        if($success)
        {
            return $this->redirectToAWS($derivative_public_uri);
        }
        else
        {
            $this->logger->notice('Unable to generate the derived image located at %path.', array('%path' => $derivative_uri));

            return new Response($this->t('Error generating image.'), 500);
        }
    }

    private function redirectToAWS($uri)
    {
        return new Response(null, 302, array(
            'location' => $uri
        ));
    }
}
