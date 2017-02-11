<?php

namespace Drupal\emercoin_id;

// use EmercoinID\PersistentData\PersistentDataInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Variables are written to and read from session via this class.
 *
 * By default, EmercoinID SDK uses native PHP sessions for storing data. We
 * implement EmercoinID\PersistentData\PersistentDataInterface using Symfony
 * Sessions so that EmercoinID SDK will use that instead of native PHP sessions.
 * Also EmercoinID reads data from and writes data to session via this
 * class.
 *
 */
class EmercoinIDPersistentDataHandler {
  protected $session;
  protected $sessionPrefix = 'emercoin_id_';

  /**
   * Constructor.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   Used for reading data from and writing data to session.
   */
  public function __construct(SessionInterface $session) {
    $this->session = $session;
  }

  /**
   * {@inheritdoc}
   */
  public function get($key) {
    return $this->session->get($this->sessionPrefix . $key);
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    $this->session->set($this->sessionPrefix . $key, $value);
  }

}
