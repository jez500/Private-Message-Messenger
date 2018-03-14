<?php

namespace Drupal\private_message_messenger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\private_message_messenger\MessengerHelper;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Extension\ModuleHandler;

/**
 * Class JsonEndpoint.
 *
 * @package Drupal\private_message_messenger\JsonEndpoint
 */
class JsonEndpoint extends ControllerBase {

  /**
   * Messenger helper.
   *
   * @var \Drupal\private_message_messenger\MessengerHelper
   */
  protected $helper;

  /**
   * Current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(MessengerHelper $calculator_factory, ModuleHandler $module_handler) {
    $this->helper = $calculator_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('private_message_messenger.messenger'),
      $container->get('module_handler')
    );
  }

  /**
   * Handle get requests for content.
   *
   * @param string $action
   *   The action to take.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function get($action, Request $request) {
    $this->request = $request;
    $valid_actions = [
      'threads', 'thread', 'messages', 'member', 'members', 'poll',
    ];
    $response = [];

    // Check access before doing anything.
    if (!$this->helper->validateToken($request->query->get('tok')) || !$this->helper->checkAccess()) {
      $response = $this->formatError($this->t('Access denied'));
      return $this->jsonResponse($response);
    }

    // Switch on our endpoint.
    switch ($action) {

      // Return thread list.
      case 'threads':
        $response = $this->buildThreadCollection($request->query->get('uid'), $request->query->get('limit'));
        break;

      // Return single thread.
      case 'thread':
        $response = $this->buildThreadModel();
        break;

      // Return messages from a thread.
      case 'messages':
        if ($thread = $this->getThread()) {
          $response = $this->buildMessagesCollection($thread->entity->id(), $request->query->get('ts'));
        }
        break;

      // Return member list for autocomplete.
      case 'member':
        if ($thread = $this->helper->getThreadModelForUid($request->query->get('uid'))) {
          $response = $thread;
        }
        else {
          $response = $this->formatError($this->t('Invalid user'));
        }
        break;

      // Return member list for autocomplete.
      case 'members':
        $response = $this->helper->getUserList($request->query->get('name'));
        break;

      // Polling endpoint, returns unread msg count and thread ids.
      case 'poll':
        $response = $this->helper->getUnreadThreads($request->query->get('ts'));
        break;
    }

    // If not valid action, error.
    if (!in_array($action, $valid_actions)) {
      $response = $this->formatError($this->t('Invalid action'));
    }

    // Allow other modules to modify any of the json responses.
    $this->moduleHandler->alter('private_message_messenger_json_response', $response, $action, $request);

    // Return as a JSON response.
    return $this->jsonResponse($response);
  }

  /**
   * Post endpoint.
   *
   * @param string $action
   *   Endpoint action.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request obkect.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function post($action, Request $request) {
    $this->request = $request;
    $this->helper->getService()->updateLastCheckTime();

    // Get all the values.
    $values = $request->request->all();

    // Check token and access before doing anything.
    if (!$this->helper->validateToken($values['tok']) || !$this->helper->checkAccess()) {
      $response = $this->formatError($this->t('Access denied'));
      return $this->jsonResponse($response);
    }

    // Save the message and return the messages since before save.
    if ($thread = $this->helper->saveMessage($values)) {
      $response['thread'] = $this->helper->processThreadModel($this->helper->parseThread($thread));
      $response['messages'] = $this->buildMessagesCollection($thread->id(), $values['timestamp']);
    }
    else {
      $response = $this->formatError($this->t('Failed to send message'));
    }

    return $this->jsonResponse($response);
  }

  /**
   * A wrapper for a json response.
   *
   * @param array $response
   *   Response data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A new JSON response.
   */
  public function jsonResponse(array $response) {
    return new JsonResponse($response);
  }

  /**
   * Build a thread list.
   *
   * @param null|int $uid
   *   If supplied, will ensure the user is in the list (at the top).
   * @param int $limit
   *   Limit number of threads returned.
   *
   * @return array
   *   Array of thread models.
   */
  public function buildThreadCollection($uid = NULL, $limit = 30) {
    $response = [];
    $threads = $this->helper->getRecentThreadCollection($limit);
    $this->helper->getService()->updateLastCheckTime();

    // If a uid is specified, we ensure it is first in the list.
    if (!empty($uid) && $first_thread = $this->helper->getThreadModelForUid($uid)) {
      $response[] = $first_thread;
    }
    foreach ($threads as $thread) {
      $processed_thread = $this->helper->processThreadModel($thread);
      if (empty($first_thread) || (is_array($first_thread) && ($processed_thread['threadId'] != $first_thread['threadId']))) {
        $response[] = $processed_thread;
      }
    }

    return $response;
  }

  /**
   * Get a valid thread via 'id' url param.
   *
   * @return bool|object
   *   Thread object or FALSE if invalid.
   */
  public function getThread() {
    $id = $this->request->query->get('id');
    $id = str_replace('thread-', '', $id);
    return !empty($id) ? $this->helper->getThreadById($id) : FALSE;
  }

  /**
   * Build a single thread.
   *
   * @return array
   *   A single thread model.
   */
  public function buildThreadModel() {
    if ($thread = $this->getThread()) {
      return $this->helper->processThreadModel($thread);
    }
    else {
      return $this->formatError($this->t('Invalid thread or access'));
    }
  }

  /**
   * Build a message collection.
   *
   * @return array
   *   A single thread model.
   */
  public function buildMessagesCollection($thread_id, $timestamp = 0) {
    if ($thread_id) {
      $response = [];
      $this->helper->getService()->updateLastCheckTime();
      foreach ($this->helper->getThreadMessages($thread_id, $timestamp) as $message) {
        $response[] = $this->helper->processMessageModel($message);
      }
      return $response;
    }
    else {
      return $this->formatError($this->t('Invalid thread or access'));
    }
  }

  /**
   * Error formatter.
   *
   * @param string $error
   *   Translated error message.
   *
   * @return array
   *   Error array.
   */
  private function formatError($error) {
    return ['error' => $error];
  }

}
