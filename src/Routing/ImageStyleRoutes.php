<?php

namespace Drupal\s3fs\Routing;

use Symfony\Component\Routing\Route;

/**
 * Defines a route subscriber to register a url for serving image styles.
 */
class ImageStyleRoutes
{

    /**
     * Returns an array of route objects.
     *
     * @return \Symfony\Component\Routing\Route[]
     *   An array of route objects.
     */
    public function routes()
    {
        $routes = array();
        // Generate image derivatives of publicly available files. If clean URLs are
        // disabled image derivatives will always be served through the menu system.
        // If clean URLs are enabled and the image derivative already exists, PHP
        // will be bypassed.
        $directory_path = file_stream_wrapper_get_instance_by_scheme('s3')->getDirectoryPath();

        $routes['image.style_s3'] = new Route(
            '/' . $directory_path . '/styles/{image_style}/{path}',
            array(
                '_controller' => 'Drupal\s3fs\Controller\S3fsController::deliver',
            ),
            array(
                '_access' => 'TRUE',
                'path'    => '.+'
            )
        );

        return $routes;
    }

}
