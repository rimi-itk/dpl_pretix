<?php

/**
 * Implementation of hook_drush_command().
 */
function ding_pretix_drush_command() {
  $commands = array();
  $commands['pretix-migrate-place2book'] = array(
    'description' => 'Drush command to migrate place2book data to DDB and Pretix fields.',
    'aliases' => array('pmp'),
    'examples' => array(
      'Migrate data for all ding_event nodes' => 'drush pmp',
    ),
  );
  return $commands;
}

/**
 * Drush command callback function
 */
function drush_ding_pretix_pretix_migrate_place2book() {
  $error = false;

  if(!module_exists('ding_place2book')) {
    drupal_set_message(t('Cannot migrate! Place2book module not enabled. Migration must happen before uninstall.'), 'error');
    $error = true;
  }

  module_load_include('inc', 'ding_pretix', 'ding_pretix.api_module');
  $service_settings = variable_get('ding_pretix', []);

  foreach ($service_settings as $key => $setting) {
    if (empty($setting)) {
      drupal_set_message(t('Cannot migrate! The @key setting is under ding > pretix is not set.', ['@key' => $key]), 'error');
      $error = true;
    }
  }

  if(!$error) {
    $module_path = drupal_get_path('module', 'ding_pretix');
    $batch = array(
      'title' => t('Batching migration of place2book events'),
      'operations' => array(
        array('ding_pretix_batch_migrate_place2book_nodes', []),
      ),
      'finished' => 'ding_pretix_batch_migrate_place2book_nodes_finished',
      'file' => $module_path . '/ding_pretix.drush.inc',
      'css' => []
    );
    batch_set($batch);

    drush_backend_batch_process();
  }
}

/**
 * Batch migrate place2book nodes
 *
 * @param $context
 * @throws InvalidMergeQueryException
 */
function ding_pretix_batch_migrate_place2book_nodes(&$context) {
  module_load_include('inc', 'ding_pretix', 'ding_pretix.api_module');

  if (empty($context['sandbox'])) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['current_node'] = 0;
    $context['sandbox']['max'] = db_query('SELECT COUNT(DISTINCT nid) FROM {ding_place2book}')->fetchField();
  }
  $limit = 50;

  $percentage = sprintf('%0.2f', $context['sandbox']['progress'] / $context['sandbox']['max'] * 100);
  $context['message'] = check_plain( t('Migrating @max place2book events, @percentage% complete', ['@max' => $context['sandbox']['max'], '@percentage' => $percentage]) );

  $rows = db_select('ding_place2book', 'p2b')
    ->fields('p2b')
    ->condition('nid', $context['sandbox']['current_node'], '>')
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
    if (empty($external_ticket) && isset($og_group->nid)) {
      $url = ding_pretix_get_shop_url($node->nid, $og_group->nid);

      if (!empty($url)) {
        $wrapper->field_ding_event_ticket_link->set(['url' => $url]);
        $wrapper->save();
      }
    }

    if(array_key_exists($node->nid, $rows)) {
      $place2book_info = $rows[$node->nid];

      $nid = (int) $node->nid;

      $pretix_info = array(
        'nid' => $nid,
        'capacity' => (int) $place2book_info->capacity,
        'maintain_copy' => 0,
        'ticket_type' => $place2book_info->ticket_type,
      );

      db_merge('ding_pretix')
        ->key(['nid' => $nid])
        ->fields($pretix_info)
        ->execute();
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
 * Batch function finished handler
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
 * Get place2book ticket information.
 *
 * Base on the render function found in the place2book module.
 *
 * @param int $event_nid
 *   The events node id to find ticket for.
 * @param int $library_nid
 *   The node id of the library that has the event.
 *
 * @return array
 *   With the keys 'url' if ticket is found.
 */
function ding_pretix_get_ticketinfo($event_nid, $library_nid) {
  $place2book_id = 0;

  // Get data from ding_place2book table.
  $place2book_result = db_query('SELECT place2book_id, maintain_copy, passive FROM {ding_place2book} WHERE nid = :nid', array(
    ':nid' => $event_nid,
  ));
  foreach ($place2book_result as $place2book) {
    // Only find ticket links for non-passive events.
    if ($place2book->passive == 0) {
      $place2book_id = $place2book->place2book_id;
    }
  }

  // Check cache for the URL.
  if ($cache = cache_get($place2book_id . '_' . $library_nid, 'cache_place2book')) {
    return array(
      'url' => $cache->data,
    );
  };

  $data = array(
    'url' => '',
  );

  if ($place2book_id) {
    $service_settings = variable_get('ding_place2book', array());

    $api_key = place2book_get_api_key($library_nid);
    if ($api_key == NULL) {
      return $data;
    }

    $options = array(
      'method' => 'GET',
      'headers' => array(
        'X-PLACE2BOOK-API-TOKEN' => $api_key,
        'X-PLACE2BOOK-EVENT-ID' => $place2book_id,
      ),
    );
    $p2b_result = drupal_http_request($service_settings['service_url'] . '/available_tickets', $options);

    // We must have a sales-status header.
    if (isset($p2b_result->headers['sales-status'])) {

      // Check if the event is closed for admissions.
      if ($p2b_result->headers['sales-status'] == 'closed') {
        $data['url'] = $p2b_result->headers['location'];
      }
      // Check if sales period is in the future and ticket sale has not started
      // yet.
      elseif ($p2b_result->headers['sales-status'] == 'upcoming') {
        $data['url'] = $p2b_result->headers['location'];
      }
      else {

        /**
         * Check if we have no tickets left.
         *
         * Number from Available-Tickets header is a string and must be tested
         * as such also, Available-Tickets header can have a negative value,
         * which we also interpret as "no ticket left".
         */
        if ($p2b_result->headers['available-tickets'] == "0" || (int) $p2b_result->headers['available-tickets'] < 0) {
          $data['url'] = $p2b_result->headers['location'];
        }
        else {
          /**
           * Check to see if we should present an link for ordering a ticket.
           *
           * Number from Available-Tickets header is a string and must be
           * typecast as integer.
           * Available-Tickets header can also be the string "Ubegraenset antal"
           * (unlimited), so we check for existence of "antal" in the string.
           *
           * "Begræset antal" we would still get the same result even though
           * the meaning is much different.
           */
          if ($p2b_result->headers['sales-status'] == 'open' && ((int) $p2b_result->headers['available-tickets'] > 0 || strpos($p2b_result->headers['available-tickets'], 'antal') > 0)) {
            $data['url'] = $p2b_result->headers['location'];
          }
        }
      }
    }
  }

  // Save URL to the cache.
  cache_set($place2book_id . '_' . $library_nid, $data['url'], 'cache_place2book', CACHE_PERMANENT);

  return $data;
}