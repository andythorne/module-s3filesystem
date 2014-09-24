<?php

namespace Drupal\s3fs\AWS\S3;

use Aws\S3\S3Client;
use Drupal\Core\Config\Config;
use Drupal\s3fs\Exception\S3fsException;

/**
 * Class ClientFactory
 *
 * @package   Drupal\s3fs\AWS\S3
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class ClientFactory
{

    public static function create(Config $s3fsConfig = null)
    {
        if(!$s3fsConfig instanceof Config)
        {
            $s3fsConfig = \Drupal::config('s3fs.settings');
        }

        $awsConfig = $s3fsConfig->get('aws');
        $s3Config  = $s3fsConfig->get('s3');

        $use_instance_profile = $awsConfig['use_instance_profile'];
        $access_key           = $awsConfig['access_key'];
        $secret_key           = $awsConfig['secret_key'];
        $default_cache_config = $awsConfig['default_cache_config'];

        if(!class_exists('Aws\S3\S3Client'))
        {
            throw new S3fsException(t('Cannot load Aws\S3\S3Client class. Please ensure that the awssdk2 library is installed correctly.'));
        }
        elseif(!$use_instance_profile && (!$secret_key || !$access_key))
        {
            throw new S3fsException(t("Your AWS credentials have not been properly configured. Please set them on the S3 File System !settings_page",
                    array('!settings_page' => l(t('Settings Page'), 'admin/config/media/s3fs/settings')))
            );
        }
        elseif($use_instance_profile && empty($default_cache_config))
        {
            throw new s3fsException(t("Your AWS credentials have not been properly configured.
        You are attempting to use instance profile credentials but you have not set a default cache location.
        Please set it on the !settings_page",
                    array('!settings_page' => l(t('Settings Page'), 'admin/config/media/s3fs/settings')))
            );
        }

        // Create and configure the S3Client object.
        $config = array();
        if($use_instance_profile)
        {
            $config['default_cache_config'] = $default_cache_config;
        }
        else
        {
            $config['key']    = $access_key;
            $config['secret'] = $secret_key;
        }

        if($awsConfig['proxy']['enabled'])
        {
            $config['request.options'] = array(
                'proxy'           => $awsConfig['proxy']['host'],
                'timeout'         => $awsConfig['timeout'],
                'connect_timeout' => $awsConfig['connect_timeout']
            );
        }

        $s3 = S3Client::factory($config);
        if(!empty($s3Config['region']))
        {
            $s3->setRegion($s3Config['region']);
        }

        if($s3Config['custom_host']['enabled'] && !empty($s3Config['custom_host']['hostname']))
        {
            $s3->setBaseURL($s3Config['custom_host']['hostname']);
        }

        return $s3;
    }

} 
