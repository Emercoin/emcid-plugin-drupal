<?php

namespace Drupal\emercoin_id;

use EmercoinID\Exceptions\EmercoinIDResponseException;
use EmercoinID\Exceptions\EmercoinIDSDKException;
use EmercoinID\EmercoinID;
use EmercoinID\GraphNodes\GraphNode;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Contains all Emercoin ID logic that is related to EmercoinID interaction.
 */
class EmercoinIDEmcManager {

  protected $loggerFactory;
  protected $eventDispatcher;
  protected $entityFieldManager;
  protected $urlGenerator;
  protected $persistentDataHandler;
  protected $emercoinid;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Used for logging errors.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Used for dispatching events to other modules.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Used for accessing Drupal user picture preferences.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   Used for generating absoulute URLs.
   * @param \Drupal\emercoin_id\EmercoinIDPersistentDataHandler $persistent_data_handler
   *   Used for reading data from and writing data to session.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, EventDispatcherInterface $event_dispatcher, EntityFieldManagerInterface $entity_field_manager, UrlGeneratorInterface $url_generator, EmercoinIDPersistentDataHandler $persistent_data_handler) {
    $this->loggerFactory         = $logger_factory;
    $this->eventDispatcher       = $event_dispatcher;
    $this->entityFieldManager    = $entity_field_manager;
    $this->urlGenerator          = $url_generator;
    $this->persistentDataHandler = $persistent_data_handler;
    $this->emercoinid              = NULL;
  }

  /**
   * Dependency injection setter for EmercoinID service.
   *
   * @param array $emercoinid
   *   EmercoinID service.
   */
  public function setEmercoinIDService(array $emercoinid) {
    $this->emercoinid = $emercoinid;
  }

  /**
   * Returns the EmercoinID login URL where user will be redirected.
   *
   * @return string
   *   Absolute EmercoinID login URL where user will be redirected
   */
  public function getEmcLoginUrl() {
    $login_helper = $this->emercoinid->getRedirectLoginHelper();

    // Define the URL where EmercoinID should return the user.
    $return_url = $this->urlGenerator->generateFromRoute(
      'emercoin_id.return_from_emc', array(), array('absolute' => TRUE));

    // Define the initial array of EmercoinID permissions.
    $scope = array('public_profile', 'email');

    // Dispatch an event so that other modules can modify the permission scope.
    // Set the scope twice on the event: as the main subject but also in the
    // list of arguments.
    $e = new GenericEvent($scope, ['scope' => $scope]);
    $event = $this->eventDispatcher->dispatch('emercoin_id.scope', $e);
    $final_scope = $event->getArgument('scope');

    // Generate and return the URL where we should redirect the user.
    return $login_helper->getLoginUrl($return_url, $final_scope);
  }

  /**
   * Returns the EmercoinID login URL for re-requesting email permission.
   *
   * @return string
   *   Absolute EmercoinID login URL where user will be redirected
   */
  public function getEmcReRequestUrl() {
    $login_helper = $this->emercoinid->getRedirectLoginHelper();

    // Define the URL where EmercoinID should return the user.
    $return_url = $this->urlGenerator->generateFromRoute(
      'emercoin_id.return_from_emc', array(), array('absolute' => TRUE));

    // Define the array of EmercoinID permissions to re-request.
    $scope = array('public_profile', 'email');

    // Generate and return the URL where we should redirect the user.
    return $login_helper->getReRequestUrl($return_url, $scope);
  }

  /**
   * Reads user's access token from EmercoinID and set is as default token.
   *
   * This method can only be called from route emercoin_id.return_from_emc
   * because RedirectLoginHelper will use the URL parameters set by EmercoinID.
   *
   * @return \EmercoinID\Authentication\AccessToken|null
   *   User's EmercoinID access token, if it could be read from EmercoinID.
   *   Null, otherwise.
   */
  public function getAccessTokenFromEmc() {
    $helper = $this->emercoinid->getRedirectLoginHelper();

    // URL where EmercoinID returned the user.
    $return_url = $this->urlGenerator->generateFromRoute(
      'emercoin_id.return_from_emc', array(), array('absolute' => TRUE));

    try {
      $access_token = $helper->getAccessToken($return_url);
    }

    catch (EmercoinIDResponseException $ex) {
      // Graph API returned an error.
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not get EmercoinID access token. EmercoinIDResponseException: @message', array('@message' => json_encode($ex->getMessage())));
      return FALSE;
    }

    catch (EmercoinIDSDKException $ex) {
      // Validation failed or other local issues.
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not get EmercoinID access token. Exception: @message', array('@message' => ($ex->getMessage())));
    }

    // If login was OK on EmercoinID, we now have user's access token.
    if (isset($access_token)) {

      // All EMC API requests use this token unless otherwise defined.
      $this->emercoinid->setDefaultAccessToken($access_token);

      return $access_token;
    }

    // If we're still here, user denied the login request on EmercoinID.
    $this->loggerFactory
      ->get('emercoin_id')
      ->error('Could not get EmercoinID access token. User cancelled the dialog in EmercoinID or return URL was not valid.');
    return NULL;
  }

  /**
   * Makes an API call to check if user has granted given permission.
   *
   * @param string $permission_to_check
   *   Permission to check.
   *
   * @return bool
   *   True if user has granted given permission.
   *   False otherwise.
   */
  public function checkPermission($permission_to_check) {
    try {
      $permissions = $this->emercoinid
        ->get('/me/permissions')
        ->getGraphEdge()
        ->asArray();
      foreach ($permissions as $permission) {
        if ($permission['permission'] == $permission_to_check && $permission['status'] == 'granted') {
          return TRUE;
        }
      }
    }
    catch (EmercoinIDResponseException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not check EmercoinID permissions: EmercoinIDResponseException: @message', array('@message' => json_encode($ex->getMessage())));
    }
    catch (EmercoinIDSDKException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not check EmercoinID permissions: EmercoinIDSDKException: @message', array('@message' => ($ex->getMessage())));
    }

    // We don't have permission or we got an exception during the API call.
    return FALSE;
  }

  /**
   * Makes an API call to get user's EmercoinID profile.
   *
   * @return \EmercoinID\GraphNodes\GraphNode|false
   *   GraphNode representing the user
   *   False if exception was thrown
   */
  public function getEmcProfile() {
    try {
      return $this->emercoinid
        ->get('/me?fields=id,name,email')
        ->getGraphNode();
    }
    catch (EmercoinIDResponseException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not load EmercoinID user profile: EmercoinIDResponseException: @message', array('@message' => json_encode($ex->getMessage())));
    }
    catch (EmercoinIDSDKException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not load EmercoinID user profile: EmercoinIDSDKException: @message', array('@message' => ($ex->getMessage())));
    }

    // Something went wrong.
    return FALSE;
  }

  /**
   * Makes an API call to get the URL of user's EmercoinID profile picture.
   *
   * @return string|false
   *   Absolute URL of the profile picture.
   *   False if user did not have a profile picture on EMC or an error occured.
   */
  public function getEmcProfilePicUrl() {
    // Determine preferred resolution for the profile picture.
    $resolution = $this->getPreferredResolution();

    // Generate EMC API query.
    $query = '/me/picture?redirect=false';
    if (is_array($resolution)) {
      $query .= '&width=' . $resolution['width'] . '&height=' . $resolution['height'];
    }

    // Call Graph API to request profile picture.
    try {
      $graph_node = $this->emercoinid->get($query)->getGraphNode();

      // We don't download the EMC default silhouttes, only real pictures.
      $is_silhoutte = (bool) $graph_node->getField('is_silhouette');
      if ($is_silhoutte) {
        return FALSE;
      }

      // We have a real picture, return URL for it.
      return $graph_node->getField('url');
    }
    catch (EmercoinIDResponseException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not load EmercoinID profile picture URL. EmercoinIDResponseException: @message', array('@message' => json_encode($ex->getMessage())));
    }
    catch (EmercoinIDSDKException $ex) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not load EmercoinID profile picture URL. EmercoinIDSDKException: @message', array('@message' => ($ex->getMessage())));
    }

    // Something went wrong and the picture could not be loaded.
    return FALSE;
  }

  /**
   * Returns user's email address from EmercoinID profile.
   *
   * @param \EmercoinID\GraphNodes\GraphNode $emc_profile
   *   GraphNode object representing user's EmercoinID profile.
   *
   * @return string|false
   *   User's email address if found
   *   False otherwise
   */
  public function getEmail(GraphNode $emc_profile) {
    if ($email = $emc_profile->getField('email')) {
      return $email;
    }

    // Email address was not found. Log error and return FALSE.
    $this->loggerFactory
      ->get('emercoin_id')
      ->error('No email address in EmercoinID user profile');
    return FALSE;
  }

  /**
   * Determines preferred profile pic resolution from account settings.
   *
   * Return order: max resolution, min resolution, FALSE.
   *
   * @return array|false
   *   Array of resolution, if defined in Drupal account settings
   *   False otherwise
   */
  protected function getPreferredResolution() {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    if (!isset($field_definitions['user_picture'])) {
      return FALSE;
    }

    $max_resolution = $field_definitions['user_picture']->getSetting('max_resolution');
    $min_resolution = $field_definitions['user_picture']->getSetting('min_resolution');

    // Return order: max resolution, min resolution, FALSE.
    if ($max_resolution) {
      $resolution = $max_resolution;
    }
    elseif ($min_resolution) {
      $resolution = $min_resolution;
    }
    else {
      return FALSE;
    }
    $dimensions = explode('x', $resolution);
    return array('width' => $dimensions[0], 'height' => $dimensions[1]);
  }

}
