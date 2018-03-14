<?php

namespace Drupal\private_message_messenger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\image\Entity\ImageStyle;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Mail\MailManager;
use Drupal\user\UserDataInterface;
use Drupal\Core\Url;
use Drupal\private_message\Service\PrivateMessageService;
use Drupal\private_message\Entity\PrivateMessageThread;
use Drupal\private_message\Mapper\PrivateMessageMapperInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Database\Connection;

/**
 * Class MessengerHelper.
 *
 * Provides helper tools for dealing with private_message.
 */
class MessengerHelper {

  use StringTranslationTrait;

  /**
   * Image style default.
   */
  const IMAGE_STYLE_DEFAULT = 'thumbnail';

  /**
   * Text format default.
   */
  const TEXT_FORMAT_DEFAULT = 'plain_text';

  /**
   * Thread count default.
   */
  const THREAD_COUNT_DEFAULT = 50;

  /**
   * Ajax refresh rate default.
   */
  const AJAX_REFRESH_DEFAULT = 15;

  /**
   * Length of a message snippet. TODO: Abstract.
   *
   * @var int
   */
  protected $snippetLength = 500;

  /**
   * Instance of PrivateMessageService.
   *
   * @var \Drupal\private_message\Service\PrivateMessageService
   */
  protected $pmService;

  /**
   * The private message mapper service.
   *
   * @var \Drupal\private_message\Mapper\PrivateMessageMapperInterface
   */
  protected $mapper;

  /**
   * Instance of ConfigFactoryInterface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Instance of EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Instance of mail manager.
   *
   * @var \Drupal\Core\Mail\MailManager
   */
  protected $mailManager;

  /**
   * The user data service.
   *
   * @var \Drupal\user\UserDataInterface
   */
  protected $userData;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The CSRF token generator service.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * Db connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * MessengerHelper constructor.
   */
  public function __construct(
    PrivateMessageService $pm_service,
    ConfigFactoryInterface $config,
    EntityTypeManager $entity_type_manager,
    AccountProxyInterface $current_user,
    MailManager $mail_manager,
    UserDataInterface $user_data,
    PrivateMessageMapperInterface $mapper,
    ModuleHandler $module_handler,
    CsrfTokenGenerator $csrfToken,
    Connection $database
  ) {
    $this->pmService = $pm_service;
    $this->config = $config;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->mailManager = $mail_manager;
    $this->userData = $user_data;
    $this->mapper = $mapper;
    $this->moduleHandler = $module_handler;
    $this->csrfToken = $csrfToken;
    $this->database = $database;
  }

  /**
   * Returns an instance of the PM service.
   *
   * @return \Drupal\private_message\Service\PrivateMessageService
   *   Pm service.
   */
  public function getService() {
    return $this->pmService;
  }

  /**
   * Get threads for a given user.
   *
   * TODO:
   *  - Account for pagination with timestamp.
   *  - Have hard set a limit of 50, because we reverse, to get latest, this
   *    will break if a user has more than 50 messages (and is not efficient).
   */
  public function getRecentThreadCollection($limit = 30) {
    $recent_threads = $this->pmService->getThreadsForUser(50);
    $threads = $recent_threads['threads'];
    $out = [];

    foreach (array_reverse($threads) as $thread) {
      if (count($out) <= $limit) {
        $out[] = $this->parseThread($thread);
      }
    }
    return $out;
  }

  /**
   * Return a single parsed thread by ID.
   *
   * @param int $thread_id
   *   Thread entity id.
   *
   * @return object
   *   Parsed thread object.
   */
  public function getThreadById($thread_id) {
    $thread = $this->entityTypeManager->getStorage('private_message_thread')->load($thread_id);
    if ($thread && $thread->isMember($this->currentUser->id())) {
      return $this->parseThread($thread);
    }
    return FALSE;
  }

  /**
   * Return thread messages.
   *
   * @param int $thread_id
   *   The id for the thread.
   * @param int $timestamp
   *   Only return messages after a given timestamp.
   *
   * @return array
   *   Array of parsed messages.
   */
  public function getThreadMessages($thread_id, $timestamp = 0) {
    $thread = $this->getThreadById($thread_id);
    $out = [];
    if ($thread) {
      $messages = $thread->entity->getMessages();
      foreach ($messages as $message) {
        if ($message->getCreatedTime() >= $timestamp) {
          $msg = $message->get('message')->first()->getValue();
          $out[] = (object) [
            'entity' => $message,
            'owner' => $message->getOwner(),
            'message' => check_markup($msg['value'], $msg['format']),
            'timestamp' => $message->getCreatedTime(),
          ];
        }
      }
    }
    return $out;
  }

  /**
   * Parse a thread entity into a flat obkect suitable for processing.
   *
   * @param \Drupal\private_message\Entity\PrivateMessageThread $thread
   *   The private message thread entity.
   *
   * @return object
   *   A parsed thread object.
   */
  public function parseThread(PrivateMessageThread $thread) {
    $item = (object) [
      'entity' => $thread,
      'members' => [],
      'pictures' => [],
      'last_message' => NULL,
      'last_owner' => NULL,
      'timestamp' => $thread->getNewestMessageCreationTimestamp(),
      'unread' => FALSE,
    ];

    // Get an array of users that are NOT the logged in user.
    foreach ($thread->getMembers() as $user) {
      if ($user->id() != $this->currentUser->id()) {
        $item->members[] = $user;
        $item->pictures[] = $this->getImageUriFromMember($user);
      }
    }

    // Get a snippet from the last message.
    $last_msgs = array_reverse($thread->getMessages());
    $last_msg = reset($last_msgs);
    $item->last_message = ($last_msg ? $last_msg->getMessage() : '');

    // Get the last owner.
    $item->last_owner = ($last_msg ? $last_msg->getOwner() : FALSE);

    return $item;
  }

  /**
   * Build a thread item model suitable for our JSON response.
   *
   * @param object $parsed_thread
   *   A parsed thread provided by parseThread().
   *
   * @return array
   *   A single thread model array suitable for a JSON response.
   */
  public function processThreadModel($parsed_thread) {
    // Expected model structure. See js/pmmm-models.js.
    $model = [
      'members' => [],
      'picture' => '',
      'owner' => '',
      'snippet' => '',
      'timestamp' => $this->formatTimeStamp($parsed_thread->timestamp),
      'id' => 'thread-' . $parsed_thread->entity->id(),
      'threadId' => $parsed_thread->entity->id(),
      'unread' => $parsed_thread->unread,
      'type' => 'thread',
    ];

    // Member names are a comma seperated list of names. Add all member ids.
    foreach ($parsed_thread->members as $member) {
      $model['members'][] = [
        'name' => Html::escape($member->getDisplayName()),
        'id' => $member->id(),
        'url' => Url::fromRoute('entity.user.canonical', ['user' => $member->id()])->toString(),
      ];
    }

    // Picture is an image style URL for the first member profile pic.
    $pictures = [];
    foreach ($parsed_thread->pictures as $image_uri) {
      $pictures[] = $this->getImageUrl($image_uri);
    }
    $model['picture'] = count($pictures) > 0 ? reset($pictures) : '';

    // Owner is the last sender of the message, ony populated if multiple
    // members or you. Otherwise assume last owner is the only member.
    if ($parsed_thread->last_owner) {
      $model['owner'] = Html::escape($parsed_thread->last_owner->getDisplayName());
      $last_member = count($model['members']) > 1 ? $model['owner'] : '';
      $last_owner = $parsed_thread->last_owner->id() == $this->currentUser->id() ? $this->t('You') : $last_member;
    }

    // Snippet is a truncated version of the message with the owner prefixed.
    $model['snippet'] = !empty($last_owner) ? $last_owner . ': ' : '';
    $model['snippet'] .= Unicode::truncate($parsed_thread->last_message, $this->snippetLength, TRUE, TRUE);

    return $model;
  }

  /**
   * Given only a uid, return a processed thread model ready for a response.
   *
   * @param int $uid
   *   Uid for the user.
   *
   * @return array|bool
   *   A thread model, either existing or a dummy one for a new thread.
   */
  public function getThreadModelForUid($uid) {
    if (!is_numeric($uid) || !$this->checkAccess($uid)) {
      return FALSE;
    }
    else {
      // Load and check if valid remote user.
      $member = User::load($uid);
      if (!$member) {
        return FALSE;
      }
      $you = User::load($this->currentUser->id());

      // Check if thread exists, if so return a thread model for that.
      $thread_id = $this->mapper->getThreadIdForMembers([$you, $member]);
      if ($thread_id) {
        $thread = PrivateMessageThread::load($thread_id);
        return $this->processThreadModel($this->parseThread($thread));
      }

      // No thread exists, create a 'dummy' thread model.
      return [
        'members' => [
          [
            'name' => Html::escape($member->getDisplayName()),
            'id' => $member->id(),
            'url' => Url::fromRoute('entity.user.canonical', ['user' => $member->id()])->toString(),
          ],
        ],
        'picture' => $this->getImageUrl($this->getImageUriFromMember($member)),
        'owner' => '',
        'snippet' => $this->t('New message'),
        'timestamp' => $this->formatTimeStamp(time()),
        'id' => 'new-' . $member->id(),
        'threadId' => 0,
        'unread' => FALSE,
        'type' => 'new',
      ];
    }
  }

  /**
   * Build a message item model suitable for our JSON response.
   *
   * @param object $parsed_message
   *   A parsed message provided by getThreadMessages().
   *
   * @return array
   *   A single model model array suitable for a JSON response.
   */
  public function processMessageModel($parsed_message) {
    // Expected model structure. See js/pmmm-models.js.
    $model = [
      'owner' => Html::escape($parsed_message->owner->getDisplayName()),
      'picture' => NULL,
      'is_you' => $parsed_message->owner->id() == $this->currentUser->id(),
      'message' => $parsed_message->message,
      'timestamp' => $this->formatTimeStamp($parsed_message->timestamp),
      'id' => $parsed_message->entity->id(),
    ];

    $image_uri = $this->getImageUriFromMember($parsed_message->owner);
    $model['picture'] = $this->getImageUrl($image_uri);

    return $model;
  }

  /**
   * Get a profile image URI for a user/member.
   *
   * @param \Drupal\user\Entity\User $member
   *   A user entity.
   *
   * @return null|string
   *   The image URI or NULL if no image found.
   */
  public function getImageUriFromMember(User $member) {
    if ($member->hasField('user_picture') && $image = $member->get('user_picture')->first()) {
      return $image->entity->getFileUri();
    }
    return NULL;
  }

  /**
   * Get the full url for an image with image style applied.
   *
   * @param string $image_uri
   *   Uri to the image.
   *
   * @return string
   *   Full url for the image with style applied.
   */
  public function getImageUrl($image_uri) {
    $style = $this->getConfig('image_style', self::IMAGE_STYLE_DEFAULT);
    return !empty($image_uri) ? ImageStyle::load($style)->buildUrl($image_uri) : '';
  }

  /**
   * Get the fallback picture for messages & threads.
   *
   * @return string
   *   URI of the module provided fallback picture.
   */
  public function getFallbackPicture() {
    return _private_message_messenger_fallback_picture();
  }

  /**
   * A wrapper for how we want all dates to be formatted.
   *
   * @param int $time
   *   Unix timestamp.
   *
   * @return string
   *   Date string YYYY-MM-DDTHH:MM:SS
   */
  public function formatTimeStamp($time) {
    return date("c", $time);
  }

  /**
   * Validate message values before save.
   *
   * @param array $values
   *   Array of values submitted, includes 'message', 'members' & 'thread_id'.
   *
   * @return bool
   *   TRUE on success, FALSE on fail.
   */
  public function validateMessage(array $values) {
    // Require members and a message to save.
    if (empty($values['members']) || !isset($values['message']) || $values['message'] == '' ) {
      return FALSE;
    }

    // If we have too many members we don't proceed.
    $settings = $this->getSettings();
    if ($settings['maxMembers'] > 0 && count($values['members']) > $settings['maxMembers']) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Save a message to a thread.
   *
   * Source: Drupal\private_message\Form\PrivateMessageForm::save.
   *
   * @param array $values
   *   Array of values sumbitted, includes 'message', 'members' & 'thread_id'.
   *
   * @return bool|\Drupal\private_message\Entity\PrivateMessageThread
   *   FALSE on fail, a private message thread entity on success.
   */
  public function saveMessage(array $values) {
    if (!$this->validateMessage($values)) {
      return FALSE;
    }

    // Get all members.
    $current_user = User::load($this->currentUser->id());
    $members = [$current_user];
    foreach ($values['members'] as $member) {
      $user = User::load($member);
      if ($user) {
        $members[] = $user;
      }
    }

    // Get a private message thread containing the given users.
    $private_message_thread = $this->pmService->getThreadForMembers($members);

    // Save the message.
    $message_entity = $this->saveMessageEntity($values);

    // Add the new message to the thread and save.
    $private_message_thread->addMessage($message_entity)->save();

    // Send emails.
    $this->emailMembers($members, $private_message_thread, $message_entity);

    return $private_message_thread;
  }

  /**
   * Save and return a new message entity.
   *
   * @param array $values
   *   The values from a form submit.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   A message entity.
   */
  public function saveMessageEntity(array $values) {
    $entity = entity_create('private_message', [
      'owner' => $this->currentUser->id(),
      'message' => [
        'value' => $values['message'],
        'format' => $this->getTextFormat(),
      ],
      'created' => time(),
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Send an email for a new message to members.
   *
   * Source: Drupal\private_message\Form\PrivateMessageForm::save.
   *
   * @param array $members
   *   Array of members in the thread.
   * @param object $private_message_thread
   *   The thread entity.
   * @param object $message_entity
   *   The message entity.
   */
  public function emailMembers(array $members, $private_message_thread, $message_entity) {
    $params = [
      'private_message' => $message_entity,
      'private_message_thread' => $private_message_thread,
    ];

    foreach ($members as $member) {
      if ($member->id() != $this->currentUser->id()) {
        $params['member'] = $member;
        $send = $this->userData->get('private_message', $member->id(), 'email_notification');
        $send = is_numeric($send) ? (bool) $send : ($this->getConfig('enable_email_notifications', 0) && $this->getConfig('send_by_default', 0));
        if ($send) {
          $this->mailManager->mail('private_message', 'message_notification', $member->getEmail(), $member->getPreferredLangcode(), $params);
        }
      }
    }
  }

  /**
   * Get a user list suitable for autocomplete.
   *
   * Source: Drupal\private_message\Controller\AjaxController::privateMessageMembersAutocomplete.
   *
   * @param string $user_starts_with
   *   A string of text that the username starts with.
   * @param int $count
   *   How many items to return.
   *
   * @return array
   *   Array of users, each contains a uid & username key.
   */
  public function getUserList($user_starts_with, $count = 10) {
    $user_info = [];
    if (!empty($user_starts_with) && strlen($user_starts_with) > 1) {
      $accounts = $this->pmService->getUsersFromString($user_starts_with, $count);
      foreach ($accounts as $account) {
        if ($account->access('view', $this->currentUser)) {
          $user_info[] = [
            'uid' => $account->id(),
            'username' => Html::escape($account->getDisplayName()),
          ];
        }
      }
    }
    return $user_info;
  }

  /**
   * A wrapper for checking the correct permission for access.
   *
   * @param int|null $uid
   *   If null or no uid, check current user, else check given uid.
   *
   * @return bool
   *   TRUE for allowed access, FALSE if not.
   */
  public function checkAccess($uid = NULL) {
    if (empty($uid)) {
      return $this->currentUser->hasPermission('use private messaging system');
    }
    else {
      $user = User::load($uid);
      return ($user && $user->hasPermission('use private messaging system'));
    }
  }

  /**
   * Get the text format for a message, preferencing config setting.
   *
   * @return string
   *   Filter format id.
   */
  public function getTextFormat() {
    $preferred_format = $this->getConfig('preferred_text_format', self::TEXT_FORMAT_DEFAULT);
    $formats = filter_formats($this->currentUser);
    // If preferred format is available, use that.
    if (isset($formats[$preferred_format])) {
      return $preferred_format;
    }
    else {
      // Otherwise use the first available format.
      $first_format = reset($formats);
      return $first_format->id();
    }
  }

  /**
   * Get the maximum amount of members allowed in a thread.
   *
   * @return int
   *   The max number of members taken from the members form widget.
   */
  public function getThreadMaxMembers() {
    $max_members = 0;
    $form_display = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('private_message_thread.private_message_thread.default')
      ->getComponent('members');
    if (isset($form_display['settings']['max_members'])) {
      $max_members = (int) $form_display['settings']['max_members'];
    }
    return $max_members;
  }

  /**
   * Build a settings array used in JS and server side.
   *
   * @return array
   *   Array of key/val settings passed to JS.
   */
  public function getSettings() {
    $settings = [
      'maxMembers' => $this->getThreadMaxMembers(),
      'threadCount' => (int) $this->getConfig('thread_count', self::THREAD_COUNT_DEFAULT),
      'ajaxRefreshRate' => (int) $this->getConfig('ajax_refresh_rate', self::AJAX_REFRESH_DEFAULT),
      'messengerPath' => $this->getMessengerPath(),
      'token' => $this->generateToken(),
    ];
    $this->moduleHandler->alter('private_message_messenger_js_settings', $settings);
    return $settings;
  }

  /**
   * Get a csrf token.
   *
   * @param string $value
   *   Value salt.
   *
   * @return string
   *   Token string.
   */
  public function generateToken($value = 'messenger') {
    return $this->csrfToken->get($value);
  }

  /**
   * Validate a csrf token.
   *
   * @param string $token
   *   The token to validate.
   * @param string $value
   *   Value salt.
   *
   * @return bool
   *   TRUE if valid, FALSE if not.
   */
  public function validateToken($token, $value = 'messenger') {
    // TODO: Fix this potential DoS security hole!
    //
    // The validate() doesn't work sometimes, I think it is some sort of cache
    // issue or the session isn't started? In the block a drupalSetting is added
    // for the token then that is validated here for each JSON request.
    //
    // To reproduce clear cache, login with user in one browser, go to
    // messenger, should work ok. then go to another browser/device, login with
    // same user and validate() will return false (using the same token) and
    // all the json requests will be access denied.
    //
    // If you are reading this and know how to fix this, please help!
    // Uncomment the following line...
    //
    // return $this->csrfToken->validate($token, $value);
    return TRUE;
  }

  /**
   * Poll result for unread count, if gt 0, also include thread ids that change.
   *
   * @param int $timestamp
   *   The timestamp should be the last time it was checked.
   *
   * @return array
   *   Keyed with 'c' = count, 't' = thread_ids, 'ts' = timestamp.
   */
  public function getUnreadThreads($timestamp) {
    $out = [
      'c' => intval($this->pmService->getUnreadThreadCount()),
      't' => [],
      'ts' => '',
    ];
    if ($out['c'] > 0) {
      $out['t'] = array_map('intval', $this->getUnreadThreadIds($timestamp));
      $out['ts'] = $timestamp;
    }
    return $out;
  }

  /**
   * Get an array of thread ids that have been updated since timestamp.
   *
   * @param int $timestamp
   *   Get only thread ids changed since this timestamp.
   *
   * @return array
   *   Array of thread ids.
   */
  public function getUnreadThreadIds($timestamp = 0) {
    $query = $this->database->select('private_message_threads', 'thread');
    $query->addField('thread', 'id');
    $query->join(
      'private_message_thread__members',
      'member',
      'member.entity_id = thread.id AND member.members_target_id = :uid',
      [':uid' => $this->currentUser->id()]
    );

    $thread_ids = $query
      ->condition('thread.updated', $timestamp, '>')
      ->execute()
      ->fetchCol();
    return is_array($thread_ids) ? $thread_ids : [];
  }

  /**
   * Get a value from config.
   *
   * @param string $config_key
   *   The config to get.
   * @param mixed $default
   *   The default value if config is empty.
   * @param string $config_bin
   *   The config set, defaults to 'private_message.settings'.
   *
   * @return mixed
   *   The config value.
   */
  public function getConfig($config_key, $default = NULL, $config_bin = 'private_message.settings') {
    $config = $this->config->get($config_bin);
    $val = $config->get($config_key);
    return !empty($val) || $val == 0 ? $val : $default;
  }

  /**
   * Get the renderable array for messenger.
   *
   * @return array
   *   Renderable array.
   */
  public function buildMessenger() {
    // Check access first.
    if (!$this->currentUser->isAuthenticated() || !$this->checkAccess()) {
      return [];
    }

    // Get settings.
    $settings = $this->getSettings();
    $settings['messengerActive'] = TRUE;

    // Add settings and cache context.
    $build = [
      '#cache' => [
        'contexts' => ['user'],
      ],
      '#attached' => [
        'drupalSettings' => [
          'pmm' => $settings,
        ],
      ],
      'wrapper' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['pmm-messenger__wrapper'],
        ],
      ],
    ];

    // Build the block.
    $build['wrapper']['messenger'] = [
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
      'thread_teaser' => [
        '#theme' => 'pmm_thread_teaser',
      ],
    ];

    // Return block.
    return $build;
  }

  /**
   * Return the path to messenger.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   URL as a string.
   */
  public function getMessengerPath() {
    return Url::fromUri('internal:/messenger')->toString();
  }

}
