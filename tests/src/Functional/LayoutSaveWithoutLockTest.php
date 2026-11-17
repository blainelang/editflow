<?php

namespace Drupal\Tests\editflow\Functional;

/**
 * Verifies saving with required dependencies but without Diff or Preview Link.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class LayoutSaveWithoutLockTest extends LayoutSaveTestBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'layout_builder', 'editflow_test'];

}
