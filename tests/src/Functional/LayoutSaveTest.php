<?php

namespace Drupal\Tests\editflow\Functional;

/**
 * Tests real saves and lock release with Content Lock installed.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class LayoutSaveTest extends LayoutSaveTestBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_lock'];

}
