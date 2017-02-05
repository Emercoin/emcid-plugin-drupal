<?php

namespace Drupal\emercoin_id\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\emercoin_id\EmercoinIDEmcManager;
use Drupal\emercoin_id\EmercoinIDUserManager;
use Drupal\emercoin_id\EmercoinIDPostLoginManager;
use Drupal\emercoin_id\EmercoinIDPersistentDataHandler;
use Drupal\emercoin_id\EmercoinIDFactory;

/**
 * Returns responses for Emercoin ID module routes.
 */
class EmercoinIDController extends ControllerBase {

  protected $emcManager;
  protected $userManager;
  protected $postLoginManager;
  protected $persistentDataHandler;
  protected $emcFactory;

  /**
   * Constructor.
   *
   * The constructor parameters are passed from the create() method.
   *
   * @param \Drupal\emercoin_id\EmercoinIDEmcManager $emc_manager
   *   EmercoinIDEmcManager object.
   * @param \Drupal\emercoin_id\EmercoinIDUserManager $user_manager
   *   EmercoinIDUserManager object.
   * @param \Drupal\emercoin_id\EmercoinIDPostLoginManager $post_login_manager
   *   EmercoinIDPostLoginManager object.
   * @param \Drupal\emercoin_id\EmercoinIDPersistentDataHandler $persistent_data_handler
   *   EmercoinIDPersistentDataHandler object.
   * @param \Drupal\emercoin_id\EmercoinIDFactory $emc_factory
   *   EmercoinIDFactory object.
   */
  public function __construct(EmercoinIDEmcManager $emc_manager, EmercoinIDUserManager $user_manager, EmercoinIDPostLoginManager $post_login_manager, EmercoinIDPersistentDataHandler $persistent_data_handler, EmercoinIDFactory $emc_factory) {
    $this->emcManager = $emc_manager;
    $this->userManager = $user_manager;
    $this->postLoginManager = $post_login_manager;
    $this->persistentDataHandler = $persistent_data_handler;
    $this->emcFactory = $emc_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('emercoin_id.emc_manager'),
      $container->get('emercoin_id.user_manager'),
      $container->get('emercoin_id.post_login_manager'),
      $container->get('emercoin_id.persistent_data_handler'),
      $container->get('emercoin_id.emc_factory')
    );
  }

  /**
   * Response for path 'user/emercoin-id-login'.
   *
   * Redirects the user to EMC for authentication.
   */
  public function redirectToEmc() {
    global $base_url;

    $config = $this->emcFactory->getEmcService();

    // Save post login path to session if it was set as a query parameter.
    if ($post_login_path = $this->postLoginManager->getPostLoginPathFromRequest()) {
      $this->postLoginManager->savePostLoginPath($post_login_path);
    }

    $authQuery = http_build_query(
        [
            'client_id' => $config['app_id'],
            'redirect_uri' => "$base_url/user/emercoin-id-login/return",
            'response_type' => 'code',
        ]
    );

    $emc_login_url = $config['auth_page'] . '?' . $authQuery;
    return new TrustedRedirectResponse($emc_login_url);
  }

  /**
   * Response for path 'user/emercoin-id-login/return'.
   *
   * EmercoinID returns the user here after user has authenticated in EMC.
   */
  public function returnFromEmc() {
    if (!$config = $this->emcFactory->getEmcService()) {
      drupal_set_message($this->t('Emercoin ID not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    if (array_key_exists('code', $_REQUEST) && array_key_exists('state', $_REQUEST) && !array_key_exists('error', $_REQUEST )) {
        $connect = $config['token_page'];
        global $base_url;

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => join(
                    "\r\n",
                    [
                        'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                        'Accept-Charset: utf-8;q=0.7,*;q=0.7',
                    ]
                ),
                'content' => http_build_query(
                    [
                        'code' => $_REQUEST['code'],
                        'client_id' => $config['app_id'],
                        'client_secret' => $config['app_secret'],
                        'grant_type' => 'authorization_code',
                        'redirect_uri' => "$base_url/user/emercoin-id-login/return",
                    ]
                ),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
            'ssl' => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
        ];

        $response = @file_get_contents($connect, false, stream_context_create($opts));
        $response = json_decode($response, true);

        if (!array_key_exists('error', $response)) {
            $infocard_url = $config['infocard'];
            $infocard_url .= '/'.$response['access_token'];

            // Save access token to session so that event subscribers can call EMC API.
            $this->persistentDataHandler->set('emc_access_token', $response['access_token']);

            $opts = [
                'http' => [
                    'method' => 'GET',
                    'ignore_errors' => true,
                    'timeout' => 10,
                ],
                'ssl' => [
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ],
            ];
            $info = @file_get_contents($infocard_url, false, stream_context_create($opts));
            $info = json_decode($info, true);

            $emc_user = [
                'emc_user_id'   => strtolower($info['SSL_CLIENT_M_SERIAL']),
                'email'         => isset($info['infocard']['Email'])     ? $info['infocard']['Email']     : '',
                'first_name'    => isset($info['infocard']['FirstName']) ? $info['infocard']['FirstName'] : '',
                'last_name'     => isset($info['infocard']['LastName'])  ? $info['infocard']['LastName']  : '',
                'alias'         => isset($info['infocard']['Alias'])     ? $info['infocard']['Alias']     : '',
            ];

            if ( empty( $emc_user['emc_user_id'] ) ) {
                drupal_set_message($this->t('Invalid User'), 'error');
                return $this->redirect('user.login');
            }

            if ($drupal_user = $this->userManager->loadUserByEmcId($emc_user['emc_user_id'])) { // LOGIN
                if ($this->userManager->loginUser($drupal_user)) {
                    return new RedirectResponse($this->postLoginManager->getPostLoginPath());
                } else {
                    $this->persistentDataHandler->set('emc_access_token', NULL);
                    drupal_set_message($this->t("Login proccess with this EmercoinID certificate wasn't successful."), 'error');
                    return $this->redirect('user.login');
                }
            } else { // REGISTER USER
                if ($drupal_user = $this->userManager->createUser($emc_user)) {
                    // Log the newly created user in.
                    if ($this->userManager->loginUser($drupal_user)) {
                        // Check if new users should be redirected to Drupal user form.
                        if ($this->postLoginManager->getRedirectNewUsersToUserFormSetting()) {
                            drupal_set_message($this->t("Please check your account details. Since you logged in with Emercoin ID, you don't need to update your password."));
                            return new RedirectResponse($this->postLoginManager->getPathToUserForm($drupal_user));
                        }

                        // Use normal post login path if user wasn't redirected to user form.
                        return new RedirectResponse($this->postLoginManager->getPostLoginPath());
                    } else {
                        // New user was created but the account is pending approval.
                        $this->persistentDataHandler->set('emc_access_token', NULL);
                        drupal_set_message($this->t('You will receive an email when site administrator activates your account.'), 'warning');
                        return $this->redirect('user.login');
                    }
                } else {
                    // User could not be created
                    $this->persistentDataHandler->set('emc_access_token', NULL);
                    drupal_set_message($this->t('You will receive an email when site administrator activates your account.'), 'warning');
                    return $this->redirect('user.login');
                }
            }
        } else {
            drupal_set_message($response['error_description'], 'error');
            return $this->redirect('user.login');
        }
    } else {
        drupal_set_message($_REQUEST['error_description'], 'error');
        return $this->redirect('user.login');
    }

    // This should never be reached, user should have been redirected already.
    $this->persistentDataHandler->set('emc_access_token', NULL);
    throw new AccessDeniedHttpException();
  }

}
