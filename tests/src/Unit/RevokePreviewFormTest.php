<?php

namespace Drupal\Tests\editflow\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\preview_link\Entity\PreviewLinkInterface;
use Drupal\editflow\Form\RevokePreviewForm;
use Drupal\Tests\UnitTestCase;

/**
 * Verifies revocation deletes only the preview link and never recreates it.
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
class RevokePreviewFormTest extends UnitTestCase {

  public function testRevokeDeletesLinkAndLeavesShareForm(): void {
    $preview = $this->createMock(PreviewLinkInterface::class);
    $preview->expects($this->once())->method('delete');
    $preview->expects($this->never())->method('save');
    $preview->expects($this->never())->method('regenerateToken');
    $form_object = new RevokePreviewForm();
    $form_object->setStringTranslation($this->getStringTranslationStub());
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus');
    $form_object->setMessenger($messenger);
    $property = new \ReflectionProperty($form_object, 'previewLink');
    $property->setValue($form_object, $preview);
    $form = [];
    $state = new FormState();
    $form_object->submitForm($form, $state);
    $this->assertTrue($state->getIgnoreDestination());
    $this->assertSame('system.admin_content', $state->getRedirect()->getRouteName());
    $this->assertSame('system.admin_content', $form_object->getCancelUrl()->getRouteName());
  }

}
