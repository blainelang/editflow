<?php

namespace Drupal\Tests\editflow\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Ensures publication retains the language of the saved preview being reviewed.
 *
 * @group editflow
 */
#[Group('editflow')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
class TranslatedPublicationTest extends BrowserTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['editflow', 'language', 'content_translation'];

  /**
   * Publishes a French draft without switching to the English translation.
   */
  public function testPublicationKeepsPreviewLanguage(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->drupalCreateContentType(['type' => 'article']);
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->addEntityTypeAndBundleToWorkflow($this->createEditorialWorkflow(), 'node', 'article');
    $this->config('editflow.settings')->set('workflows', [
      ['id' => 'editorial', 'draft_state' => 'draft', 'publish_state' => 'published'],
    ])->save();
    $node = $this->drupalCreateNode([
      'type' => 'article',
      'langcode' => 'en',
      'title' => 'English live',
      'moderation_state' => 'published',
    ]);
    $french = $node->addTranslation('fr', [
      'title' => 'French live',
      'moderation_state' => 'published',
    ]);
    $french->save();
    $french->setNewRevision(TRUE);
    $french->setTitle('French saved draft');
    $french->set('moderation_state', 'draft');
    $french->save();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('fr/node/' . $node->id() . '/latest');
    $this->assertSession()->buttonExists('Publish this version');
    $this->submitForm(['confirm_publish' => TRUE], 'Publish this version');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $saved = $storage->load($node->id());
    $this->assertSame('English live', $saved->label());
    $this->assertTrue($saved->isPublished());
    $this->assertSame('French saved draft', $saved->getTranslation('fr')->label());
    $this->assertTrue($saved->getTranslation('fr')->isPublished());
  }

}
