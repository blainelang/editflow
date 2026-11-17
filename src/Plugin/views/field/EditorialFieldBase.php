<?php

namespace Drupal\editflow\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides injected services for editorial Views fields.
 *
 * @phpstan-consistent-constructor
 */
abstract class EditorialFieldBase extends FieldPluginBase {

  /**
   * Moderation information.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation;

  /**
   * Entity storage manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * User-aware date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Optional locking integration.
   *
   * @var \Drupal\content_lock\ContentLock\ContentLock|null
   */
  protected $lockService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->moderationInformation = $container->get('content_moderation.moderation_information');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->lockService = $container->has('content_lock') ? $container->get('content_lock') : NULL;
    return $instance;
  }

}
