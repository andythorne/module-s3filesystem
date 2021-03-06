<?php

namespace Drupal\s3filesystem\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\LinkGeneratorTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;

/**
 * Class ActionAdminForm
 *
 * @package   Drupal\s3filesystem\Form
 *
 * @author    Andy Thorne <andy.thorne@timeinc.com>
 * @copyright Time Inc (UK) 2014
 */
class ActionAdminForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 's3filesystem_actions_form';
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
    $form['s3filesystem_refresh_cache'] = array(
      '#type'        => 'fieldset',
      '#description' => $this->t("The file metadata cache keeps track of every file that S3 File System writes to (and deletes from) the S3 bucket,
      so that queries for data about those files (checks for existence, filetype, etc.) don't have to hit S3.
      This speeds up many operations, most noticeably anything related to images and their derivatives."),
      '#title'       => $this->t('File Metadata Cache'),
    );

    $form['s3filesystem_refresh_cache']['refresh'] = array(
      '#type'        => 'submit',
      '#suffix'      => '<div class="refresh">' . $this->t("Query S3 for the metadata of <i><b>all</b></i> the files in your site's bucket, and saves it to the database.
                  This may take a while for buckets with many thousands of files. <br>
                  It should only be necessary to use this button if you've just installed S3 File System and you need to cache all the pre-existing files in your bucket,
                  or if you need to restore your metadata cache from scratch for some other reason.") . '</div>',
      '#value'       => $this->t('Refresh file metadata cache'),
      '#button_type' => 'primary',
    );

    return $form;
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
    /** @var DrupalAdaptor $client */
    $client = \Drupal::service('s3filesystem.client');
    $client->refreshCache();
  }

} 
