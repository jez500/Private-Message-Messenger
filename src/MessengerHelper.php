<?php

namespace Drupal\private_message_messenger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Entity\Query\QueryFactory;
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
   * Insance of QueryFactory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

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
   * The user data service
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
   * The CSRF token generator service
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
    QueryFactory $entity_query,
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
    $this->entityQuery = $entity_query;
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
   * Get threads for a given user.
   *
   * TODO: Account for pagination with timestamp.
   */
  public function getRecentThreadCollection($limit = 30) {
    $this->getThreadMaxMembers();
    $recent_threads = $this->pmService->getThreadsForUser($limit);
    $threads = $recent_threads['threads'];
    $out = [];

    foreach (array_reverse($threads) as $thread) {
      $out[] = $this->parseThread($thread);
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
  public function getThreadByID($thread_id) {
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
    $thread = $this->getThreadByID($thread_id);
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
   * @param object $thread
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
        'name' => Html::escape($member->getUsername()),
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
      $model['owner'] = Html::escape($parsed_thread->last_owner->getUsername());
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
        'members' => [[
          'name' => Html::escape($member->getUsername()),
          'id' => $member->id(),
          'url' => Url::fromRoute('entity.user.canonical', ['user' => $member->id()])->toString(),
        ]],
        'picture' => $this->getImageUrl($this->getImageUriFromMember($member)),
        'owner' => '',
        'snippet' => '',
        'timestamp' => $this->formatTimeStamp($member->getCreatedTime()),
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
   * @param object $message
   *   A parsed message provided by getThreadMessages().
   *
   * @return array
   *   A single model model array suitable for a JSON response.
   */
  public function processMessageModel($parsed_message) {
    // Expected model structure. See js/pmmm-models.js.
    $model = [
      'owner' => Html::escape($parsed_message->owner->getUsername()),
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
    if ($image = $member->get('user_picture')->first()) {
      return $image->entity->getFileUri();
    }
    return NULL;
  }

  /**
   * Get the full url for an image with image style applied.
   *
   * @param $image_uri
   *   Uri to the image.
   *
   * @return string
   *   Full url for the image with style applied.
   */
  public function getImageUrl($image_uri) {
    $style = $this->config->get('private_message.settings')->get('image_style');
    $image_stlye = !empty($style) ? $style : self::IMAGE_STYLE_DEFAULT;
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
   * @param $time
   *   Unix timestamp
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
   *   Array of values sumbitted, includes 'message', 'members' & 'thread_id'
   *
   * @return bool
   *   TRUE on success, FALSE on fail.
   */
  public function validateMessage(array $values) {
    // Require memebers and a message to save.
    if (empty($values['members']) || empty($values['message'])) {
      return FALSE;
    }

    // If we have too many members we don't proceed.
    $settings = $this->getSettings();
    if ($settings['maxMembers'] > 0 && count($members) > $settings['maxMembers']) {
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
   *   Array of values sumbitted, includes 'message', 'members' & 'thread_id'
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
      'owner' =>$this->currentUser->id(),
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
   * @param $private_message_thread
   *   The thread entity.
   * @param $message_entity
   *   The message entity.
   */
  public function emailMembers(array $members, $private_message_thread, $message_entity) {
    $pm_config = $this->config->get('private_message.settings');
    $params = [
      'private_message' => $message_entity,
      'private_message_thread' => $private_message_thread,
    ];

    foreach ($members as $member) {
      if($member->id() != $this->currentUser->id()) {
        $params['member'] = $member;
        $send = $this->userData->get('private_message', $member->id(), 'email_notification');
        $send = is_numeric($send) ? (bool) $send : ($pm_config->get('enable_email_notifications') && $pm_config->get('send_by_default'));
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
    return FALSE;
  }

  /**
   * Get the text format for a message, preferencing config setting.
   *
   * @return string
   *   Filter format id.
   */
  public function getTextFormat() {
    $prefered_format = $this->config->get('private_message.settings')->get('preferred_text_format');
    $prefered_format = empty($prefered_format) ? $prefered_format : 'plain_text';
    $formats = filter_formats($this->currentUser);
    $format = $formats[0];
    foreach ($formats as $f) {
      if ($f->id() == $prefered_format) {
        $format = $f;
      }
    }
    return $format->id();
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
    $settigs = [
      'maxMembers' => $this->getThreadMaxMembers(),
      'token' => $this->generateToken(),
    ];
    $this->moduleHandler->invokeAll('private_message_messenger_js_settings', array($settigs));
    return $settigs;
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
    return $this->csrfToken->validate($token, $value);
  }

  /**
   * Poll result for unread count, if gt 0, also include thread ids that change.
   *
   * @param int $timestamp
   *   The timestamp should be the last time it was checked.
   *
   * @retrun array
   *   Keyed with 'c' = count, 't' = thread_ids, 'ts' = timestamp.
   */
  public function getUnreadThreads($timestamp) {
    $out = [
      'c' => intval($this->mapper->getUnreadThreadCount($this->currentUser->id(), $timestamp)),
      't' => [],
      'ts' => '',
    ];
    if ($out['c'] > 0) {
      $user = User::load($this->currentUser->id());
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

}
