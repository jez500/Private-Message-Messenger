<?php

namespace Drupal\private_message_messenger\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\private_message_messenger\MessengerHelper;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a Messenger Block.
 *
 * @Block(
 *  id = "private_message_messenger",
 *  admin_label = @Translation("Private Message Messenger"),
 * )
 */
class Messenger extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Messenger helper.
   *
   * @var \Drupal\private_message_messenger\MessengerHelper
   */
  protected $helper;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountProxyInterface $current_user,
    MessengerHelper $helper
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->currentUser = $current_user;
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('private_message_messenger.messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = $this->helper->buildMessenger();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $cache_tags = parent::getCacheTags();
    $cache_tags[] = 'private_message_messenger:uid:' . $this->currentUser->id();
    return $cache_tags;
  }

}
