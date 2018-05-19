<?php

/**
 * @file
 * Ding Pretix drush commands.
 */

/**
 * Implements hook_drush_command().
 */
function ding_pretix_drush_command() {
  $commands = [];
  $commands['pretix-migrate-place2book'] = [
    'description' => 'Drush command to migrate place2book data to DDB and Pretix fields.',
    'aliases' => ['pmp'],
    'examples' => [
      'Migrate data for all ding_event nodes' => 'drush pmp',
    ],
  ];
  return $commands;
}

/**
 * Drush command callback function.
 */
function drush_ding_pretix_pretix_migrate_place2book() {
  $error = FALSE;

  if (!module_exists('ding_place2book')) {
    drupal_set_message(t('Cannot migrate! Place2book module not enabled. Migration must happen before uninstall.'), 'error');
    $error = TRUE;
  }

  module_load_include('inc', 'ding_pretix', 'includes/ding_pretix.api_module');
  $service_settings = variable_get('ding_pretix', []);

  foreach ($service_settings as $key => $setting) {
    if (empty($setting)) {
      drupal_set_message(t('Cannot migrate! The @key setting is under ding > pretix is not set.', ['@key' => $key]), 'error');
      $error = TRUE;
    }
  }

  if (!$error) {
    $module_path = drupal_get_path('module', 'ding_pretix');
    $batch = [
      'title' => t('Batching migration of place2book events'),
      'operations' => [
        ['ding_pretix_batch_migrate_place2book_nodes', []],
      ],
      'finished' => 'ding_pretix_batch_migrate_place2book_nodes_finished',
      'file' => $module_path . '/ding_pretix.drush.inc',
      'css' => [],
    ];
    batch_set($batch);

    drush_backend_batch_process();
  }
}

/**
 * Batch migrate place2book ticket information.
 *
 * @param ArrayObject $context
 *   The context.
 *
 * @throws InvalidMergeQueryException
 */
function ding_pretix_batch_migrate_place2book_nodes(ArrayObject &$context) {
  module_load_include('inc', 'ding_pretix', 'includes/ding_pretix.api_module');

  if (empty($context['sandbox'])) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['current_node'] = 0;
    $context['sandbox']['max'] = db_query('SELECT COUNT(DISTINCT nid) FROM {ding_place2book} WHERE maintain_copy = 1')->fetchField();
  }
  $limit = 50;

  $percentage = sprintf('%0.2f', $context['sandbox']['progress'] / $context['sandbox']['max'] * 100);
  $context['message'] = check_plain(t('Migrating @max place2book events, @percentage% complete', ['@max' => $context['sandbox']['max'], '@percentage' => $percentage]));

  $rows = db_select('ding_place2book', 'p2b')
    ->fields('p2b')
    ->condition('nid', $context['sandbox']['current_node'], '>')
    ->condition('maintain_copy', 1)
    ->orderBy('nid')
    ->range(0, $limit)
    ->execute()
    ->fetchAllAssoc('nid');

  $nids = array_keys($rows);
  $nodes = entity_load('node', $nids);

  foreach ($nodes as $node) {
    $wrapper = entity_metadata_wrapper('node', $node);

    $og_group = $wrapper->og_group_ref->value();
    $og_group = $og_group ? reset($og_group) : $og_group;

    $external_ticket = $wrapper->field_ding_event_ticket_link->value();

    // Check if the event exists in pretix and if it do get the URL.
    if (empty($external_ticket) && isset($og_group->nid)) {
      $url = ding_pretix_get_shop_url($node->nid, $og_group->nid);

      if (!empty($url)) {
        $wrapper->field_ding_event_ticket_link->set(['url' => $url]);
        $wrapper->save();
      }
      else {
        if (array_key_exists($node->nid, $rows)) {
          $place2book_info = $rows[$node->nid];
          $nid = $node->nid;

          // Get URL from place2book.
          $url = ding_pretix_migrate_get_place2book_url($place2book_info->place2book_id, $og_group->nid);

          // Update the node with that link, so place2book can be disabled.
          $wrapper->field_ding_event_ticket_link->set(['url' => $url]);
          $wrapper->save();

          $pretix_info = [
            'nid' => $nid,
            'capacity' => (int) $place2book_info->capacity,
            'maintain_copy' => 0,
            'ticket_type' => $place2book_info->ticket_type,
          ];

          db_merge('ding_pretix')
            ->key(['nid' => $nid])
            ->fields($pretix_info)
            ->execute();
        }
      }
    }

    $context['results'][] = $node->nid . ' : ' . check_plain($node->title);
    $context['sandbox']['progress']++;
    $context['sandbox']['current_node'] = $node->nid;
    if ($context['sandbox']['progress'] !== $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

}

/**
 * Batch function finished handler.
 *
 * @param $success
 * @param $results
 * @param $operations
 */
function ding_pretix_batch_migrate_place2book_nodes_finished($success, $results, $operations) {
  if ($success) {
    $message = format_plural(count($results), 'Migration complete: 1 event processed.', 'Migration complete: @count event processed.');
    drupal_set_message($message);
  }
  else {
    $message = t('Migration failed. Some nodes where not migrated.');
    drupal_set_message($message, 'error');
  }
}

/**
 * Get place2book ticket URL.
 *
 * @param int $place2book_id
 *   The events node id to find ticket for.
 * @param int $library_id
 *   The node id of the library that has the event.
 *
 * @return string
 *   The ticket URL if ticket is found.
 */
function ding_pretix_migrate_get_place2book_url($place2book_id, $library_id) {
  $service_settings = variable_get('ding_place2book', array());
  $api_key = place2book_get_api_key($library_id);
  if ($api_key == NULL) {
    return '';
  }

  // Check cache for the URL.
  if ($cache = cache_get($place2book_id . '_' . $library_id, 'cache_place2book')) {
    return $cache->data;
  };

  $headers = array(
    'X-PLACE2BOOK-API-TOKEN' => $api_key,
    'X-PLACE2BOOK-EVENT-ID' => $place2book_id,
  );
  $options = array(
    'method' => 'GET',
    'headers' => $headers,
  );
  $p2b_result = drupal_http_request(
    $service_settings['service_url'] . '/available_tickets',
    $options
  );

  return $p2b_result->headers['location'];
}
