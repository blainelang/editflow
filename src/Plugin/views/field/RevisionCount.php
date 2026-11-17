<?php

namespace Drupal\editflow\Plugin\views\field;

use Drupal\views\Plugin\views\field\NumericField;

/**
 * Displays the number of revisions for a node.
 *
 * @ViewsField("editflow_revision_count")
 */
class RevisionCount extends NumericField {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $node_id = $this->tableAlias . '.nid';
    $this->field_alias = $this->query->addField(
      NULL,
      "(SELECT COUNT(*) FROM {node_revision} editflow_node_revision WHERE editflow_node_revision.nid = {$node_id})",
      'editflow_revision_count'
    );
  }

}
