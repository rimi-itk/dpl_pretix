<?php

function _ding_pretix_create_event($node, $service_settings)
{
  $headers = _ding_pretix_built_request_headers($service_settings);
  $body = _ding_pretix_built_request_body($node, 'INSERT');

  $clone_options = [
    'method' => 'POST',
    'headers' => $headers,
    'data' => json_encode($body)
  ];
  $clone_url = _ding_pretix_get_event_endpoint($service_settings) . $service_settings['template_event_slug'] . '/clone/';

  // Create Event via clone
  $result = _ding_pretix_http_request($clone_url, $clone_options);

  return $result;
}

function _ding_pretix_update_event($node, $service_settings, $pretix_slug)
{
  $headers = _ding_pretix_built_request_headers($service_settings);
  $body = _ding_pretix_built_request_body($node, 'UPDATE');

  $options = array(
    'method' => 'PATCH',
    'headers' => $headers,
    'data' => json_encode($body)
  );
  $url = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug);
  $result = _ding_pretix_http_request($url, $options);

  return $result;
}

function _ding_pretix_delete_event($service_settings, $pretix_slug)
{
  $headers = _ding_pretix_built_request_headers($service_settings);

  $options = array(
    'method' => 'PATCH',
    'headers' => $headers
  );
  $url = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug);
  $result = _ding_pretix_http_request($url, $options);

  return $result;
}

function _ding_pretix_update_live($is_live, $service_settings, $pretix_slug)
{
  $live_url = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug);
  $live_options = [
    'method' => 'PATCH',
    'headers' => _ding_pretix_built_request_headers($service_settings),
    'data' => json_encode(['live' => $is_live]),
  ];
  $result = _ding_pretix_http_request($live_url, $live_options);

  return $result;
}

function _ding_pretix_update_quota_size($quota, $service_settings, $pretix_slug, $pretix_capacity)
{
  if ($quota && $pretix_capacity !== $quota->size) {
    $options = [
      'method' => 'PATCH',
      'headers' => _ding_pretix_built_request_headers($service_settings),
      'data' => json_encode(['size' => (int) $pretix_capacity])
    ];
    $quota_endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug) . 'quotas/' . $quota->id . '/';
    $result = _ding_pretix_http_request($quota_endpoint, $options);
  }

  return $result ?? null;
}

function _ding_pretix_get_quota($item, $service_settings, $pretix_slug)
{
  $options = [
    'method' => 'GET',
    'headers' => _ding_pretix_built_request_headers($service_settings)
  ];
  $quota_endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug) . 'quotas/';
  $result = _ding_pretix_http_request($quota_endpoint, $options);

  if (200 === (int)$result->code) {
    $data = json_decode($result->data);
    foreach ($data->results as $result) {
      if (in_array($item->id, $result->items)) {
        $quota = $result;
      }
    }
  }

  return $quota ?? null;
}

function _ding_pretix_get_item($service_settings, $pretix_slug)
{
  $options = [
    'method' => 'GET',
    'headers' => _ding_pretix_built_request_headers($service_settings)
  ];
  $item_endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug) . 'items/';
  $result = _ding_pretix_http_request($item_endpoint, $options);

  if (200 === (int)$result->code) {
    $data = json_decode($result->data);
    foreach ($data->results as $result) {
      if ($result->admission) {
        $item = $result;
        break;
      }
    }
  }

  return $item ?? null;
}

function _ding_pretix_update_item($node, $item, $service_settings, $pretix_slug)
{
  $wrapper = entity_metadata_wrapper('node', $node);
  $price = $wrapper->field_ding_event_price->value();

  if ($item) {
    $options = [
      'method' => 'PATCH',
      'headers' => _ding_pretix_built_request_headers($service_settings),
      'data' => json_encode(['default_price' => (int)$price, 'name' => ['da' => $node->pretix['ticket_type']]])
    ];
    $endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_slug) . 'items/' . $item->id . '/';
    $result = _ding_pretix_http_request($endpoint, $options);
  }

  return $result ?? null;
}

function _ding_pretix_get_event_endpoint($service_settings, $event_slug = null)
{
  $endpoint = $service_settings['service_url'] . 'api/v1/organizers/' . $service_settings['organizer_slug'] . '/events/';

  if($event_slug) {
    $endpoint .= $event_slug . '/';
  }

  return $endpoint;
}

function _ding_pretix_get_event_admin_url($service_settings, $event_slug)
{
  $endpoint = $service_settings['service_url'] . 'control/event/' . $service_settings['organizer_slug'] . '/' . $event_slug . '/';

  return $endpoint;
}

/**
 * @param $service_settings
 *
 * @return array
 */
function _ding_pretix_built_request_headers($service_settings)
{
  $headers = array(
    'Accept' => 'application/json, text/javascript',
    'Authorization' => 'Token ' . $service_settings['api_key'],
    'Content-Type' => 'application/json'
  );

  return $headers;
}

/**
 * @param $node
 * @param $request_type
 *
 * @return array
 * @throws Exception
 */
function _ding_pretix_built_request_body($node, $request_type = 'UPDATE')
{
  $wrapper = entity_metadata_wrapper('node', $node);

  $start_date = date_create('@' . $wrapper->field_ding_event_date->value->value());
  $end_date = date_create('@' . $wrapper->field_ding_event_date->value2->value());

  $field_address = _ding_pretix_get_place($node);
  $place = _ding_pretix_get_place_string($field_address);

  // Events cannot be created as 'live' in Pretix.
  $live = ($request_type === 'INSERT') ? false : $node->status;

  $body = [
    'name' => ['da' => check_plain($node->title)],
    'slug' => $node->nid,
    'live' => $live,
    'currency' => 'DKK',
    'date_from' => date_format($start_date, 'c'),
    'date_to' => date_format($end_date, 'c'),
    'is_public' => $node->status,
    'presale_start' => date_format(date_sub(clone $start_date, new DateInterval('P30D')), 'c'),
    'presale_end' => date_format($start_date, 'c'),
    'location' => ['da' => $place],
    'has_subevents' => false
  ];

  $ding_pretix_psp_elements = variable_get('ding_pretix_psp_elements', array());
  if ($request_type === 'INSERT' && array_key_exists('pretix_psp_meta_key', $ding_pretix_psp_elements) && !empty($ding_pretix_psp_elements['pretix_psp_meta_key'])) {
    $key = $ding_pretix_psp_elements['pretix_psp_meta_key'];
    $body['meta_data'] = [$key => $node->pretix['psp_element']];
  }

  return $body;
}

function _ding_pretix_get_place($node)
{
  $field_address = field_get_items('node', $node, 'field_ding_event_location');

  // If the events has a thoroughfare (street) value we use the place set on the event. Else we load the location of
  // the first selected library.
  if(empty($field_address[0]['thoroughfare'])) {
    $library_nid = _ding_pretix_get_library_nid($node);

    $query = new EntityFieldQuery();
    $query->entityCondition('entity_type', 'node')
      ->entityCondition('entity_id', $library_nid, '=')
      ->propertyCondition('status', NODE_PUBLISHED);
    $result = $query->execute();
    $libraries = $result['node'] ?? null;

    if ($libraries) {
      // Get id of location field
      $fields = field_info_instances('node', 'ding_library');
      $field_id = $fields['field_ding_library_addresse']['field_id'];

      // Attach a field of selected id only to get value for it
      field_attach_load('node', $libraries, FIELD_LOAD_CURRENT, array('field_id' => $field_id));
      $library = $libraries[$library_nid];

      // Get the field
      $field_address = field_get_items('node', $library, 'field_ding_library_addresse');
    }

  }

  return $field_address;
}

function _ding_pretix_get_place_string($field_address) {
  $place = '';
  $place = empty($field_address[0]['name_line']) ? $place : $place . $field_address[0]['name_line'] . "\n";
  $place = empty($field_address[0]['thoroughfare']) ? $place : $place . $field_address[0]['thoroughfare'] . "\n";
  $place = empty($field_address[0]['premise']) ? $place : $place . $field_address[0]['premise'] . "\n";
  $place = empty($field_address[0]['postal_code']) ? $place : $place . $field_address[0]['postal_code'] . "\n";
  $place = empty($field_address[0]['locality']) ? $place : $place . $field_address[0]['locality'] . "\n";
  $place = empty($field_address[0]['country']) ? $place : $place . $field_address[0]['country'] . "\n";

  return $place;
}

/**
 * Gets the library node id from event entity.
 *
 * @param $entity
 *   The entity to find library node id for.
 *
 * @return null|int
 *   If the no library is selected on the entity NULL is returned else the
 *   entity id (nid) for the first library found.
 */
function _ding_pretix_get_library_nid($entity)
{
  // Get the library id for the event using entity metadata wrappers
  $node_wrapper = entity_metadata_wrapper('node', $entity);

  $libraries = $node_wrapper->og_group_ref->value();
  $library_nid = empty($libraries) ? null : $libraries[0]->nid;

  return $library_nid;
}

/**
 * Private function to save node specific Pretix data
 *
 * @param $node
 * @param $organizer
 * @param $data
 *
 * @return array
 * @throws Exception
 */
function _ding_pretix_insert_pretix_node_info($node, $organizer, $data = null)
{
  $slug = $data ? json_decode($data)->slug : null;

  $pretix_info = array(
    'nid' => $node->nid,
    'pretix_organizer' => $organizer,
    'pretix_slug' => $slug,
    'capacity' => (int)$node->pretix['capacity'],
    'maintain_copy' => $node->pretix['maintain_copy'],
    'ticket_type' => $node->pretix['ticket_type'],
    'psp_element' => $node->pretix['psp_element']
  );

  db_insert('ding_pretix')->fields($pretix_info)->execute();

  return $pretix_info;
}

function _ding_pretix_update_pretix_node_info($node)
{
  $pretix_info = array(
    'capacity' => (int)$node->pretix['capacity'],
    'maintain_copy' => $node->pretix['maintain_copy'],
    'ticket_type' => $node->pretix['ticket_type'],
    'psp_element' => $node->pretix['psp_element']
  );

  db_update('ding_pretix')
    ->fields($pretix_info)
    ->condition('nid', $node->nid, '=')
    ->execute();

  return _ding_pretix_get_pretix_node_info($node->nid);
}

function _ding_pretix_get_pretix_node_info($nid)
{
  $pretix_info = db_select('ding_pretix', 'p')
    ->fields('p')
    ->condition('nid', $nid, '=')
    ->execute()
    ->fetchAssoc();

  return $pretix_info;
}

function _ding_pretix_delete_pretix_node_info($nid)
{
  $pretix_info = db_delete('ding_pretix')
    ->condition('nid', $nid, '=')
    ->execute();

  return $pretix_info;
}

function _ding_pretix_get_pretix_node_info_defaults($nid)
{
  $settings = variable_get('ding_pretix_event_nodes', array());

  // If this is a new node we use the defaults. If it's an existing node we don't automatically add it to Pretix.
  $maintain_copy = ($nid === null) ? $settings['defaults']['maintain_copy'] : false;

  $pretix_defaults = [
    'capacity' => (int)$settings['capacity'],
    'ticket_type' => $settings['ticket_type'],
    'maintain_copy' => $maintain_copy,
    'passive' => $settings['defaults']['passive'],
  ];

  return $pretix_defaults;
}

function _ding_pretix_http_request($item_endpoint, $options)
{
  $result = drupal_http_request($item_endpoint, $options);

  if(isset($result->error))
  {
    watchdog('ding_pretix',
      'Pretix API: @error: @data (Request: @request, URL: @url)',
      ['@error' => $result->error, '@data' => $result->data, '@request' => $result->request, '@url' => $item_endpoint],
      $severity = WATCHDOG_ERROR);
  }

  return $result;
}
