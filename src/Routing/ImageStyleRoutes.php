<?php

namespace Drupal\s3filesystem\Routing;

use Symfony\Component\Routing\Route;

/**
 * Class ImageStyleRoutes
 * Defines a route subscriber to register a url for serving image styles.
 *
 * @package   Drupal\s3filesystem\Routing
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) ${YEAR}
 */
class ImageStyleRoutes {

  /**
   * Returns an array of route objects.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   An array of route objects.
   */
  public function routes() {
    $routes = array();

    $routes['image.style_s3'] = new Route(
      '/s3/files/styles/{image_style}/{path}',
      array(
        '_controller' => 'Drupal\s3filesystem\Controller\S3FileSystemController::deliver',
      ),
      array(
        '_access' => 'TRUE',
        'path'    => '.+'
      )
    );

    return $routes;
  }

}
