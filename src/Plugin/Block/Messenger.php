<?php

namespace Drupal\private_message_messenger\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\private_message_messenger\MessengerHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Render\Renderer;
use Drupal\user\Entity\User;

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
   * Renderer helper.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountProxyInterface $current_user,
    MessengerHelper $helper,
    Renderer $renderer
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->currentUser = $current_user;
    $this->helper = $helper;
    $this->renderer = $renderer;
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
      $container->get('private_message_messenger.messenger'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'thread_count' => 50,
      'ajax_refresh_rate' => 15,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['thread_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of threads to show'),
      '#description' => $this->t('The number of threads to be shown in the block'),
      '#default_value' => $config['thread_count'],
      '#min' => 1,
    ];

    $form['ajax_refresh_rate'] = [
      '#type' => 'number',
      '#title' => $this->t('Ajax refresh rate'),
      '#default_value' => $config['ajax_refresh_rate'],
      '#min' => 0,
      '#description' => $this->t('The number of seconds after which the inbox should refresh itself. Setting this to a low number will result in more requests to the server, adding overhead and bandwidth. Setting this number to zero will disable ajax refresh, and the inbox will only updated if/when the page is refreshed.'),
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
    // Check access first.
    if (!$this->currentUser->isAuthenticated() || !$this->helper->checkAccess()) {
      return [];
    }

    // Build JS settings.
    $settings = _private_message_messenger_get_settings();
    $settings['threadCount'] = (int) $this->configuration['thread_count'];
    $settings['ajaxRefreshRate'] = (int) $this->configuration['ajax_refresh_rate'];
    $settings['token'] = $this->helper->generateToken();

    // Add settings and cache context.
    $build = [
      '#cache' => [
        'contexts' => ['user']
      ],
      '#attached' => [
        'drupalSettings' => [
          'pmm' => $settings,
        ]
      ]
    ];

    // Build the block.
    $build['messenger'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pmm-messenger'],
      ],
      'thread_list' => [
        '#theme' => 'pmm_threads',
      ],
      'thread' => [
        '#theme' => 'pmm_thread',
      ],
    ];

    // Return block.
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
