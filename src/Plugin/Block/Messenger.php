<?php

namespace Drupal\private_message_messenger\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Messenger Block.
 *
 * @Block(
 *  id = "private_message_messenger",
 *  admin_label = @Translation("Private Message Messenger"),
 * )
 */
class Messenger extends BlockBase {

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
    $settings = _private_message_messenger_get_settings();
    $settings['threadCount'] = (int) $this->configuration['thread_count'];
    $settings['ajaxRefreshRate'] = (int) $this->configuration['ajax_refresh_rate'];

    $build = [];
    $build['messenger'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['pmm-messenger'],
      ],
      '#attached' => [
        'drupalSettings' => [
          'pmm' => $settings,
        ]
      ],
      'thread_list' => [
        '#theme' => 'pmm_threads',
      ],
      'thread' => [
        '#theme' => 'pmm_thread',
      ],
    ];
    return $build;
  }

}
