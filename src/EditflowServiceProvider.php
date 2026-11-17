<?php

namespace Drupal\editflow;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\editflow\ContentLock\EditorialContentLock;

/**
 * Integrates with Content Lock when it is installed.
 *
 * Content Lock currently provides no dedicated hook to alter its lock-owner
 * messages. Replacing the service with a subclass lets us shorten acquisition
 * notices and append the configured timeout to owner warnings, while inheriting all
 * locking behavior and leaving contributed code untouched. If Content Lock
 * adds a message-alter hook, use that instead and remove this service override.
 */
class EditflowServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('content_lock')) {
      $definition = $container->getDefinition('content_lock');
      // Do not replace another module's customized lock implementation.
      if ($definition->getClass() === 'Drupal\content_lock\ContentLock\ContentLock') {
        $definition->setClass(EditorialContentLock::class);
      }
    }
  }

}
