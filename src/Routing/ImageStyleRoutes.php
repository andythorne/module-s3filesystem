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
    // Generate image derivatives of publicly available files. If clean URLs are
    // disabled image derivatives will always be served through the menu system.
    // If clean URLs are enabled and the image derivative already exists, PHP
    // will be bypassed.
    $directory_path = file_stream_wrapper_get_instance_by_scheme('s3')->getDirectoryPath();

    $routes['image.style_s3'] = new Route(
      '/' . $directory_path . '/styles/{image_style}/{path}',
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
