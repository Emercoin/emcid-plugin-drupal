<?php

/**
 * @file
 * Rules hook implementations for Emercoin ID module.
 */

/**
 * Implements hook_rules_event_info().
 *
 * Defines a rules event to react on Emercoin ID login and user creation
 *
 * @ingroup rules
 */
function emercoin_id_rules_event_info() {
  return array(
    'emercoin_id_login' => array(
      'label' => t('User logs in to their existing Drupal account with Emercoin ID'),
      'module' => 'emercoin_id',
      'group' => t('Authentication'),
      'variables' => array(
        'user' => array('type' => 'user', 'label' => t('The user logging in with Emercoin ID')),
      ),
    ),
    'emercoin_id_registration' => array(
      'label' => t('User registers a new account connected to Emercoin ID'),
      'module' => 'emercoin_id',
      'group' => t('Authentication'),
      'variables' => array(
        'user' => array('type' => 'user', 'label' => t('The user registering the account')),
      ),
    ),
  );
}

/**
 * Implements hook_rules_condition_info().
 *
 * @ingroup rules
 */
function emercoin_id_rules_condition_info() {
  return array(
    'emercoin_id_user_has_emc_session' => array(
      'label' => t('User has Emercoin ID session'),
      'module' => 'emercoin_id',
      'group' => t('Authentication'),
    ),
  );
}

/**
 * Rule condition: Check if user has a valid Emercoin ID session.
 *
 * @return bool
 *   True, if use has a valid Emercoin ID session.
 *   False otherwise.
 */
function emercoin_id_user_has_emc_session() {
  return emercoin_id_get_session() ? TRUE : FALSE;
}
