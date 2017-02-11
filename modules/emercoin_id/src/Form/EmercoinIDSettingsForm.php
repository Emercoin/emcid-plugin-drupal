<?php

namespace Drupal\emercoin_id\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Component\Utility\SafeMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form that configures Emercoin ID settings.
 */
class EmercoinIDSettingsForm extends ConfigFormBase {

  protected $requestContext;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Routing\RequestContext $request_context
   *   Holds information about the current request.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RequestContext $request_context) {
    $this->setConfigFactory($config_factory);
    $this->requestContext = $request_context;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this class.
    return new static(
      // Load the services required to construct this class.
      $container->get('config.factory'),
      $container->get('router.request_context')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'emercoin_id_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'emercoin_id.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $emc_config = $this->config('emercoin_id.settings');

    $form['emc_server_settings'] = array(
      '#type' => 'details',
      '#title' => $this->t('Server Settings'),
      '#open' => TRUE,
    );

    $form['emc_server_settings']['auth_page'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Auth Page'),
      '#description' => $this->t('Emercoin ID Auth Page (example: https://id.emercoin.net/oauth/v2/auth)'),
      '#default_value' => $emc_config->get('auth_page'),
    );

    $form['emc_server_settings']['token_page'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Token Page'),
      '#description' => $this->t('Emercoin ID Token Page (example: https://id.emercoin.net/oauth/v2/token)'),
      '#default_value' => $emc_config->get('token_page'),
    );

    $form['emc_server_settings']['infocard'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Infocard Page'),
      '#description' => $this->t('Emercoin ID Infocard Page (example: https://id.emercoin.net/infocard)'),
      '#default_value' => $emc_config->get('infocard'),
    );

    //////////////////////////////

    $form['emc_settings'] = array(
      '#type' => 'details',
      '#title' => $this->t('App settings'),
      '#open' => TRUE,
      '#description' => $this->t('You need to first create an Emercoin ID App (at such as <a href="@emercoinid-dev">@emercoinid-dev</a>)', array('@emercoinid-dev' => 'https://id.emercoin.net')),
    );

    $form['emc_settings']['app_id'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('App Client ID'),
      '#default_value' => $emc_config->get('app_id'),
      '#description' => $this->t("Paste your App's Client ID"),
    );

    $form['emc_settings']['app_secret'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('App Secret Key'),
      '#default_value' => $emc_config->get('app_secret'),
      '#description' => $this->t("Paste your App's Secret Key"),
    );

    //////////////////////////////

    $form['module_settings'] = array(
      '#type' => 'details',
      '#title' => $this->t('Drupal integration'),
      '#open' => TRUE,
      '#description' => $this->t('These settings allow you to configure how Emercoin ID module behaves on your Drupal site'),
    );

    $form['module_settings']['post_login_path'] = array(
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => $this->t('Post login path'),
      '#description' => $this->t('Drupal path where the user should be redirected after successful login. Use <em>&lt;front&gt;</em> to redirect user to your front page.'),
      '#default_value' => $emc_config->get('post_login_path'),
    );

    $form['module_settings']['redirect_user_form'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Redirect new users to Drupal user form'),
      '#description' => $this->t('If you check this, new users are redirected to Drupal user form after the user is created. This is useful if you want to encourage users to fill in additional user fields.'),
      '#default_value' => $emc_config->get('redirect_user_form'),
    );

    $form['module_settings']['disable_admin_login'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Disable Emercoin ID for administrator'),
      '#description' => $this->t('Disabling Emercoin ID for administrator (<em>user 1</em>) can help protect your site if a security vulnerability is ever discovered in Emercoin ID SDK or this module.'),
      '#default_value' => $emc_config->get('disable_admin_login'),
    );

    // Option to disable Emercoin ID for specific roles.
    $roles = user_roles();
    $options = array();
    foreach ($roles as $key => $role_object) {
      if ($key != 'anonymous' && $key != 'authenticated') {
        $options[$key] = SafeMarkup::checkPlain($role_object->get('label'));
      }
    }

    $form['module_settings']['disabled_roles'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Disable Emercoin ID for the following roles'),
      '#options' => $options,
      '#default_value' => $emc_config->get('disabled_roles'),
    );
    if (empty($roles)) {
      $form['module_settings']['disabled_roles']['#description'] = $this->t('No roles found.');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // If someone would need a validation error:
    // $form_state->setErrorByName('form_field_name', $this->t('Error text'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('emercoin_id.settings')
      ->set('auth_page', $values['auth_page'])
      ->set('token_page', $values['token_page'])
      ->set('infocard', $values['infocard'])
      ->set('app_id', $values['app_id'])
      ->set('app_secret', $values['app_secret'])
      ->set('post_login_path', $values['post_login_path'])
      ->set('redirect_user_form', $values['redirect_user_form'])
      ->set('disable_admin_login', $values['disable_admin_login'])
      ->set('disabled_roles', $values['disabled_roles'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
