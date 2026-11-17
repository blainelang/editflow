<?php

namespace Drupal\Tests\editflow\Unit;

use Drupal\content_lock\Hook\FormAlter;
use Drupal\Core\Form\FormState;
use Drupal\editflow\ContentLock\LayoutSaveIntegration;
use Drupal\Tests\UnitTestCase;

/**
 * Checks that callback filtering preserves the final save pipeline.
 *
 * @group editflow
 * @coversDefaultClass \Drupal\editflow\ContentLock\LayoutSaveIntegration
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
class LayoutSaveIntegrationTest extends UnitTestCase {

  /**
   * Verifies only release callback is removed.
   */
  public function testOnlyReleaseCallbackIsRemoved(): void {
    $lock_hook = (new \ReflectionClass(FormAlter::class))->newInstanceWithoutConstructor();
    $other = new \stdClass();
    $handlers = ['::submitForm', '::save', [$lock_hook, 'formSubmit'], [$other, 'formSubmit'], 'late_module_submit', 'editflow_finish_editing_submit'];
    $form = [
      'actions' => [
        'submit' => ['#submit' => $handlers],
        'editorial_continue' => ['#submit' => ['stale_callback'], '#validate' => ['required_validator']],
      ],
    ];
    LayoutSaveIntegration::configureContinue($form);
    $this->assertSame($handlers, $form['actions']['submit']['#submit']);
    $this->assertSame([
      '::submitForm',
      '::save', [
        $other,
        'formSubmit',
      ],
      'late_module_submit',
      'editflow_continue_editing_submit',
    ],
    $form['actions']['editorial_continue']['#submit']);
    $this->assertSame(['required_validator'], $form['actions']['editorial_continue']['#validate']);
    $once = $form;
    LayoutSaveIntegration::configureContinue($form);
    $this->assertSame($once, $form);
  }

  /**
   * Verifies clicked button snapshot.
   */
  public function testClickedButtonSnapshot(): void {
    $form = [
      'actions' => [
        'submit' => ['#submit' => ['::submitForm', '::save', 'late_module_submit']],
        'editorial_continue' => ['#name' => 'editorial_continue', '#submit' => ['old_submit']],
      ],
    ];
    $state = new FormState();
    $state->setTriggeringElement($form['actions']['editorial_continue']);
    LayoutSaveIntegration::configureContinue($form, $state);
    $this->assertSame($form['actions']['editorial_continue'], $state->getTriggeringElement());

    $state->setTriggeringElement(['#name' => 'submit', '#submit' => ['::save']]);
    LayoutSaveIntegration::configureContinue($form, $state);
    $this->assertSame(['#name' => 'submit', '#submit' => ['::save']], $state->getTriggeringElement());
  }

  /**
   * Verifies no lock handler.
   */
  public function testNoLockHandler(): void {
    $form = [
      'actions' => [
        'submit' => ['#submit' => ['::submitForm', '::save']],
        'editorial_continue' => [],
      ],
    ];
    LayoutSaveIntegration::configureContinue($form);
    $this->assertSame(['::submitForm', '::save', 'editflow_continue_editing_submit'], $form['actions']['editorial_continue']['#submit']);
  }

}
