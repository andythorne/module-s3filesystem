<?php

namespace Drupal\s3fs\StreamWrapper;

use Drupal\s3fs\Exception\S3fsException;


/**
 * Class Configuration
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class Configuration
{

    /**
     * Domain we use to access files over http.
     *
     * @var string
     */
    public $domain = null;

    /**
     * @var boolean
     */
    public $useHttps;

    /**
     * Map for files that should be delivered with a torrent URL.
     *
     * @var array
     */
    public $torrents = array();

    /**
     * Files that the user has said must be downloaded, rather than viewed.
     *
     * @var array
     */
    public $saveas = array();

    /**
     * Files which should be created with URLs that eventually time out.
     *
     * @var array
     */
    public $presignedURLs = array();

    /**
     * @var array
     */
    public $s3Config;

    /**
     * @var bool
     */
    public $configured = false;

    /**
     * Configure the configuration
     *
     * @throws S3fsException
     */
    public function configure()
    {
        $config  = \Drupal::config('s3fs.settings');
        $request = \Drupal::request();
        $logger  = \Drupal::logger('s3fs');

        $logger->debug('Building Stream Configuration');

        $this->s3Config    = $config->get('s3');
        $this->domain      = $this->s3Config['custom_cdn']['enabled'] ? $this->s3Config['custom_cdn']['domain'] : null;
        $this->useHttps    = $this->s3Config['force_https'];

        if(!$this->s3Config['bucket'])
        {
            $msg = t('Your AmazonS3 bucket name is not configured. Please visit the !settings_page.',
                array('!settings_page' => l(t('Configuration Page'), '/admin/config/media/s3fs/settings')));
            $logger->error($msg);
            throw new S3fsException($msg);
        }

        // Always use HTTPS when the page is being served via HTTPS, to avoid
        // complaints from the browser about insecure content.
        if($request->isSecure())
        {
            // We change the config itself, rather than simply using $is_https in
            // the following if condition, because $this->s3Config['force_https'] gets
            // used again later.
            $this->useHttps = true;
        }

        $scheme = $this->useHttps ? 'https' : 'http';
        $logger->debug('Using ' . $scheme);

        // Custom CDN support for customizing S3 URLs.
        // If custom_cdn is not enabled or http_only is enabled and the protcol is https,
        // the file URLs do not use $this->domain.
        $customCDN = $this->s3Config['custom_cdn'];
        if($customCDN['enabled'] && $customCDN['domain'] && (!$customCDN['http_only'] || ($customCDN['http_only'] && !$request->isSecure())))
        {
            $domain = check_url($customCDN['domain']);
            if($domain)
            {
                // If domain is set to a root-relative path, add the hostname back in.
                if(strpos($domain, '/') === 0)
                {
                    $domain = $request->getHttpHost() . $domain;
                }
                $this->domain = "$scheme://$domain";
            }
            else
            {
                // Due to the config form's validation, this shouldn't ever happen.
                throw new S3fsException(t('The "Use custom CDN" option is enabled, but no Domain Name has been set.'));
            }
        }

        // Convert the torrents string to an array.
        foreach($this->s3Config['torrents'] as $line)
        {
            $blob = trim($line);
            if($blob)
            {
                $this->torrents[] = $blob;
            }
        }

        // Convert the presigned URLs string to an associative array like
        // array(blob => timeout).
        foreach($this->s3Config['presigned_urls'] as $line)
        {
            $blob = trim($line);
            if($blob)
            {
                if(preg_match('/(.*)\|(.*)/', $blob, $matches))
                {
                    $blob                       = $matches[2];
                    $timeout                    = $matches[1];
                    $this->presignedURLs[$blob] = $timeout;
                }
                else
                {
                    $this->presignedURLs[$blob] = 60;
                }
            }
        }

        // Convert the forced save-as string to an array.
        foreach($this->s3Config['saveas'] as $line)
        {
            $blob = trim($line);
            if($blob)
            {
                $this->saveas[] = $blob;
            }
        }

        $this->configured = true;
    }
}
