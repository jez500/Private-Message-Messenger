<?php

namespace Drupal\private_message_messenger\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\private_message_messenger\MessengerHelper;

/**
 * Defines the configuration form for the private message messenger module.
 */
class ConfigForm extends ConfigFormBase {

  /**
   * Messenger helper.
   *
   * @var \Drupal\private_message_messenger\MessengerHelper
   */
  protected $messengerHelper;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   * @param \Drupal\private_message_messenger\MessengerHelper $messenger_helper
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManager $entity_type_manager,
    MessengerHelper $messenger_helper
  ) {
    parent::__construct($config_factory);

    $this->messengerHelper = $messenger_helper;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('private_message_messenger.messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'private_message_messenger_config_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'private_message_messenger.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Set a default text format for messages.
    $form['preferred_text_format'] = [
      '#type' => 'select',
      '#title' => t('Prefered text format for messages'),
      '#description' => t('You should ensure that users who have access to "use private message" can also access this format.'),
      '#default_value' => $this->messengerHelper->getConfig('preferred_text_format', 'plain_text'),
      '#options' => [],
    ];
    foreach (filter_formats() as $format) {
      $form['preferred_text_format']['#options'][$format->id()] = $format->label();
    }

    // Set a default image style for messages.
    $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
    $form['image_style'] = [
      '#type' => 'select',
      '#title' => t('Profile picture Image style'),
      '#description' => t('What image style is used in the messenger interface'),
      '#default_value' => $this->messengerHelper->getConfig('image_style', $this->messengerHelper::IMAGE_STYLE_DEFAULT),
      '#options' => [],
    ];
    foreach ($styles as $style) {
      $form['image_style']['#options'][$style->id()] = $style->label();
    }

    // The default thread count to get.
    $form['thread_count'] = [
      '#type' => 'number',
      '#title' => t('Number of threads to show'),
      '#description' => t('The number of threads to be shown in the block'),
      '#default_value' => $this->messengerHelper->getConfig('thread_count', $this->messengerHelper::THREAD_COUNT_DEFAULT),
      '#min' => 1,
    ];

    // The default ajax refresh rate.
    $form['ajax_refresh_rate'] = [
      '#type' => 'number',
      '#title' => t('Ajax refresh rate'),
      '#default_value' => $this->messengerHelper->getConfig('ajax_refresh_rate', $this->messengerHelper::AJAX_REFRESH_DEFAULT),
      '#min' => 0,
      '#description' => t(
        'The number of seconds after which the inbox should refresh itself.
      Setting this to a low number will result in more requests to the server,
      adding overhead and bandwidth. Setting this number to zero will disable
      ajax refresh, and the inbox will only updated if/when the page is refreshed.'),
    ];

    // Where does it switch to desktop view.
    $form['desktop_breakpoint'] = [
      '#type' => 'number',
      '#title' => t('Desktop breakpoint'),
      '#default_value' => $this->messengerHelper->getConfig('desktop_breakpoint', $this->messengerHelper::DESKTOP_BREAKPOINT),
      '#min' => 0,
      '#description' => t('The screen width where the UI switches to desktop mode.'),
    ];

    // Snippet length.
    $form['snippet_length'] = [
      '#type' => 'number',
      '#title' => t('Snippet length'),
      '#default_value' => $this->messengerHelper->getConfig('snippet_length', $this->messengerHelper::SNIPPET_LENGTH),
      '#min' => 0,
      '#description' => t('The number of characters to display in a thread snippet teaser.'),
    ];

    // Use the enter key to send a message.
    $form['enter_key_send'] = [
      '#type' => 'checkbox',
      '#title' => t('Enter key sends a message'),
      '#description' => t('If this is unchecked, users need to click the send button to send a message. Only applies to desktop mode.'),
      '#default_value' => $this->messengerHelper->getConfig('enter_key_send', TRUE),
    ];

    // Include timeago.js from cdn.
    $form['timeago_cdn'] = [
      '#type' => 'checkbox',
      '#title' => t('Include timeago.js from remote CDN'),
      '#description' => t(
        'Include timeago.js from CDN to improve timestamps on messages
        and threads by displaying them in a "XX ago" format. If you want to
        include timeago.js locally, leave this unchecked and add the library to
        your theme or module manually. CDN used is 
        "https://cdnjs.com/libraries/timeago.js"'),
      '#default_value' => $this->messengerHelper->getConfig('timeago_cdn', FALSE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('private_message_messenger.settings');
    $current_timeago_cdn = $config->get('timeago_cdn');

    $config
      ->set('preferred_text_format', (string) $form_state->getValue('preferred_text_format'))
      ->set('image_style', (string) $form_state->getValue('image_style'))
      ->set('thread_count', (int) $form_state->getValue('thread_count'))
      ->set('ajax_refresh_rate', (int) $form_state->getValue('ajax_refresh_rate'))
      ->set('desktop_breakpoint', (int) $form_state->getValue('desktop_breakpoint'))
      ->set('snippet_length', (int) $form_state->getValue('snippet_length'))
      ->set('enter_key_send', (bool) $form_state->getValue('enter_key_send'))
      ->set('timeago_cdn', (bool) $form_state->getValue('timeago_cdn'))
      ->save();

    // If timeago_cdn changed we need a clear cache.
    if ((bool) $current_timeago_cdn !== (bool) $form_state->getValue('timeago_cdn')) {
      drupal_flush_all_caches();
    }

    parent::submitForm($form, $form_state);
  }
}

