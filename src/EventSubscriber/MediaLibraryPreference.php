<?php

namespace Drupal\editflow\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\user\UserDataInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Remembers the media picker display for each authenticated editor.
 */
final class MediaLibraryPreference implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly UserDataInterface $userData,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::CONTROLLER => 'onController'];
  }

  public function onController(ControllerEvent $event): void {
    if (!\Drupal::config('editflow.settings')->get('media_library')) { return; }
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    $displays = [
      'view.media_library.widget' => 'widget',
      'view.media_library.widget_table' => 'widget_table',
    ];
    if ($route !== 'media_library.ui' && !isset($displays[$route])) {
      return;
    }
    // Run after route access checks and validate the signed opener state.
    MediaLibraryState::fromRequest($request);
    $uid = $this->account->id();
    $saved = $this->account->isAuthenticated()
      ? $this->userData->get('editflow', $uid, 'media_library_display') : NULL;
    // The route identifies an explicit Grid/Table click; its query may still
    // contain the previous display. Media type links omit the display entirely.
    $display = $displays[$route] ?? $saved ?? 'widget_table';
    if (!in_array($display, ['widget', 'widget_table'], TRUE)) {
      $display = 'widget_table';
    }
    $request->query->set('views_display_id', $display);
    if (isset($displays[$route]) && $this->account->isAuthenticated() && $saved !== $display) {
      $this->userData->set('editflow', $uid, 'media_library_display', $display);
    }
  }

}
