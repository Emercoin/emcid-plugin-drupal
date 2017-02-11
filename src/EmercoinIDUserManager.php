<?php

namespace Drupal\emercoin_id;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Core\Transliteration\PhpTransliteration;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Component\Utility\Unicode;

/**
 * Contains all logic that is related to Drupal user management.
 */
class EmercoinIDUserManager {
  use StringTranslationTrait;

  protected $configFactory;
  protected $loggerFactory;
  protected $eventDispatcher;
  protected $entityTypeManager;
  protected $entityFieldManager;
  protected $token;
  protected $transliteration;
  protected $languageManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Used for accessing Drupal configuration.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Used for logging errors.
   * @param \Drupal\Core\TranslationInterface $string_translation
   *   Used for translating strings in UI messages.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Used for dispatching events to other modules.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Used for loading and creating Drupal user objects.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   Used for access Drupal user field definitions.
   * @param \Drupal\Core\Utility\Token $token
   *   Used for token support in Drupal user picture directory.
   * @param \Drupal\Core\Transliteration\PhpTransliteration $transliteration
   *   Used for user picture directory and file transiliteration.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   Used for detecting the current UI language.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, TranslationInterface $string_translation, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, Token $token, PhpTransliteration $transliteration, LanguageManagerInterface $language_manager) {
    $this->configFactory      = $config_factory;
    $this->loggerFactory      = $logger_factory;
    $this->stringTranslation  = $string_translation;
    $this->eventDispatcher    = $event_dispatcher;
    $this->entityTypeManager  = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->token              = $token;
    $this->transliteration    = $transliteration;
    $this->languageManager    = $language_manager;
  }

  /**
   * Loads existing Drupal user object by given property and value.
   *
   * Note that first matching user is returned. Email address and account name
   * are unique so there can be only zero ore one matching user when
   * loading users by these properties.
   *
   * @param string $field
   *   User entity field to search from.
   * @param string $value
   *   Value to search for.
   *
   * @return \Drupal\user\Entity\User|false
   *   Drupal user account if found
   *   False otherwise
   */
  public function loadUserByProperty($field, $value) {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(array($field => $value));

    if (!empty($users)) {
      return current($users);
    }

    // If user was not found, return FALSE.
    return FALSE;
  }

    /**
    * Loads existing Drupal user object by given emc_user_id.
    *
    * @param string $emc_user_id
    *   Emercoin ID to search for.
    *
    * @return \Drupal\user\Entity\User|false
    *   Drupal user account if found
    *   False otherwise
    */
    public function loadUserByEmcId ($emc_user_id) {
        $query = db_select('users_data', 'u')
                ->fields('u', array('uid'))
                ->condition('u.name',   'emc_user_id', '=')
                ->condition('u.module', 'emercoin_id', '=')
                ->condition('u.value',  $emc_user_id,  '=')
                ->execute();
        $result = $query->fetchObject();

        return $result ? $this->loadUserByProperty('uid', $result->uid) : false;
    }

    /**
    * Assigns emc_user_id for a user, if such record doesn't yet exist.
    *
    * @param string $user_id
    *   Drupal uid value.
    *
    * @param string $emc_user_id
    *   Emercoin ID from certificate.
    *
    * @return boolean
    *   Status of DB query.
    */
    public function assignEmcIdToUser ($user_id, $emc_user_id) {
        $query = db_select('users_data', 'u')
                ->fields('u', array('value'))
                ->condition('u.name',   'emc_user_id', '=')
                ->condition('u.module', 'emercoin_id', '=')
                ->condition('u.uid',    $user_id,      '=')
                ->execute();

        // if these's no record for this user, we save his EMCID
        if ( !$query->fetchObject() ) {
            db_insert('users_data')
              ->fields(array(
                'name'   => 'emc_user_id',
                'module' => 'emercoin_id',
                'value'  => $emc_user_id,
                'uid'    => $user_id,
              ))
              ->execute();
        }

        return true;
    }

  /**
   * Create a new user account.
   *
   * @param array $emc_user
   *   Array of user's EmercoinID certificate data.
   *
   * @return \Drupal\user\Entity\User|false
   *   Drupal user account if user was created
   *   False otherwise
   */
  public function createUser($emc_user) {
    $emc_user_id = $emc_user['emc_user_id'];
    $name        = $this->generateUniqueUsername("{$emc_user['first_name']} {$emc_user['last_name']}");
    $email       = $this->generateUniqueEmail($emc_user['email'], $name);

    // Check if site configuration allows new users to register
    if ($this->registrationBlocked()) {
      $this->loggerFactory
        ->get('emercoin_id')
        ->warning('Failed to create user. User registration is disabled in Drupal account settings. Name: @name, email: @email.', array('@name' => $name, '@email' => $email));

      $this->drupalSetMessage($this->t('Only existing users can log in with EmercoinID. Contact system administrator.'), 'error');
      return FALSE;
    }

    // Set up the user fields.
    // - Username will be user's name on EmercoinID.
    // - Password can be very long since the user doesn't see this.
    // There are three different language fields.
    // - preferred_language
    // - preferred_admin_langcode
    // - langcode of the user entity i.e. the language of the profile fields
    // - We use the same logic as core and populate the current UI language to
    //   all of these. Other modules can subscribe to the triggered event and
    //   change the languages if they will.
    // Get the current UI language.
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $fields = array(
      'name' => $name,
      'mail' => $email,
      'init' => $email,
      'pass' => $this->userPassword(32),
      'status' => $this->getNewUserStatus(),
      'langcode' => $langcode,
      'preferred_langcode' => $langcode,
      'preferred_admin_langcode' => $langcode,
    );

    // Create new user account.
    $new_user = $this->entityTypeManager
      ->getStorage('user')
      ->create($fields);

    // Validate the new user.
    $violations = $new_user->validate();
    if (count($violations) > 0) {
      $msg = $violations[0]->getMessage();
      $this->drupalSetMessage($this->t('Creation of user account failed: @message', array('@message' => $msg)), 'error');
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not create new user: @message', array('@message' => $msg));
      return FALSE;
    }

    // Try to save the new user account.
    try {
      $new_user->save();

      $this->loggerFactory
        ->get('emercoin_id')
        ->notice('New user created. Username @username, UID: @uid', array('@username' => $new_user->getAccountName(), '@uid' => $new_user->id()));

      // Dispatch an event so that other modules can react to the user creation.
      // Set the account twice on the event: as the main subject but also in the
      // list of arguments.
      $event = new GenericEvent($new_user, ['account' => $new_user, 'emc_user_id' => $emc_user_id]);
      $this->eventDispatcher->dispatch('emercoin_id.user_created', $event);

      $this->assignEmcIdToUser($new_user->id(), $emc_user_id);

      return $new_user;
    }

    catch (EntityStorageException $ex) {
      $this->drupalSetMessage($this->t('Creation of user account failed. Please contact site administrator.'), 'error');
      $this->loggerFactory
        ->get('emercoin_id')
        ->error('Could not create new user. Exception: @message', array('@message' => $ex->getMessage()));
    }

    return FALSE;
  }

  /**
   * Logs the user in.
   *
   * @todo Add Boost integtraion when Boost is available for D8
   *   https://www.drupal.org/node/2524372
   *
   * @param \Drupal\user\Entity\User $drupal_user
   *   User object.
   *
   * @return bool
   *   True if login was successful
   *   False if the login was blocked
   */
  public function loginUser(User $drupal_user) {
    // Prevent admin login if defined in module settings.
    if ($this->loginDisabledForAdmin($drupal_user)) {
      $this->drupalSetMessage($this->t('EmercoinID login is disabled for site administrator. Login with your local user account.'), 'error');
      return FALSE;
    }

    // Prevent login if user has one of the roles defined in module settings.
    if ($this->loginDisabledByRole($drupal_user)) {
      $this->drupalSetMessage($this->t('EmercoinID login is disabled for your role. Please login with your local user account.'), 'error');
      return FALSE;
    }

    // Check that the account is active and log the user in.
    if ($drupal_user->isActive()) {
      $this->userLoginFinalize($drupal_user);

      // Dispatch an event so that other modules can react to the user login.
      // Set the account twice on the event: as the main subject but also in the
      // list of arguments.
      $event = new GenericEvent($drupal_user, ['account' => $drupal_user]);
      $this->eventDispatcher->dispatch('emercoin_id.user_login', $event);

      // TODO: Add Boost cookie if Boost module is enabled
      // https://www.drupal.org/node/2524372
      $this->drupalSetMessage($this->t('You are now logged in as @username.', array('@username' => $drupal_user->getAccountName())));
      return TRUE;
    }

    // If we are still here, account is blocked.
    $this->drupalSetMessage($this->t('You could not be logged in because your user account @username is not active.', array('@username' => $drupal_user->getAccountName())), 'warning');
    $this->loggerFactory
      ->get('emercoin_id')
      ->warning('EmercoinID login for user @user prevented. Account is blocked.', array('@user' => $drupal_user->getAccountName()));
    return FALSE;
  }

  /**
   * Checks if user registration is blocked in Drupal account settings.
   *
   * @return bool
   *   True if registration is blocked
   *   False if registration is not blocked
   */
  protected function registrationBlocked() {
    // Check if Drupal account registration settings is Administrators only.
    if ($this->configFactory
      ->get('user.settings')
      ->get('register') == 'admin_only') {
      return TRUE;
    }

    // If we didnt' return TRUE already, registration is not blocked.
    return FALSE;
  }

  /**
   * Ensures that Drupal usernames will be unique.
   *
   * Drupal usernames will be generated so that the user's full name on EmercoinID
   * will become user's Drupal username. This method will check if the username
   * is already used and appends a number until it finds the first available
   * username.
   *
   * @param string $emc_name
   *   User's full name on EmercoinID.
   *
   * @return string
   *   Unique username
   */
  protected function generateUniqueUsername($emc_name) {
    $base_name = $emc_name;
    $base_name = strtolower(trim($base_name));
    $base_name = preg_replace('/ {1,}/', '-', $base_name);

    $candidate = strlen($base_name) > 3 ? $base_name : 'emcid_' . $this->generateSuffix();
    $base_name = strlen($base_name) > 3 ? $base_name : 'emcid_';

    while ($this->loadUserByProperty('name', $candidate)) {
      $candidate = $base_name . '-' . $this->generateSuffix();
    }

    return $candidate;
  }

  /**
   * Generates guaranteed unique email based on Drupal login.
   *
   * @param string $email
   *   Email from certificate to check if it's already unique.
   *
   * @param string $drupal_name
   *   Drupal login name.
   *
   * @return string
   *   Unique email.
   */
  protected function generateUniqueEmail($email, $drupal_name) {
    if ($this->loadUserByProperty('mail', $email) || !$email) {
        $candidate = strtolower($drupal_name);
        $candidate = trim($candidate);
        $candidate = preg_replace('/ {1,}/', '', $candidate);

        while ($this->loadUserByProperty('mail', "$candidate@emercoinid.local")) {
            $suffix = $this->generateSuffix();
            $candidate = "$drupal_name-$suffix";
        }

        return "$candidate@emercoinid.local";
    } else {
        return $email;
    }
  }

    /**
     * Generate a 5 chars string [a-z0-9]
     *
     * @return string
     */
    private function generateSuffix() {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix     = '';

        for ($i = 0; $i < 5; $i++) {
            $suffix .= $characters[mt_rand(0, 35)];
        }

        return $suffix;
    }

  /**
   * Returns the status for new users.
   *
   * @return int
   *   Value 0 means that new accounts remain blocked and require approval.
   *   Value 1 means that visitors can register new accounts without approval.
   */
  protected function getNewUserStatus() {
    if ($this->configFactory
      ->get('user.settings')
      ->get('register') == 'visitors') {
      return 1;
    }

    return 0;
  }

  /**
   * Checks if current user is admin and admin login via EMC is disabled.
   *
   * @param \Drupal\user\Entity\User $drupal_user
   *   User object.
   *
   * @return bool
   *   True if current user is admin and admin login via fB is disabled.
   *   False otherwise.
   */
  protected function loginDisabledForAdmin(User $drupal_user) {
    // Check if current user is admin.
    if ($drupal_user->id() == 1) {

      // Check if admin EMC login is disabled.
      if ($this->configFactory
        ->get('emercoin_id.settings')
        ->get('disable_admin_login')) {

        $this->loggerFactory
          ->get('emercoin_id')
          ->warning('EmercoinID login for user @user prevented. EmercoinID login for site administrator (user 1) is disabled in module settings.', array('@user' => $drupal_user->getAccountName()));
        return TRUE;
      }
    }

    // User is not admin or admin login is not disabled.
    return FALSE;
  }

  /**
   * Checks if the user has one of the "EMC login disabled" roles.
   *
   * @param \Drupal\user\Entity\User $drupal_user
   *   User object.
   *
   * @return bool
   *   True if login is disabled for one of this user's role
   *   False if login is not disabled for this user's roles
   */
  protected function loginDisabledByRole(User $drupal_user) {
    // Read roles that are blocked from module settings.
    $disabled_roles = $this->configFactory
      ->get('emercoin_id.settings')
      ->get('disabled_roles');

    // Filter out allowed roles. Allowed roles have have value "0".
    // "0" evaluates to FALSE so second parameter of array_filter is omitted.
    $disabled_roles = array_filter($disabled_roles);

    // Loop through all roles the user has.
    foreach ($drupal_user->getRoles() as $role) {
      // Check if EMC login is disabled for this role.
      if (array_key_exists($role, $disabled_roles)) {
        $this->loggerFactory
          ->get('emercoin_id')
          ->warning('EmercoinID login for user @user prevented. EmercoinID login for role @role is disabled in module settings.', array('@user' => $drupal_user->getAccountName(), '@role' => $role));
        return TRUE;
      }
    }

    // EMC login is not disabled for any of the user's roles.
    return FALSE;
  }

  /**
   * Wrapper for drupal_set_message.
   *
   * We need to wrap the legacy procedural Drupal API functions so that we are
   * not using them directly in our own methods. This way we can unit test our
   * own methods.
   *
   * @see drupal_set_message
   */
  protected function drupalSetMessage($message = NULL, $type = 'status', $repeat = FALSE) {
    return drupal_set_message($message, $type, $repeat);
  }

  /**
   * Wrapper for user_password.
   *
   * We need to wrap the legacy procedural Drupal API functions so that we are
   * not using them directly in our own methods. This way we can unit test our
   * own methods.
   *
   * @see user_password
   */
  protected function userPassword($length) {
    return user_password($length);
  }

  /**
   * Wrapper for user_login_finalize.
   *
   * We need to wrap the legacy procedural Drupal API functions so that we are
   * not using them directly in our own methods. This way we can unit test our
   * own methods.
   *
   * @see user_password
   */
  protected function userLoginFinalize(UserInterface $account) {
    return user_login_finalize($account);
  }

}
