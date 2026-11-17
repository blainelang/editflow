# EditFlow

EditFlow improves Drupal's content editing experience with draft save controls,
saved-version previews, publication confirmations, editing locks, and optional
Layout Builder, Media Library, revision comparison and shared-preview tools.
It uses Drupal's Content Moderation workflows and permissions.

## Requirements

- Drupal 10.4 or later, or Drupal 11, with its supported PHP version.
- Core Node, Workflows and Content Moderation.
- Content Lock 3.x, required to protect against simultaneous editing.
- Optional: core Layout Builder and Media Library; contributed Diff and Preview
  Link for revision comparisons and shared previews.

## Installation

When available from Drupal.org, install with Composer and enable the module:

```sh
composer require drupal/editflow
drush en editflow -y
```

For a manually supplied copy, place the `editflow` directory under
`modules/contrib` in your Drupal webroot, install `drupal/content_lock:^3.0`
with Composer, then enable EditFlow under Extend or with Drush.

Enabling EditFlow enables its required modules. It does not create workflows,
assign content types, create roles or grant permissions. Drupal's Standard
profile may supply its optional Editorial workflow when Content Moderation is
first enabled.

## Workflow setup

Open **Configuration > Content authoring > EditFlow > Workflow setup**
(`/admin/config/content/editflow`). This page requires all three permissions:
Administer workflows, Administer permissions and Administer site configuration.

1. Choose an existing Content Moderation workflow. Use **Manage workflows and
   transitions** to create or change one if needed.
2. Enable EditFlow enhancements for that workflow and select its Draft and
   Published states. Switching workflows loads saved settings without saving
   changes to the previous selection.
3. Select content types to add. Types belonging to another workflow cannot be
   moved here. Unchecking a type does not remove its existing assignment.
4. With Administer Content Lock settings permission, leave **Add editing-lock
   protection for selected content types when applying** checked on initial setup to add protection.
   Review lists the types to add. Existing timeout and advanced settings stay
   unchanged. The option defaults off when revisiting an enabled workflow.
5. Optionally choose existing dedicated editor and publisher roles. Leave these
   unchanged if permissions are already configured. This does not assign users
   to roles. Administrator, Anonymous and Authenticated roles are excluded.
6. Choose **Review changes**, check assignments and permission grants, then
   **Apply configuration**. Existing permissions are never removed.

Role grants include editing/revision access for participating types, global
unpublished-content and latest-version access, and relevant transitions.
Creation, deletion, Layout Builder, locking and sharing permissions are separate.

A Draft state must be unpublished and not the default revision. Published must
be published and the default revision. Saving a draft then leaves the live page
unchanged. Workflow setup does not rewrite existing content or revisions.

### Unpublishing and republishing

To take live pages offline, create a separate unpublished state with **Default
revision** checked. Add a transition from Published and a path back to Draft or
Published. Select that state as the **Unpublish destination**, enable the
Unpublish interface option, and grant the appropriate transition permissions.

Unpublish confirmation preserves revision history and pending drafts, takes all
translations offline, and revokes shared preview access to the page. It does not
save unsaved form input. The button is hidden while editing a pending draft.
Eligible publishers can use **Publish content…** on an offline page to review and
publish its latest saved version without making an edit.

## Interface settings

Use `/admin/config/content/editflow/interface` to select shared features.
Workflow-specific editing tools apply only to enabled workflows.

- **Revision information footer** describes the individual revision being viewed,
  not the editing form. Pending-draft author and save time is a separate option.
- **Latest-preview controls** provide saved-version review and permitted
  publication. Full-page previews simplify the review display.
- **Layout Builder controls** improve layout guidance, saving and advanced state
  choices. The optional editing bar groups page tabs when viewing pages.
- **Media Library display preference** starts in Table view and remembers the
  signed-in user's Grid/Table choice, including CKEditor. Styling is independent.
- **Media table setup** optionally enables sortable Name, Author and Updated
  headings, defaults to Updated descending, removes Sort by, and enables display
  preferences. It preserves filters/access and does not enable styling.
- **Diff comparisons** add a summary, navigation, collapsible options and an
  improved text legend for full-page visual comparisons.
- **Shared preview revocation** adds a confirmation to end shared access.
  Preview Link creates a link on first opening Share preview. Save updates its
  associated content; regeneration replaces it. Reset lifetime keeps the same
  link and restarts its configured lifetime.

Unavailable features explain whether their module is missing or not enabled.
Saved preferences are retained. A checkbox does not install a dependency.
Test optional styling and page editing tabs with your site's theme.

### Editing locks

Expand **Editing locks**, then choose **Configure editing locks** to select
protected content types, timeout and lock scope. Installing Content Lock does
not automatically select protected types; Workflow setup can add them with your
reviewed approval. Unchecking the setup option or disabling EditFlow does not
remove protection. Later changes made in Content Lock are preserved.

By default, Content Lock uses one shared lock across Page Details and Layout.
Existing translation-specific or form-operation settings are respected. These
allow more independent editing, but deleting unpublished revisions requires
shared locking and is unavailable with those advanced scopes. Configure permission to break another
editor's lock separately.

Save and continue editing keeps the lock; save and finish editing releases it.
Leaving the page may retain the lock until it expires or is explicitly released.

### Content list columns

Open **Content list columns > Review optional content list columns** with
Administer views permission. Select eligible displays to add missing Draft
status/Draft updated columns to Content listings, or Editing lock to Moderated
content listings. New columns go before Operations; existing fields retain their
positions. Existing filters, access and sorting are preserved.

Status and Updated describe the default saved version. Draft columns identify
newer unpublished revisions and their save time/author when accessible.
**No pending draft** means there is no newer unpublished revision; **Unpublished**
means the page is offline. Putting Draft in a title does not change its state.

## Help and troubleshooting

Both settings tabs provide **Configuration help**. Editing pages provide
**Workflow help** for saving, previewing, publishing, locks and sharing.

- Missing enhancements: check the workflow's enablement and the feature option.
- Missing preview: a published page with no pending draft needs no latest-version
  tab. On an offline page whose default is also latest, use **View saved page**.
- Missing publication action: check state mappings, valid transitions, permissions
  and whether another editor holds a lock.
- Missing media uploads or sharing: check the relevant module's permissions.

## Configuration management and uninstalling

Export reviewed settings, workflow, role and Views changes through your normal
configuration deployment process.

Back up the database before uninstalling. EditFlow removes its Views fields and
related table settings while preserving site-owned Views and unrelated settings.
Reinstalling does not restore EditFlow settings or optional columns: configure
workflows and features again, or restore reviewed exported configuration.

## Theming

Component libraries scope styles to editorial controls and Media Library.
Override the `--editflow-ui-*` CSS variables for colors, spacing, borders and
other shared styling. Custom fixed headers or tab markup may need theme-specific
adjustments. EditFlow does not require a particular theme.

## License

GPL-2.0-or-later. See LICENSE.txt.
