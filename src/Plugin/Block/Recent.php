<?php

namespace Drupal\private_message_messenger\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\private_message_messenger\MessengerHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Provides a Recent Messages Block.
 *
 * @Block(
 *  id = "private_message_messenger_recent",
 *  admin_label = @Translation("Recent Messenger Messages"),
 * )
 */
class Recent extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

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
  public function defaultConfiguration() {
    return [
      'thread_count' => 3,
      'ajax_refresh_rate' => 15,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    $settings_url = Url::fromUri('internal:/admin/config/private_message/config', []);

    $form['thread_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of threads to show'),
      '#description' => $this->t('The number of threads in the dropdown menu for this block'),
      '#default_value' => $config['thread_count'],
      '#min' => 1,
    ];

    $form['ajax_refresh_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Ajax refresh rate'),
      '#default_value' => $config['ajax_refresh_rate'],
      '#min' => 0,
      '#description' => $this->t('How often to poll for new messages, if on the messenger page, this gets trumped by the @default_settings if on the Messenger page.', [
        '@default_settings' => Link::fromTextAndUrl($this->t('Default settings'), $settings_url)->toString(),
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['thread_count'] = $form_state->getValue('thread_count');
    $this->configuration['ajax_refresh_rate'] = $form_state->getValue('ajax_refresh_rate');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    if (!$this->helper->checkAccess()) {
      return [];
    }

    // Get settings.
    $config = $this->getConfiguration();
    $settings = $this->helper->getSettings();
    $settings['recentAjaxRefreshRate'] = (int) $config['ajax_refresh_rate'];
    $settings['recentThreadCount'] = (int) $config['thread_count'];
    $settings['unreadCount'] = (int) $this->helper->getService()->getUnreadThreadCount();

    $build = [
      'recent' => [
        '#theme' => 'pmm_recent',
      ],
      'thread_teaser' => [
        '#theme' => 'pmm_thread_teaser',
        '#id_suffix' => '-recent',
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
      '#attached' => [
        'drupalSettings' => [
          'pmm' => $settings,
        ],
      ],
    ];

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
