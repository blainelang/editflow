<?php

namespace Drupal\editflow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Configuration guidance, available as a page or Drupal AJAX modal.
 */
final class ConfigurationHelpController extends ControllerBase {

  /**
   * Opens help with the current settings tab's section expanded first.
   */
  public static function link(string $section = 'workflow'): array {
    $url = Url::fromRoute('editflow.configuration_help', ['section' => $section]);
    return [
      '#type' => 'link',
      '#title' => t('Configuration help'),
      '#url' => $url,
      '#access' => $url->access(),
      '#weight' => -20,
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#attributes' => [
        'class' => ['use-ajax', 'editflow-help-link'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => json_encode([
          'width' => 760,
          'closeText' => (string) t('Close configuration help'),
          'classes' => ['ui-dialog' => 'editflow-help-dialog'],
        ]),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax', 'editflow/workflow_help']],
    ];
  }

  /**
   * Builds help with the relevant section first; other sections stay available.
   */
  public function build(string $section = 'workflow'): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['editflow-workflow-help']],
      '#attached' => ['library' => ['editflow/workflow_help']],
      'intro' => [
        '#type' => 'html_tag', '#tag' => 'p',
        '#value' => $this->t('Workflow setup controls which content uses EditFlow and how drafts are published. Interface settings controls the shared editing tools. Changes here do not rewrite existing content or revisions.'),
      ],
    ];
    $sections = [
      'workflow' => [$this->t('Workflow setup'), [
        [$this->t('Choose a workflow'), $this->t('Select an existing Content Moderation workflow. Switching loads that workflow’s saved settings; it does not save your changes. Editorial may have been supplied by Drupal’s Standard profile. Other workflows may have been added by your site. Use Manage workflows and transitions to create or change states and transitions.')],
        [$this->t('Enable enhancements'), $this->t('Enable EditFlow enhancements for each workflow you want to use. Disabling them keeps Drupal moderation, content-type assignments and existing permissions.')],
        [$this->t('Draft and Published states'), $this->t('Map the existing states used by the draft-save and publication controls. Draft must be unpublished and must not replace the default revision, so saving a draft leaves the live page unchanged. Published is the state used to make reviewed content live. Users still need permission for the relevant transitions.')],
        [$this->t('Taking a page offline'), $this->t('Draft and Published alone do not provide unpublishing. Add a separate unpublished state that becomes the default revision, with a transition from Published. Select it as the Unpublish destination and enable the Unpublish option on Interface settings. Include a transition back to Draft or Published so offline pages can be edited or republished.')],
        [$this->t('Content types'), $this->t('Choose the types to add to this workflow. Types assigned to another workflow cannot be moved here. Unchecking a type does not remove its existing assignment; use Drupal’s workflow configuration for removals.')],
        [$this->t('Protect selected content types'), $this->t('With Administer Content Lock settings permission, initial setup offers to add editing locks to selected types. Review lists the additions. Existing timeout, translation and form-operation settings stay unchanged. This option defaults off when revisiting an enabled workflow; use Configure editing locks for later changes. Unchecking or disabling EditFlow never removes lock protection.')],
        [$this->t('Optional role permissions'), $this->t('Choose existing dedicated Editor and Publisher roles only if you want to add permissions. This does not create roles or assign users. Administrator, Anonymous and Authenticated roles are excluded. Editors receive editing/revision access and draft transitions; publishers also receive publication transitions. Global unpublished-content and latest-version access are included and shown for review. Existing permissions are never removed. Creation, deletion, Layout Builder, locking and sharing permissions are configured separately.')],
        [$this->t('Review and apply'), $this->t('Review changes shows proposed assignments, state mappings, enhancement settings and permission grants. Apply configuration saves them. Back returns to your choices. If relevant configuration changes during review, review again before applying.')],
      ]],
      'interface' => [$this->t('Interface settings'), [
        [$this->t('Choose shared features'), $this->t('Feature preferences are shared across the site; workflow-specific editing controls apply to workflows enabled on Workflow setup. Save configuration stores these preferences. Each option describes what it changes.')],
        [$this->t('Revision information and drafts'), $this->t('The revision information footer appears when viewing an individual revision from Revisions, not on the edit form. Pending-draft author and save time adds saved-draft details while editing. The latest-preview option provides saved-version review controls and publication where permitted; full-page previews simplify the review display.')],
        [$this->t('Unpublish confirmation'), $this->t('Allows authorized editors to take a published page offline after confirmation, preserving saved drafts and revision history. It requires an Unpublish destination and a permitted transition. The button is hidden while editing a draft. Offline pages can be republished through Publish content… without making a new edit.')],
        [$this->t('Layout Builder and page editing tabs'), $this->t('Layout Builder enhancements improve editing controls, layout guidance and advanced options. The optional page editing bar groups links such as Edit, Preview and Revisions into a collapsible toolbar when viewing pages. Keep it disabled to use the theme’s default local tabs. Check custom themes for styling compatibility.')],
        [$this->t('Optional modules'), $this->t('Unavailable options explain whether their module is available but not enabled, or missing. Enable available modules under Extend; add missing contributed modules with Composer first. Saved preferences are retained while controls are unavailable. Checking a feature never downloads or enables a module. Diff enhances revision comparisons; Preview Link provides shared previews and EditFlow can add revocation controls.')],
        [$this->t('Media Library'), $this->t('Display preference starts in Table view and remembers each signed-in user’s Grid/Table choice, including CKEditor. Styling is a separate option; check it with your theme. Optional media table setup changes the picker to Updated descending with sortable Name, Author and Updated headings, removes Sort by, and enables display preferences. It preserves filters and access and does not enable styling. Upload permissions remain separate.')],
        [$this->t('Editing locks'), $this->t('Content Lock is required. Workflow setup can add protected content types after review. Expand Editing locks below Interface features and follow Configure editing locks to choose types, timeout and lock scope. Configure any permission to break another editor’s lock separately. Translation-specific and form-operation locks are respected, but deleting unpublished revisions is unavailable with those advanced scopes.')],
        [$this->t('Content list columns'), $this->t('Review optional content list columns adds missing Draft status and Draft updated fields to eligible Content displays, and Editing lock to eligible Moderated content displays. New columns go before Operations, or at the end if Operations is absent. Existing fields are not repositioned; filters, access and sorting remain. Repeating setup does not duplicate columns. Administer views permission is required.')],
      ]],
      'deployment' => [$this->t('Deployment and reinstalling'), [
        [$this->t('Export reviewed configuration'), $this->t('Export EditFlow settings and any changed workflows, roles and Views through your normal deployment process. Installing EditFlow does not create workflows, assign content types or grant roles permissions.')],
        [$this->t('Uninstall and reinstall'), $this->t('Uninstalling removes EditFlow fields from Views while preserving site-owned views and unrelated settings. Reinstalling resets EditFlow settings: re-enable workflow enhancements and shared features, then review optional columns again, or restore reviewed exported configuration. Back up the database before uninstalling.')],
      ]],
    ];
    $order = $section === 'interface' ? ['interface', 'workflow', 'deployment'] : ['workflow', 'interface', 'deployment'];
    foreach ($order as $key) {
      [$title, $topics] = $sections[$key];
      $build[$key] = ['#type' => 'details', '#title' => $title, '#open' => $key === $section];
      foreach ($topics as $index => [$heading, $text]) {
        $build[$key]['topic_' . $index] = [
          'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $heading],
          'text' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text],
        ];
      }
    }
    return $build;
  }

}
