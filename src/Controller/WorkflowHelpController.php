<?php

namespace Drupal\editflow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Read-only editorial guidance, available as a page or Drupal AJAX modal.
 */
class WorkflowHelpController extends ControllerBase {

  /**
   * Handles link.
   */
  public static function link(): array {
    $url = Url::fromRoute('editflow.workflow_help');
    return [
      '#type' => 'link',
      '#title' => t('Workflow help'),
      '#url' => $url,
      '#access' => $url->access(),
      '#attributes' => [
        'class' => ['use-ajax', 'editflow-help-link'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => json_encode([
          'width' => 760,
          'closeText' => (string) t('Close workflow help'),
          'classes' => [
            'ui-dialog' => 'editflow-help-dialog',
          ],
        ]),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax', 'editflow/workflow_help']],
    ];
  }

  /**
   * Builds the permission-aware workflow help page.
   */
  public function build(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['editflow-workflow-help']],
      '#attached' => ['library' => ['editflow/workflow_help']],
    ];
    // Keep editor guidance in reading order.
    $sections = [
      [
        $this->t('Start here'), [
          $this->t('Choose Edit Page Details to change fields such as the title and date. Choose Edit Layout to change sections and blocks.'),
          $this->t('View live page shows the published page. If a newer draft exists, Preview - Latest Version shows that saved draft. Opening an editor does not save or publish anything.'),
          $this->t('Buttons and state names may vary with your site’s setup and your permissions.'),
        ], [
          $this->t('Make your changes and save them as a draft.'),
          $this->t('Open the Preview - Latest Version tab and review the saved page. The Preview saved version link in the editing footer opens this same page in a new tab.'),
          $this->t('If someone else will publish, release your editing lock and let them know the draft is ready. Unlocking does not send a notification.'),
          $this->t('On the Preview - Latest Version page, a user with publishing permission confirms publication and chooses Publish this version in the footer. Check the result with View live page.'),
        ],
      ], [
        $this->t('Save your changes'), [
          $this->t('A draft is saved work that is not yet published. Each save creates a revision in the page’s history. The Save draft buttons do not publish your changes. After saving, open Preview - Latest Version to review and publish the saved draft.'),
          $this->t('Edit Page Details and Edit Layout share the same save controls. If the form reports an error, correct it and save again; failed validation does not release your lock.'),
        ], [
          $this->t('Save draft and continue editing saves your work and keeps your lock on either editing screen.'),
          $this->t('Save draft and finish editing saves your work, releases your lock, and opens Preview - Latest Version when you have access. Review the saved draft there and publish it if you have permission.'),
          $this->t('Publish this version is available in the saved-version preview footer. It is not a button on Edit Page Details or Edit Layout. Use the Preview saved version link to reach that page. When state choices are available, Edit Layout places Change to under Advanced editing options; Edit Page Details uses Workflow actions. Selecting a state changes the save-button labels. Check those labels before saving.'),
          $this->t('When a page is offline, eligible publishers can choose Publish content… in the right-hand publication status panel. This opens a review of the latest saved version with a confirmation step; no content edit is needed. Unsaved form changes are not included.'),
          $this->t('Unpublish content… appears in the publication status panel when editing a published revision, if your workflow and permissions allow it. It is hidden when editing a draft. Confirmation takes the live page offline without saving unsaved form changes. Saved drafts and revision history remain available; shared preview access to this page is revoked.'),
        ],
      ], [
        $this->t('Discard unpublished changes'), [
          $this->t('On either editing screen, open Advanced editing options and choose Delete unpublished revisions. This opens the revision selection and confirmation page.'),
          $this->t('Review the revision list and lock owner carefully. Select individual revisions, or use the header checkbox to select all, then confirm deletion. Selecting only some revisions preserves unchecked drafts, pending layout changes, and the lock. Selecting every revision also clears pending layout changes and releases the lock. You return to the published page; published revisions remain unchanged.'),
          $this->t('This action requires permission to view and delete every listed revision and to release the editing lock. It is available only for published, single-language content with saved drafts and standard lock settings. It does not replace Discard unsaved changes, which abandons unsaved edits without deleting saved drafts.'),
          $this->t('Unsaved browser edits are not saved. Close other edit tabs before confirming. If revisions, the lock, or the pending layout change while you review, reopen confirmation and check the new list.'),
        ], [],
      ], [
        $this->t('Preview saved work'), [
          $this->t('The Preview - Latest Version tab and the Preview saved version footer link both open the saved-version preview page. Save first, then refresh any preview you already have open.'),
          $this->t('The Preview button under Advanced editing options on Edit Page Details shows unsaved form changes and does not offer Publish this version. Return to the form, save your draft, and then open Preview - Latest Version to publish.'),
        ], [],
      ], [
        $this->t('If the latest-preview link is missing'), [
          $this->t('The link is usually absent when the published page has no newer draft. It appears after you save a draft and disappears after that draft is published. Refresh the page if the menu has not updated.'),
          $this->t('If you know a draft exists but cannot see the link, ask an administrator to check your access. When a page is unpublished, View saved page opens its saved version. If that is also the latest revision, there is no separate Preview - Latest Version tab and no View live page link. This also applies after taking a formerly published page offline.'),
        ], [],
      ], [
        $this->t('Publish a reviewed draft'), [
          $this->t('To publish, save your draft and open the Preview - Latest Version page. In that page’s footer, select the publication confirmation checkbox and choose Publish this version. The publication button requires permission to publish the saved draft. Publishing makes that saved version public; it does not save changes still open in another tab.'),
          $this->t('If another editor holds the lock, ask them to finish and release it. If a newer revision has been saved, reload the preview and review it before publishing.'),
          $this->t('If the Preview - Latest Version footer says “A publisher must make this draft live”, ask your publisher or administrator to open that page, review the saved draft, and publish it.'),
        ], [],
      ], [
        $this->t('Check and release an editing lock'), [
          $this->t('A lock reserves the item for one editor on content types configured for locking. On either editing screen, open Details beside the lock owner in the footer to see the lock age and timeout. Close the popup with Close or Escape. Lock information appears only when a lock is active.'),
          $this->t('You can release your own lock. To release someone else’s lock, you need the Break content lock permission. Contact that editor before taking over.'),
        ], [
          $this->t('Open the footer’s lock Details, choose Unlock, then confirm.'),
          $this->t('In the latest-preview footer, choose Release editing lock and confirm. Layout also provides Unlock.'),
          $this->t('Save first if you want to keep your changes. Unlocking does not save or publish them.'),
          $this->t('Break the lock appears in the warning only when you have permission to release another editor’s lock.'),
        ],
      ], [
        $this->t('When you leave the editor'), [
          $this->t('If you change Page Details and leave without saving, your browser asks you to confirm. Stay on the page to save your work, or leave to abandon the changes. Save and the form’s Preview button continue normally. This warning does not save your work; some browsers may suppress it.'),
          $this->t('Browser Back, navigating away and closing a tab can leave the lock in place. Use a save-and-finish action or Unlock when you are done.'),
          $this->t('The lock details show the current timeout. This runs from when the lock was last created or refreshed, not from when you leave. Lock age is not a measure of active editing time. If automatic expiry is disabled, someone must release the lock.'),
          $this->t('After the lock is released or expires, another editor can reopen or refresh Edit. Unlocking from the editing footer returns you to the content view.'),
          $this->t('Edit Page Details and Edit Layout share a lock when separate locks for each editor are disabled in Content Lock. In that setup, releasing either lock releases access to both editors.'),
        ], [],
      ], [
        $this->t('Share a draft for review'), [
          $this->t('Save your draft and open Share preview. Preview Link automatically creates a link the first time this screen is opened; Save is not needed to create it. Copy the link for your reviewer, who can use a valid link without signing in. Save updates which content the link gives access to.'),
          $this->t('The link shows the latest saved version, so later saves can change what the reviewer sees. Check its expiry and share it only with intended reviewers. Sharing does not publish the page or release your lock. Publication controls are available on signed-in review pages, not on the shared preview.'),
        ], [
          $this->t('Save and regenerate preview link replaces the existing link. Previous links and access granted through them stop working; send reviewers the replacement link.'),
          $this->t('Reset lifetime keeps the same link and restarts its configured lifetime from now. For a one-week lifetime, it expires one week from now.'),
          $this->t('Revoke shared preview access opens a confirmation to end access without creating a replacement. These controls require the corresponding permissions and enabled features.'),
          $this->t('A generated link does not mean it has been sent to anyone. Shared preview access can persist in the reviewer’s browser session while they navigate; it is not limited to keeping the token URL open.'),
        ],
      ], [
        $this->t('Read revision history'), [
          $this->t('Published beside an older revision describes that revision’s saved state. It does not mean every revision marked Published is currently live. The current published version is the one visitors see.'),
          $this->t('Open a revision’s date/time link to inspect that exact version. When enabled, the revision footer identifies a live, historical or unpublished revision, with its saved author and date and a link to the live page when one exists. This footer is separate from the editing footer.'),
          $this->t('With Diff enabled, select two revisions to compare. EditFlow can add a comparison summary, collapsible options, navigation links and an improved text legend for full-page visual comparisons. Comparing does not change content.'),
        ], [],
      ], [
        $this->t('Understand the content listing'), [
          $this->t('Status and Updated describe the default saved version. Draft status identifies newer unpublished changes separately. Draft updated shows the pending draft’s save time and author when you have access.'),
          $this->t('No pending draft means there is no newer unpublished revision. Unpublished means the page itself is offline. A dash can mean there is no applicable draft information or you do not have access to it. Putting Draft in a title does not make a revision a draft; use Save draft.'),
        ], [],
      ], [
        $this->t('Choose media'), [
          $this->t('When display preferences are enabled, the Media Library starts in Table view and remembers your Grid/Table choice across media types and future openings, including CKEditor. Styling is a separate option.'),
          $this->t('When the administrator applies the media table setup, Name, Author and Updated headings are sortable. Updated starts with the most recently updated media first, and the separate Sort by filter is removed. Apply filters searches by name.'),
          $this->t('Upload and media-creation controls still depend on your permissions. EditFlow does not grant permission to upload media.'),
        ], [],
      ], [
        $this->t('Discard changes and check history'), [], [
          $this->t('Discard unsaved changes abandons unsaved edits on the current editing screen. Saved revisions and the published page remain.'),
          $this->t('Revisions shows saved history. Comparisons let you review what changed without editing it.'),
          $this->t('Reset layout to template replaces the custom layout with its defaults. Use Discard unsaved changes to abandon unfinished layout edits.'),
          $this->t('Delete removes the content item.'),
          $this->t('Show block content while editing changes only the editing display.'),
        ],
      ],
    ];
    foreach ($sections as $index => [$title, $paragraphs, $items]) {
      $build['section_' . $index] = [
        '#type' => 'container',
        'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $title],
      ];
      foreach ($paragraphs as $key => $text) {
        $build['section_' . $index]['paragraph_' . $key] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $text,
        ];
      }
      if ($items) {
        $build['section_' . $index]['steps'] = [
          '#theme' => 'item_list',
          '#list_type' => $index === 0 ? 'ol' : 'ul',
          '#items' => $items,
        ];
      }
    }
    return $build;
  }

}
