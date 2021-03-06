<?php

namespace Drupal\s3filesystem\Form;

use Aws\S3\Exception\InvalidAccessKeyIdException;
use Aws\S3\Exception\NoSuchBucketException;
use Aws\S3\Exception\PermanentRedirectException;
use Aws\S3\Exception\SignatureDoesNotMatchException;
use Drupal\Core\Config\Config;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\s3filesystem\AWS\S3\ClientFactory;
use Drupal\s3filesystem\Exception\S3FileSystemException;

/**
 * Class SettingsAdminForm
 *
 * @package   Drupal\s3filesystem\Form
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class SettingsAdminForm extends ConfigFormBase {

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['s3filesystem.settings'];
  }


  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 's3filesystem_settings_form';
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['s3filesystem_credentials'] = array(
      '#type'        => 'fieldset',
      '#title'       => $this->t('Amazon Web Services Credentials'),
      '#collapsible' => TRUE,
      '#collapsed'   => FALSE,
    );
    $this->addAWSCredentialsSection($form['s3filesystem_credentials']);

    $form['s3filesystem_s3'] = array(
      '#type'        => 'fieldset',
      '#title'       => $this->t('S3 Settings'),
      '#collapsible' => TRUE,
      '#collapsed'   => FALSE,
    );
    $this->addS3ConfigSection($form['s3filesystem_s3']);

    $form['actions']['submit'] = array(
      '#type'        => 'submit',
      '#value'       => $this->t('Save'),
      '#button_type' => 'primary',
    );

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var $form_state \Drupal\Core\Form\FormState */

    $s3Hostname = $form_state->getValue('s3filesystem_custom_s3_host_hostname');
    if ($form_state->getValue('s3filesystem_custom_s3_host_enabled') && empty($s3Hostname)) {
      $form_state->setErrorByName('s3filesystem_hostname', $this->t('You must specify a Hostname to use the Custom Host feature.'));
    }

    $s3CdnHost = $form_state->getValue('s3filesystem_custom_cdn_domain');
    if ($form_state->getValue('s3filesystem_custom_cdn_enabled') && empty($s3CdnHost)) {
      $form_state->setErrorByName('s3filesystem_custom_cdn_domain', $this->t('You must specify a CDN Domain Name to use the CNAME feature.'));
    }

    $awsProxy = $form_state->getValue('s3filesystem_awssdk2_proxy_enabled');
    if ($awsProxy) {
      $proxyHosy = $form_state->getValue('s3filesystem_awssdk2_proxy_host');
      if (!preg_match('/^.*?:[0-9]+$/', $proxyHosy)) {
        $form_state->setErrorByName('s3filesystem_awssdk2_proxy_host', $this->t('The proxy host is invalid. It must be in the format hostname:port'));
      }
    }

    try {
      $testConfig = clone $this->config('s3filesystem.settings');
      $this->hydrateConfiguration($form_state, $testConfig);
      $s3 = ClientFactory::create($testConfig);

      // listObjects() will trigger descriptive exceptions if the credentials,
      // bucket name, or region are invalid/mismatched.
      $s3->listObjects(array(
        'Bucket'    => $form_state->getValue('s3filesystem_bucket'),
        'Prefix'    => $form_state->getValue('s3filesystem_keyprefix'),
        'Delimiter' => '/',
      ));
    }
    catch(S3FileSystemException $e) {
      $form_state->setErrorByName('s3filesystem_bucket', $e->getMessage());
    }
    catch(InvalidAccessKeyIdException $e) {
      $form_state->setErrorByName('', $this->t('The Access Key in your AWS credentials is invalid.'));
    }
    catch(SignatureDoesNotMatchException $e) {
      $form_state->setErrorByName('', $this->t('The Secret Key in your AWS credentials is invalid.'));
    }
    catch(NoSuchBucketException $e) {
      $form_state->setErrorByName('s3filesystem_bucket', $this->t('The specified bucket does not exist.'));
    }
    catch(PermanentRedirectException $e) {
      $form_state->setErrorByName('s3filesystem_region', $this->t('This bucket exists, but it is not in the specified region.'));
    }
    catch(\Exception $e) {
      $form_state->setErrorByName('s3filesystem_bucket', $this->t('An unexpected %exception occured, with the following error message:<br>%error',
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('s3filesystem.settings');
    $this->hydrateConfiguration($form_state, $config);
    $config->save();
  }

  /**
   * Add the AWS Credentials form
   *
   * @param array $form
   */
  private function addAWSCredentialsSection(array &$form) {

    $config = $this->config('s3filesystem.settings');

    $form['s3filesystem_use_instance_profile'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use EC2 Instance Profile Credentials'),
      '#default_value' => $config->get('aws.use_instance_profile'),
      '#description'   => $this->t('If your Drupal site is running on an Amazon EC2 server, you may use the Instance Profile Credentials from that server
                                rather than setting your AWS credentials directly.'),
    );

    $form['s3filesystem_awssdk2_access_key'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Amazon Web Services Access Key'),
      '#default_value' => $config->get('aws.access_key'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-use-instance-profile]' => array('checked' => FALSE),
        ),
      ),
    );

    $form['s3filesystem_awssdk2_secret_key'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Amazon Web Services Secret Key'),
      '#default_value' => $config->get('aws.secret_key'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-use-instance-profile]' => array('checked' => FALSE),
        ),
      ),
    );

    $form['s3filesystem_awssdk2_default_cache_config'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Default Cache Location'),
      '#description'   => $this->t('The default cache location for your EC2 Instance Profile Credentials.'),
      '#default_value' => $config->get('aws.default_cache_config'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-use-instance-profile]' => array('checked' => TRUE),
        ),
      ),
    );


    $form['s3filesystem_proxy'] = array(
      '#type'        => 'fieldset',
      '#title'       => $this->t('Proxy Settings'),
      '#collapsible' => TRUE,
      '#collapsed'   => FALSE,
    );

    $form['s3filesystem_proxy']['s3filesystem_awssdk2_proxy_enabled'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable proxy'),
      '#description'   => $this->t('Enable to connect to AWS via a proxy'),
      '#default_value' => $config->get('aws.proxy.enabled'),
    );

    $form['s3filesystem_proxy']['s3filesystem_awssdk2_proxy_host'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Host and Port'),
      '#description'   => $this->t('Use the format hostname:port'),
      '#default_value' => $config->get('aws.proxy.host'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-awssdk2-proxy-enabled]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['s3filesystem_proxy']['s3filesystem_awssdk2_proxy_timeout'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Timeout'),
      '#default_value' => $config->get('aws.proxy.timeout'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-awssdk2-proxy-enabled]' => array('checked' => TRUE),
        ),
      ),
    );

    $form['s3filesystem_proxy']['s3filesystem_awssdk2_proxy_connect_timeout'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Connection Timeout'),
      '#default_value' => $config->get('aws.proxy.connect_timeout'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-awssdk2-proxy-enabled]' => array('checked' => TRUE),
        ),
      ),
    );

  }

  /**
   * Add the S3 form
   *
   * @param array $form
   */
  private function addS3ConfigSection(array &$form) {

    $config = $this->config('s3filesystem.settings');

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

    $form['s3filesystem_bucket'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Bucket Name'),
      '#default_value' => $config->get('s3.bucket'),
      '#required'      => TRUE,
    );

    $form['s3filesystem_keyprefix'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Prefix Key'),
      '#description'   => $this->t('The key prefix is used to limit objects returned from S3 and prepended to URLs.'),
      '#default_value' => $config->get('s3.keyprefix'),
      '#required'      => TRUE,
    );

    $form['s3filesystem_region'] = array(
      '#type'          => 'select',
      '#options'       => $region_map,
      '#title'         => $this->t('Region'),
      '#description'   => $this->t('The region in which your bucket resides. Be careful to specify this accurately, as you may see strange behavior if the region is set wrong.'),
      '#default_value' => $config->get('s3.region'),
    );

    $form['s3filesystem_force_https'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Always serve files from S3 via HTTPS'),
      '#description'   => $this->t('Forces S3 File System to always generate HTTPS URLs for files in your bucket, e.g. "https://mybucket.s3.amazonaws.com/smiley.jpg".<br>
      Without this setting enabled, URLs for your files will use the same scheme as the page they are served from.'),
      '#default_value' => $config->get('s3.force_https'),
    );

    $this->addCustomCDNSection($form);
    $this->addCustomS3HostSection($form);
    $this->addS3CacheSection($form);

    $form['s3filesystem_presigned_urls'] = array(
      '#type'          => 'textarea',
      '#title'         => $this->t('Presigned URLs'),
      '#description'   => $this->t('A list of timeouts and paths that should be delivered through a presigned url.<br>
      Enter one value per line, in the format timeout|path. e.g. "60|private_files/*". Paths use regex patterns as per !link.
      If no timeout is provided, it defaults to 60 seconds.<br>
      <b>This feature does not work when "Enable CNAME" is used.</b>',
        array('!link' => $this->l($this->t('preg_match'), Url::fromUri('http://php.net/preg_match')))),
      '#default_value' => implode("\n", $config->get('s3.presigned_urls')),
      '#rows'          => 5,
    );

    $form['s3filesystem_saveas'] = array(
      '#type'          => 'textarea',
      '#title'         => $this->t('Force Save As'),
      '#description'   => $this->t('A list of paths for which users will be forced to save the file, rather than displaying it in the browser.<br>
      Enter one value per line. e.g. "video/*". Paths use regex patterns as per !link.<br>
      <b>This feature does not work when "Enable CNAME" is used.</b>',
        array('!link' => $this->l($this->t('preg_match'), Url::fromUri('http://php.net/preg_match')))),
      '#default_value' => implode("\n", $config->get('s3.saveas')),
      '#rows'          => 5,
    );

    $form['s3filesystem_torrents'] = array(
      '#type'          => 'textarea',
      '#title'         => $this->t('Torrents'),
      '#description'   => $this->t('A list of paths that should be delivered via BitTorrent.<br>
      Enter one value per line, e.g. "big_files/*". Paths use regex patterns as per !link.<br>
      <b>Paths which are already set as Presigned URLs or Forced Save As cannot be delivered as torrents.</b>',
        array('!link' => $this->l($this->t('preg_match'), Url::fromUri('http://php.net/preg_match')))),
      '#default_value' => implode("\n", $config->get('s3.torrents')),
      '#rows'          => 5,
    );
  }


  /**
   * Add the Custom CDN form
   *
   * @param array $form
   */
  private function addCustomCDNSection(array &$form) {

    $config    = $this->config('s3filesystem.settings');
    $customCDN = $config->get('s3.custom_cdn');

    $form['s3filesystem_custom_cdn_settings_fieldset'] = array(
      '#type'  => 'fieldset',
      '#title' => $this->t('CDN Settings'),
    );

    $form['s3filesystem_custom_cdn_settings_fieldset']['s3filesystem_custom_cdn_enabled'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Custom CDN'),
      '#description'   => $this->t('Serve files from a custom domain by using an appropriately named bucket, e.g. "mybucket.mydomain.com".'),
      '#default_value' => $config->get('s3.custom_cdn.enabled'),
    );

    $form['s3filesystem_custom_cdn_settings_fieldset']['s3filesystem_custom_cdn_domain'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('CDN Domain Name'),
      '#description'   => $this->t('If serving files from CloudFront, the bucket name can differ from the domain name.'),
      '#default_value' => $config->get('s3.custom_cdn.domain'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-custom-cdn-enabled]' => array('checked' => TRUE),
        ),
      ),
    );


    $form['s3filesystem_custom_cdn_settings_fieldset']['s3filesystem_custom_cdn_http_only'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('CDN over HTTP only'),
      '#description'   => $this->t('Enable if you only want to serve files over the CDN on the http (non-secure) protocol only'),
      '#default_value' => $config->get('s3.custom_cdn.http_only'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-custom-cdn-enabled]' => array('checked' => TRUE),
        ),
      ),
    );

  }

  /**
   * Add the Custom S3 Host form
   *
   * @param array $form
   */
  private function addCustomS3HostSection(array &$form) {

    $config = $this->config('s3filesystem.settings');

    $form['s3filesystem_custom_s3_host_settings_fieldset'] = array(
      '#type'  => 'fieldset',
      '#title' => $this->t('Custom Host Settings'),
    );

    $form['s3filesystem_custom_s3_host_settings_fieldset']['s3filesystem_custom_s3_host_enabled'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use a Custom Host'),
      '#description'   => $this->t('Connect to an S3-compatible storage service other than Amazon.'),
      '#default_value' => $config->get('s3.custom_host.enabled'),
    );

    $form['s3filesystem_custom_s3_host_settings_fieldset']['s3filesystem_custom_s3_host_hostname'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Hostname'),
      '#description'   => $this->t('Custom service hostname, e.g. "objects.dreamhost.com".'),
      '#default_value' => $config->get('s3.custom_host.hostname'),
      '#states'        => array(
        'visible' => array(
          ':input[id=edit-s3filesystem-custom-s3-host-enabled]' => array('checked' => TRUE),
        ),
      ),
    );
  }

  private function addS3CacheSection(array &$form) {

    $config = $this->config('s3filesystem.settings');

    $form['s3filesystem_cache_settings_fieldset'] = array(
      '#type'  => 'fieldset',
      '#title' => $this->t('Cache Settings'),
    );

    $form['s3filesystem_cache_settings_fieldset']['s3filesystem_ignore_cache'] = array(
      '#type'          => 'checkbox',
      '#title'         => $this->t('Ignore the file metadata cache'),
      '#description'   => $this->t("If you need to debug a problem with S3, you may want to temporarily ignore the file metadata cache.
       This will make all filesystem reads hit S3 instead of the cache.<br>
       <b>This causes s3filesystem to work extremely slowly, and should never be enabled on a production site.</b>"),
      '#default_value' => $config->get('s3.ignore_cache'),
    );

    $form['s3filesystem_cache_settings_fieldset']['s3filesystem_refresh_prefix'] = array(
      '#type'          => 'textfield',
      '#title'         => $this->t('Partial Refresh Prefix'),
      '#default_value' => $config->get('s3.refresh_prefix'),
      '#description'   => $this->t('If you want the "Refresh file metadata cache" action to refresh only some of the contents of your bucket, provide a file path prefix in this field.<br>
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
  protected function parseTextAreaList($value) {
    $value         = str_replace("\r", "", $value);
    $parsedUrlList = array();
    $rawUrlList    = explode("\n", $value);
    foreach ($rawUrlList as $rawUrl) {
      $rawUrl = trim($rawUrl);
      if ($rawUrl) {
        $parsedUrlList[] = $rawUrl;
      }
    }

    return $parsedUrlList;
  }

  /**
   * Given a FormStateInterface, hydrate the configurations
   *
   * @param FormStateInterface $form_state
   * @param Config             $config
   */
  protected function hydrateConfiguration(FormStateInterface $form_state, Config $config) {

    $config->set('s3.bucket', $form_state->getValue('s3filesystem_bucket'));
    $config->set('s3.keyprefix', $form_state->getValue('s3filesystem_keyprefix'));
    $config->set('s3.region', $form_state->getValue('s3filesystem_region'));
    $config->set('s3.force_https', $form_state->getValue('s3filesystem_force_https'));
    $config->set('s3.ignore_cache', $form_state->getValue('s3filesystem_ignore_cache'));
    $config->set('s3.refresh_prefix', $form_state->getValue('s3filesystem_refresh_prefix'));
    $config->set('s3.presigned_urls', $this->parseTextAreaList($form_state->getValue('s3filesystem_presigned_urls')));
    $config->set('s3.saveas', $this->parseTextAreaList($form_state->getValue('s3filesystem_saveas')));
    $config->set('s3.torrents', $this->parseTextAreaList($form_state->getValue('s3filesystem_torrents')));

    $config->set('s3.custom_s3_host.enabled', $form_state->getValue('s3filesystem_custom_s3_host_enabled'));
    $config->set('s3.custom_s3_host.enabled', $form_state->getValue('s3filesystem_custom_s3_host_hostname'));

    $config->set('s3.custom_cdn.enabled', $form_state->getValue('s3filesystem_custom_cdn_enabled'));
    $config->set('s3.custom_cdn.domain', $form_state->getValue('s3filesystem_custom_cdn_domain'));
    $config->set('s3.custom_cdn.http_only', $form_state->getValue('s3filesystem_custom_cdn_http_only'));

    $config->set('aws.use_instance_profile', $form_state->getValue('s3filesystem_use_instance_profile'));
    $config->set('aws.default_cache_config', $form_state->getValue('s3filesystem_awssdk2_default_cache_config'));
    $config->set('aws.access_key', $form_state->getValue('s3filesystem_awssdk2_access_key'));
    $config->set('aws.secret_key', $form_state->getValue('s3filesystem_awssdk2_secret_key'));

    $config->set('aws.proxy.enabled', (bool) $form_state->getValue('s3filesystem_awssdk2_proxy_enabled'));
    $config->set('aws.proxy.host', $form_state->getValue('s3filesystem_awssdk2_proxy_host'));
    $config->set('aws.proxy.connect_timeout', (int) $form_state->getValue('s3filesystem_awssdk2_proxy_connect_timeout'));
    $config->set('aws.proxy.timeout', (int) $form_state->getValue('s3filesystem_awssdk2_proxy_timeout'));

  }
} 
