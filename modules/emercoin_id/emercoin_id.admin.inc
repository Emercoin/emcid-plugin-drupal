<?php

/**
 * @file
 * Administration page callbacks for the Emercoin ID login module.
 */
function emercoin_id_api_keys_settings($form, &$form_state) {
    drupal_set_message(t('Installation and configuration instructions can be found at <a href="@url">@url</a>', array('@url' => 'https://github.com/Emercoin/emcid-plugin-drupal')));
    drupal_set_message(t('Also check <a href="@url">Drupal account settings</a>', array('@url' => url('admin/config/people/accounts'))));

    $form['emc_settings'] = array(
        '#type' => 'fieldset',
        '#title' => t('App settings'),
        '#description' => t('You need to first create an Emercoin ID App (at <a href="@emercoinid-dev">@emercoinid-dev</a>)', array('@emercoinid-dev' => 'https://oauth.authorizer.io')),
    );

    $form['emc_settings']['emercoin_id_app_id'] = array(
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => t('App Client ID'),
        '#default_value' => variable_get('emercoin_id_app_id', NULL),
        '#description' => t("Paste your App's Client ID"),
    );

    $form['emc_settings']['emercoin_id_app_secret'] = array(
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => t('App Secret Key'),
        '#default_value' => variable_get('emercoin_id_app_secret', NULL),
        '#description' => t("Paste your App's Secret Key"),
    );

    $form['module_settings'] = array(
        '#type' => 'fieldset',
        '#title' => t('Drupal integration'),
        '#description' => t('These settings allow you to configure how Emercoin ID module behaves on your Drupal site')
    );

    $form['module_settings']['emercoin_id_post_login_url'] = array(
        '#type' => 'textfield',
        '#title' => t('Post login path'),
        '#description' => t('Drupal path where the user should be redirected after successful login. Use <em>&lt;front&gt;</em> to redirect user to your front page.'),
        '#default_value' => variable_get('emercoin_id_post_login_url', 'user'),
    );

    $form['module_settings']['emercoin_id_redirect_user_form'] = array(
        '#type' => 'checkbox',
        '#title' => t('Redirect new users to Drupal user form'),
        '#description' => t('If you check this, new users are redirected to Drupal user form after the user is created. This is useful if you want to encourage users to fill in additional user fields.'),
        '#default_value' => variable_get('emercoin_id_redirect_user_form', 0),
    );

    $form['module_settings']['emercoin_id_login_only'] = array(
        '#type' => 'checkbox',
        '#title' => t('Login Only (No Registration)'),
        '#description' => t('Allow only existing users to login with Emercoin ID. New users can not register using Emercoin ID.'),
        '#default_value' => variable_get('emercoin_id_login_only', 0),
    );

    $form['module_settings']['emercoin_id_disable_admin_login'] = array(
        '#type' => 'checkbox',
        '#title' => t('Disable Emercoin ID login for administrator'),
        '#description' => t('Disabling Emercoin ID for administrator (<em>user 1</em>) can help protect your site if a security vulnerability is ever discovered in Emercoin ID SDK or this module.'),
        '#default_value' => variable_get('emercoin_id_disable_admin_login', 1),
    );

    // Option to disable Emercoin ID login for specific roles.
    // - roles 1 & 2 (anonymous & authenticated users) are not sensible options
    // - Drupal 7 has a default role 'administrator'. Block this role by default if
    //   emercoin_id_disabled_roles has not been set.
    // - we save this setting by role name and not id so that the configuration
    //   is transferable with Features: https://www.drupal.org/node/2653060
    $roles = user_roles();
    $options = array();
    $defaults = variable_get('emercoin_id_disabled_roles', array());
    foreach ($roles as $rid => $label) {
        if ($rid > 2) {
            $options[$label] = check_plain($label);
        }
        if (empty($defaults) && $label == 'administrator') {
            $defaults[$label] = $label;
        }
    }
    $form['module_settings']['emercoin_id_disabled_roles'] = array(
        '#type' => 'checkboxes',
        '#title' => t('Disable Emercoin ID login for the following roles'),
        '#options' => $options,
        '#default_value' => $defaults,
    );
    if (empty($options)) {
        $form['module_settings']['emercoin_id_disabled_roles']['#description'] = t('No roles found.');
    }

    return system_settings_form($form);
}
