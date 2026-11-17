<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\preview_link\Entity\PreviewLink;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;

/**
 * Ensures Nex does not bypass shared-preview tokens or expiry.
 *
 * @group editflow
 */
#[\PHPUnit\Framework\Attributes\Group('editflow')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(FALSE)]
class SharedPreviewTest extends BrowserTestBase {
  use ContentModerationTestTrait;
  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'preview_link'];

  /**
   * Verifies access and expiry.
   */
  public function testAccessAndExpiry(): void {
    $this->drupalCreateContentType(['type' => 'article']);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
    [
      'id' => 'editorial',
      'draft_state' => 'draft',
      'publish_state' => 'published',
    ],
    ])->save();
    $this->config('preview_link.settings')->set('enabled_entity_types', ['node' => ['article']])->save();
    $this->container->get('router.builder')->rebuild();
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'title' => 'Private shared draft',
      'moderation_state' => 'draft',
    ]);
    $link = PreviewLink::create()->addEntity($node)->setExpiry(new \DateTimeImmutable('+1 day'));
    $link->save();
    $url = $link->getUrl($node);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(403);
    $bad = clone $url;
    $bad->setRouteParameter('preview_token', PreviewLink::create()->getToken());
    $this->drupalGet($bad);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Private shared draft');
    $this->assertSession()->buttonNotExists('Publish this version');
    $node->setNewRevision(TRUE);
    $node->setTitle('Newer private shared draft');
    $node->save();
    $this->drupalGet($url);
    $this->assertSession()->pageTextContains('Newer private shared draft');
    $link->setExpiry(new \DateTimeImmutable('-1 day'))->save();
    // Reset the browser session so an earlier valid token cannot grant access.
    $this->getSession()->reset();
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Newer private shared draft');
  }

}
