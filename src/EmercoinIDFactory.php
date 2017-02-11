<?php

namespace Drupal\emercoin_id;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
// use EmercoinID\EmercoinID;

/**
 * Class EmercoinIDFactory.
 *
 * Creates an instance of EmercoinID\EmercoinID service with App ID and secret from
 * EmercoinID module settings.
 */
class EmercoinIDFactory {
  protected $configFactory;
  protected $loggerFactory;
  protected $peristentDataHandler;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Used for accessing Drupal configuration.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Used for logging errors.
   * @param \Drupal\emercoin_id\EmercoinIDPersistentDataHandler $persistent_data_handler
   *   Used for reading data from and writing data to session.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, EmercoinIDPersistentDataHandler $persistent_data_handler) {
    $this->configFactory         = $config_factory;
    $this->loggerFactory         = $logger_factory;
    $this->persistentDataHandler = $persistent_data_handler;
  }

  /**
   * Returns an instance of EmercoinID\EmercoinID service.
   *
   * Reads EmercoinID settings from a module settings
   * and creates an array of these parameters.
   *
   * @return array
   *   EmercoinID settings array.
   */
  public function getEmcService() {
    if ($this->validateConfig()) {
      return array(
        'app_id' => $this->getAppId(),
        'app_secret' => $this->getAppSecret(),
        'auth_page' => $this->getAuthPage(),
        'token_page' => $this->getTokenPage(),
        'infocard' => $this->getInfocard(),
        'persistent_data_handler' => $this->persistentDataHandler,
        'http_client_handler' => $this->getHttpClient(),
      );
    }

    return FALSE;
  }

  /**
   * Returns an instance of EmercoinIDPersistentDataHandler service.
   *
   * @return Drupal\emercoin_id\EmercoinIDPersistentDataHandler
   *   EmercoinIDPersistentDataHandler service instance.
   */
  public function getPersistentDataHandler() {
    return $this->persistentDataHandler;
  }

  /**
   * Checks that module is configured.
   *
   * @return bool
   *   True if module is configured
   *   False otherwise
   */
  protected function validateConfig() {
    $app_id     = $this->getAppId();
    $app_secret = $this->getAppSecret();
    $auth_page  = $this->getAuthPage();
    $token_page = $this->getTokenPage();
    $infocard   = $this->getInfocard();

    if (!$app_id || !$app_secret || !$auth_page || !$token_page || !$infocard) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Define ALL App and Server settings on the module settings page.');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Returns app_id from module settings.
   *
   * @return string
   *   Application ID defined in module settings.
   */
  protected function getAppId() {
    $app_id = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('app_id');
    return $app_id;
  }

  /**
   * Returns app_secret from module settings.
   *
   * @return string
   *   Application secret defined in module settings.
   */
  protected function getAppSecret() {
    $app_secret = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('app_secret');
    return $app_secret;
  }

  /**
   * Returns auth_page from module settings.
   *
   * @return string
   *   Auth Page URL defined in module settings.
   */
  protected function getAuthPage() {
    $auth_page = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('auth_page');
    return $auth_page;
  }

  /**
   * Returns token_page from module settings.
   *
   * @return string
   *   Token Page URL defined in module settings.
   */
  protected function getTokenPage() {
    $token_page = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('token_page');
    return $token_page;
  }

  /**
   * Returns infocard from module settings.
   *
   * @return string
   *   Infocard URL defined in module settings.
   */
  protected function getInfocard() {
    $infocard = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('infocard');
    return $infocard;
  }

  /**
   * Returns HTTP client to be used with EmercoinID SDK.
   *
   * @return string
   *   Client that should be used with EmercoinID SDK.
   */
  protected function getHttpClient() {
    if (extension_loaded('curl')) {
      return 'curl';
    }
    return 'stream';
  }

}
