<?php

namespace Drupal\private_message_messenger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\private_message_messenger\MessengerHelper;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Extension\ModuleHandler;

/**
 * Class Messenger.
 *
 * @package Drupal\private_message_messenger\JsonEndpoint
 */
class Messenger extends ControllerBase {

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
   * Content for the page.
   */
  public function content() {
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
