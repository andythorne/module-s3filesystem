<?php

namespace Drupal\s3fs\Form;

use Aws\S3\Exception\InvalidAccessKeyIdException;
use Aws\S3\Exception\NoSuchBucketException;
use Aws\S3\Exception\PermanentRedirectException;
use Aws\S3\Exception\SignatureDoesNotMatchException;
use Drupal\Core\Config\Config;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Url;
use Drupal\s3fs\AWS\S3\ClientFactory;
use Drupal\s3fs\Exception\S3fsException;

/**
 * Class SettingsAdminForm
 *
 * @package   Drupal\s3fs\Form
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class SettingsAdminForm extends FormBase
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $s3Config;

    /**
     * @var array
     */
    protected $awsConfig;

    /**
     * @var TranslationManager
     */
    protected $t;

    function __construct()
    {
        $this->config    = \Drupal::config('s3fs.settings');
        $this->s3Config  = $this->config->get('s3');
        $this->awsConfig = $this->config->get('aws');
        $this->t         = \Drupal::translation();
    }


    /**
     * Returns a unique string identifying the form.
     *
     * @return string
     *   The unique string identifying the form.
     */
    public function getFormId()
    {
        return 's3fs_settings_form';
    }

    /**
     * Form constructor.
     *
     * @param array                                $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     *
     * @return array
     *   The form structure.
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form['s3fs_credentials'] = array(
            '#type'        => 'fieldset',
            '#title'       => $this->t->translate('Amazon Web Services Credentials'),
            '#collapsible' => true,
            '#collapsed'   => false,
        );
        $this->addAWSCredentialsSection($form['s3fs_credentials']);

        $form['s3fs_s3'] = array(
            '#type'        => 'fieldset',
            '#title'       => $this->t->translate('S3 Settings'),
            '#collapsible' => true,
            '#collapsed'   => false,
        );
        $this->addS3ConfigSection($form['s3fs_s3']);

        $form['actions']['submit'] = array(
            '#type'        => 'submit',
            '#value'       => $this->t->translate('Save'),
            '#button_type' => 'primary',
        );

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        /** @var $form_state \Drupal\Core\Form\FormState */

        $s3Hostname = $form_state->getValue('s3fs_custom_s3_host_hostname');
        if($form_state->getValue('s3fs_custom_s3_host_enabled') && empty($s3Hostname))
        {
            $form_state->setErrorByName('s3fs_hostname', $this->t->translate('You must specify a Hostname to use the Custom Host feature.'));
        }

        $s3CdnHost = $form_state->getValue('s3fs_custom_cdn_domain');
        if($form_state->getValue('s3fs_custom_cdn_enabled') && empty($s3CdnHost))
        {
            $form_state->setErrorByName('s3fs_custom_cdn_domain', $this->t->translate('You must specify a CDN Domain Name to use the CNAME feature.'));
        }

        $awsProxy = $form_state->getValue('s3fs_awssdk2_proxy_enabled');
        if($awsProxy)
        {
            $proxyHosy = $form_state->getValue('s3fs_awssdk2_proxy_host');
            if(!preg_match('/^.*?:[0-9]+$/', $proxyHosy))
            {
                $form_state->setErrorByName('s3fs_awssdk2_proxy_host', $this->t->translate('The proxy host is invalid. It must be in the format hostname:port'));
            }
        }

        try
        {
            $testConfig = clone $this->config;
            $this->hydrateConfiguration($form_state, $testConfig);
            $s3 = ClientFactory::create($testConfig);

            // listObjects() will trigger descriptive exceptions if the credentials,
            // bucket name, or region are invalid/mismatched.
            $s3->listObjects(array(
                'Bucket'    => $form_state->getValue('s3fs_bucket'),
                'Prefix'    => $form_state->getValue('s3fs_keyprefix'),
                'Delimiter' => '/',
            ));
        }
        catch(S3fsException $e)
        {
            $form_state->setErrorByName('s3fs_bucket', $e->getMessage());
        }
        catch(InvalidAccessKeyIdException $e)
        {
            $form_state->setErrorByName('', $this->t->translate('The Access Key in your AWS credentials is invalid.'));
        }
        catch(SignatureDoesNotMatchException $e)
        {
            $form_state->setErrorByName('', $this->t->translate('The Secret Key in your AWS credentials is invalid.'));
        }
        catch(NoSuchBucketException $e)
        {
            $form_state->setErrorByName('s3fs_bucket', $this->t->translate('The specified bucket does not exist.'));
        }
        catch(PermanentRedirectException $e)
        {
            $form_state->setErrorByName('s3fs_region', $this->t->translate('This bucket exists, but it is not in the specified region.'));
        }
        catch(\Exception $e)
        {
            $form_state->setErrorByName('s3fs_bucket', $this->t->translate('An unexpected %exception occured, with the following error message:<br>%error',
                array('%exception' => get_class($e), '%error' => $e->getMessage())));
        }
    }


    /**
     * Form submission handler.
     *
     * @param array                                $form
     *   An associative array containing the structure of the form.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The current state of the form.
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->hydrateConfiguration($form_state, $this->config);
        $this->s3Config  = $this->config->get('s3');
        $this->awsConfig = $this->config->get('aws');
        $this->config->save();
    }

    /**
     * Add the AWS Credentials form
     *
     * @param array $form
     */
    private function addAWSCredentialsSection(array &$form)
    {

        $form['s3fs_use_instance_profile'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Use EC2 Instance Profile Credentials'),
            '#default_value' => $this->awsConfig['use_instance_profile'],
            '#description'   => $this->t->translate('If your Drupal site is running on an Amazon EC2 server, you may use the Instance Profile Credentials from that server
                                rather than setting your AWS credentials directly.'),
        );

        $form['s3fs_awssdk2_access_key'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Amazon Web Services Access Key'),
            '#default_value' => $this->awsConfig['access_key'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-use-instance-profile]' => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_awssdk2_secret_key'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Amazon Web Services Secret Key'),
            '#default_value' => $this->awsConfig['secret_key'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-use-instance-profile]' => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_awssdk2_default_cache_config'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Default Cache Location'),
            '#description'   => $this->t->translate('The default cache location for your EC2 Instance Profile Credentials.'),
            '#default_value' => $this->awsConfig['default_cache_config'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-use-instance-profile]' => array('checked' => true),
                ),
            ),
        );


        $form['s3fs_proxy'] = array(
            '#type'        => 'fieldset',
            '#title'       => $this->t->translate('Proxy Settings'),
            '#collapsible' => true,
            '#collapsed'   => false,
            '#states'      => array(
                'visible' => array(
                    ':input[id=edit-s3fs-use-instance-profile]' => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_proxy']['s3fs_awssdk2_proxy_enabled'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Enable proxy'),
            '#description'   => $this->t->translate('Enable to connect to AWS via a proxy'),
            '#default_value' => $this->awsConfig['proxy']['enabled'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-use-instance-profile]' => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_proxy']['s3fs_awssdk2_proxy_host'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Host and Port'),
            '#description'   => $this->t->translate('Use the format hostname:port'),
            '#default_value' => $this->awsConfig['proxy']['host'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-awssdk2-proxy-enabled]' => array('checked' => true),
                    ':input[id=edit-s3fs-use-instance-profile]'  => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_proxy']['s3fs_awssdk2_proxy_timeout'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Timeout'),
            '#default_value' => $this->awsConfig['proxy']['timeout'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-awssdk2-proxy-enabled]' => array('checked' => true),
                    ':input[id=edit-s3fs-use-instance-profile]'  => array('checked' => false),
                ),
            ),
        );

        $form['s3fs_proxy']['s3fs_awssdk2_proxy_connect_timeout'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Connection Timeout'),
            '#default_value' => $this->awsConfig['proxy']['connect_timeout'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-awssdk2-proxy-enabled]' => array('checked' => true),
                    ':input[id=edit-s3fs-use-instance-profile]'  => array('checked' => false),
                ),
            ),
        );

    }

    /**
     * Add the S3 form
     *
     * @param array $form
     */
    private function addS3ConfigSection(array &$form)
    {
        $region_map = array(
            ''               => 'Default',
            'us-east-1'      => 'US Standard (us-east-1)',
            'us-west-1'      => 'US West - Northern California  (us-west-1)',
            'us-west-2'      => 'US West - Oregon (us-west-2)',
            'eu-west-1'      => 'EU - Ireland  (eu-west-1)',
            'ap-southeast-1' => 'Asia Pacific - Singapore (ap-southeast-1)',
            'ap-southeast-2' => 'Asia Pacific - Sydney (ap-southeast-2)',
            'ap-northeast-1' => 'Asia Pacific - Tokyo (ap-northeast-1)',
            'sa-east-1'      => 'South America - Sao Paulo (sa-east-1)',
        );

        $form['s3fs_bucket'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Bucket Name'),
            '#default_value' => $this->s3Config['bucket'],
            '#required'      => true,
        );

        $form['s3fs_keyprefix'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Prefix Key'),
            '#description'   => $this->t->translate('The key prefix is used to limit objects returned from S3 and prepended to URLs.'),
            '#default_value' => $this->s3Config['keyprefix'],
            '#required'      => true,
        );

        $form['s3fs_region'] = array(
            '#type'          => 'select',
            '#options'       => $region_map,
            '#title'         => $this->t->translate('Region'),
            '#description'   => $this->t->translate('The region in which your bucket resides. Be careful to specify this accurately, as you may see strange behavior if the region is set wrong.'),
            '#default_value' => $this->s3Config['region'],
        );

        $form['s3fs_force_https'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Always serve files from S3 via HTTPS'),
            '#description'   => $this->t->translate('Forces S3 File System to always generate HTTPS URLs for files in your bucket, e.g. "https://mybucket.s3.amazonaws.com/smiley.jpg".<br>
      Without this setting enabled, URLs for your files will use the same scheme as the page they are served from.'),
            '#default_value' => $this->s3Config['force_https'],
        );

        $this->addCustomCDNSection($form);
        $this->addCustomS3HostSection($form);
        $this->addS3CacheSection($form);

        $form['s3fs_presigned_urls'] = array(
            '#type'          => 'textarea',
            '#title'         => $this->t->translate('Presigned URLs'),
            '#description'   => $this->t->translate('A list of timeouts and paths that should be delivered through a presigned url.<br>
      Enter one value per line, in the format timeout|path. e.g. "60|private_files/*". Paths use regex patterns as per !link.
      If no timeout is provided, it defaults to 60 seconds.<br>
      <b>This feature does not work when "Enable CNAME" is used.</b>',
                array('!link' => new Link($this->t->translate('preg_match'), Url::fromUri('http://php.net/preg_match')))),
            '#default_value' => implode("\n", $this->s3Config['presigned_urls']),
            '#rows'          => 5,
        );

        $form['s3fs_saveas'] = array(
            '#type'          => 'textarea',
            '#title'         => $this->t->translate('Force Save As'),
            '#description'   => $this->t->translate('A list of paths for which users will be forced to save the file, rather than displaying it in the browser.<br>
      Enter one value per line. e.g. "video/*". Paths use regex patterns as per !link.<br>
      <b>This feature does not work when "Enable CNAME" is used.</b>',
                array('!link' => new Link($this->t->translate('preg_match'), Url::fromUri('http://php.net/preg_match')))),
            '#default_value' => implode("\n", $this->s3Config['saveas']),
            '#rows'          => 5,
        );

        $form['s3fs_torrents'] = array(
            '#type'          => 'textarea',
            '#title'         => $this->t->translate('Torrents'),
            '#description'   => $this->t->translate('A list of paths that should be delivered via BitTorrent.<br>
      Enter one value per line, e.g. "big_files/*". Paths use regex patterns as per !link.<br>
      <b>Paths which are already set as Presigned URLs or Forced Save As cannot be delivered as torrents.</b>',
                array('!link' => new Link($this->t->translate('preg_match'), Url::fromUri('http://php.net/preg_match')))),
            '#default_value' => implode("\n", $this->s3Config['torrents']),
            '#rows'          => 5,
        );
    }


    /**
     * Add the Custom CDN form
     *
     * @param array $form
     */
    private function addCustomCDNSection(array &$form)
    {
        $customCDN = $this->s3Config['custom_cdn'];

        $form['s3fs_custom_cdn_settings_fieldset'] = array(
            '#type'  => 'fieldset',
            '#title' => $this->t->translate('CDN Settings'),
        );

        $form['s3fs_custom_cdn_settings_fieldset']['s3fs_custom_cdn_enabled'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Enable Custom CDN'),
            '#description'   => $this->t->translate('Serve files from a custom domain by using an appropriately named bucket, e.g. "mybucket.mydomain.com".'),
            '#default_value' => $customCDN['enabled'],
        );

        $form['s3fs_custom_cdn_settings_fieldset']['s3fs_custom_cdn_domain'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('CDN Domain Name'),
            '#description'   => $this->t->translate('If serving files from CloudFront, the bucket name can differ from the domain name.'),
            '#default_value' => $customCDN['domain'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-custom-cdn-enabled]' => array('checked' => true),
                ),
            ),
        );


        $form['s3fs_custom_cdn_settings_fieldset']['s3fs_custom_cdn_http_only'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('CDN over HTTP only'),
            '#description'   => $this->t->translate('Enable if you only want to serve files over the CDN on the http (non-secure) protocol only'),
            '#default_value' => $customCDN['http_only'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-custom-cdn-enabled]' => array('checked' => true),
                ),
            ),
        );

    }

    /**
     * Add the Custom S3 Host form
     *
     * @param array $form
     */
    private function addCustomS3HostSection(array &$form)
    {
        $customHost = $this->s3Config['custom_host'];

        $form['s3fs_custom_s3_host_settings_fieldset'] = array(
            '#type'  => 'fieldset',
            '#title' => $this->t->translate('Custom Host Settings'),
        );

        $form['s3fs_custom_s3_host_settings_fieldset']['s3fs_custom_s3_host_enabled'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Use a Custom Host'),
            '#description'   => $this->t->translate('Connect to an S3-compatible storage service other than Amazon.'),
            '#default_value' => $customHost['enabled'],
        );

        $form['s3fs_custom_s3_host_settings_fieldset']['s3fs_custom_s3_host_hostname'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Hostname'),
            '#description'   => $this->t->translate('Custom service hostname, e.g. "objects.dreamhost.com".'),
            '#default_value' => $customHost['hostname'],
            '#states'        => array(
                'visible' => array(
                    ':input[id=edit-s3fs-custom-s3-host-enabled]' => array('checked' => true),
                ),
            ),
        );
    }

    private function addS3CacheSection(array &$form)
    {


        $form['s3fs_cache_settings_fieldset'] = array(
            '#type'  => 'fieldset',
            '#title' => $this->t->translate('Cache Settings'),
        );

        $form['s3fs_cache_settings_fieldset']['s3fs_ignore_cache'] = array(
            '#type'          => 'checkbox',
            '#title'         => $this->t->translate('Ignore the file metadata cache'),
            '#description'   => $this->t->translate("If you need to debug a problem with S3, you may want to temporarily ignore the file metadata cache.
       This will make all filesystem reads hit S3 instead of the cache.<br>
       <b>This causes s3fs to work extremely slowly, and should never be enabled on a production site.</b>"),
            '#default_value' => $this->s3Config['ignore_cache'],
        );

        $form['s3fs_cache_settings_fieldset']['s3fs_refresh_prefix'] = array(
            '#type'          => 'textfield',
            '#title'         => $this->t->translate('Partial Refresh Prefix'),
            '#default_value' => $this->s3Config['refresh_prefix'],
            '#description'   => $this->t->translate('If you want the "Refresh file metadata cache" action to refresh only some of the contents of your bucket, provide a file path prefix in this field.<br>
      For example, setting this option to "images/" will refresh only the files with a URI that matches s3://images/*. This setting is case sensitive.'),
        );
    }

    /**
     * Accept a Textarea string and convert it into a line by line list
     *
     * @param string $value
     *
     * @return array
     */
    protected function parseTextAreaList($value)
    {
        $value         = str_replace("\r", "", $value);
        $parsedUrlList = array();
        $rawUrlList    = explode("\n", $value);
        foreach($rawUrlList as $rawUrl)
        {
            $rawUrl = trim($rawUrl);
            if($rawUrl)
            {
                $parsedUrlList[] = $rawUrl;
            }
        }

        return $parsedUrlList;
    }

    /**
     * Given a FormStateInterface, higrate the configurations
     *
     * @param FormStateInterface $form_state
     * @param Config             $config
     */
    protected function hydrateConfiguration(FormStateInterface $form_state, Config $config)
    {
        $s3Config  = $config->get('s3');
        $awsConfig = $config->get('aws');

        $s3Config['bucket']         = $form_state->getValue('s3fs_bucket');
        $s3Config['keyprefix']      = $form_state->getValue('s3fs_keyprefix');
        $s3Config['region']         = $form_state->getValue('s3fs_region');
        $s3Config['force_https']    = $form_state->getValue('s3fs_force_https');
        $s3Config['ignore_cache']   = $form_state->getValue('s3fs_ignore_cache');
        $s3Config['refresh_prefix'] = $form_state->getValue('s3fs_refresh_prefix');
        $s3Config['presigned_urls'] = $this->parseTextAreaList($form_state->getValue('s3fs_presigned_urls'));
        $s3Config['saveas']         = $this->parseTextAreaList($form_state->getValue('s3fs_saveas'));
        $s3Config['torrents']       = $this->parseTextAreaList($form_state->getValue('s3fs_torrents'));

        $s3Config['custom_s3_host'] = array(
            'enabled'  => $form_state->getValue('s3fs_custom_s3_host_enabled'),
            'hostname' => $form_state->getValue('s3fs_custom_s3_host_hostname'),
        );

        $s3Config['custom_cdn'] = array(
            'enabled'   => $form_state->getValue('s3fs_custom_cdn_enabled'),
            'domain'    => $form_state->getValue('s3fs_custom_cdn_domain'),
            'http_only' => $form_state->getValue('s3fs_custom_cdn_http_only'),
        );

        $awsConfig['use_instance_profile'] = $form_state->getValue('s3fs_use_instance_profile');
        $awsConfig['default_cache_config'] = $form_state->getValue('s3fs_awssdk2_default_cache_config');
        $awsConfig['access_key']           = $form_state->getValue('s3fs_awssdk2_access_key');
        $awsConfig['secret_key']           = $form_state->getValue('s3fs_awssdk2_secret_key');

        $awsConfig['proxy'] = array(
            'enabled'         => (bool)$form_state->getValue('s3fs_awssdk2_proxy_enabled'),
            'host'            => $form_state->getValue('s3fs_awssdk2_proxy_host'),
            'connect_timeout' => (int)$form_state->getValue('s3fs_awssdk2_proxy_connect_timeout'),
            'timeout'         => (int)$form_state->getValue('s3fs_awssdk2_proxy_timeout'),
        );

        $config->set('s3', $s3Config);
        $config->set('aws', $awsConfig);
    }
} 
