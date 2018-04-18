<?php

function _ding_pretix_update_live($is_live, $service_settings, $pretix_info ) {
	$live_url     = _ding_pretix_get_event_endpoint( $service_settings, $pretix_info['pretix_slug'] );
	$live_options = [
		'method'  => 'PATCH',
		'headers' => _ding_pretix_built_request_headers( $service_settings ),
		'data'    => json_encode( [ 'live' => $is_live ] ),
	];
	$result       = drupal_http_request( $live_url, $live_options );

	return $result;
}

function _ding_pretix_update_quota_size($quota, $service_settings, $pretix_info) {
	if($quota && $pretix_info['capacity'] !== $quota->size) {
		$options        = [
			'method'  => 'PATCH',
			'headers' => _ding_pretix_built_request_headers( $service_settings ),
			'data'    => json_encode( [ 'size' => (int) $pretix_info['capacity'] ] )
		];
		$quota_endpoint = _ding_pretix_get_event_endpoint( $service_settings, $pretix_info['pretix_slug'] ) . 'quotas/' . $quota->id . '/';
		$result         = drupal_http_request( $quota_endpoint, $options );
	}

	return $result ?? null;
}

function _ding_pretix_get_quota($item, $service_settings, $pretix_info) {
	$options = [
		'method' => 'GET',
		'headers' => _ding_pretix_built_request_headers($service_settings)
	];
	$quota_endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_info['pretix_slug']) . 'quotas/';
	$result  = drupal_http_request( $quota_endpoint, $options );

	if(200 === (int) $result->code) {
		$data = json_decode($result->data);
    foreach ($data->results as $result) {
      if(in_array($item->id, $result->items)) {
        $quota = $result;
      }
    }
	}

	return $quota ?? null;
}

function _ding_pretix_get_item($service_settings, $pretix_info) {
  $options = [
    'method' => 'GET',
    'headers' => _ding_pretix_built_request_headers($service_settings)
  ];
  $item_endpoint = _ding_pretix_get_event_endpoint($service_settings, $pretix_info['pretix_slug']) . 'items/';
  $result  = drupal_http_request( $item_endpoint, $options );

  if(200 === (int) $result->code) {
    $data = json_decode($result->data);
    foreach ($data->results as $result) {
      if($result->admission) {
        $item = $result;
      }
    }
  }

  return $item ?? null;
}

function _ding_pretix_update_item_price($node, $item, $service_settings, $pretix_info) {
  $wrapper = entity_metadata_wrapper('node', $node);
  $price = $wrapper->field_ding_event_price->value();

  if($item && $price !== $item->default_price) {
    $options        = [
      'method'  => 'PATCH',
      'headers' => _ding_pretix_built_request_headers( $service_settings ),
      'data'    => json_encode( [ 'default_price' => (int) $price ] )
    ];
    $endpoint = _ding_pretix_get_event_endpoint( $service_settings, $pretix_info['pretix_slug'] ) . 'items/' . $item->id . '/';
    $result         = drupal_http_request( $endpoint, $options );
  }

  return $result ?? null;
}

function _ding_pretix_get_event_endpoint($service_settings, $event_slug = null) {
	$event_slug = $event_slug ?? $service_settings['template_event_slug'];
	$endpoint = $service_settings['service_url'] . 'api/v1/organizers/' . $service_settings['organizer_slug'] . '/events/' . $event_slug . '/';

	return $endpoint;
}

function _ding_pretix_get_event_admin_url($service_settings, $event_slug) {
	$endpoint = $service_settings['service_url'] . 'control/event/' . $service_settings['organizer_slug'] . '/' . $event_slug . '/';

	return $endpoint;
}

/**
 * @param $service_settings
 *
 * @return array
 */
function _ding_pretix_built_request_headers($service_settings) {
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
function _ding_pretix_built_request_body($node, $request_type = 'UPDATE') {
	$wrapper = entity_metadata_wrapper('node', $node);

	$start_date = date_create('@'.$wrapper->field_ding_event_date->value->value());
	$end_date = date_create('@'.$wrapper->field_ding_event_date->value2->value());

	$field_address = field_get_items('node', $node, 'field_ding_event_location');
	$place = '';
	$place = empty($field_address[0]['name_line']) ? $place : $place . $field_address[0]['name_line'] . "\n";
	$place = empty($field_address[0]['thoroughfare']) ? $place : $place . $field_address[0]['thoroughfare'] . "\n";
	$place = empty($field_address[0]['premise']) ? $place : $place . $field_address[0]['premise'] . "\n";
	$place = empty($field_address[0]['postal_code']) ? $place : $place . $field_address[0]['postal_code'] . "\n";
	$place = empty($field_address[0]['locality']) ? $place : $place . $field_address[0]['locality'] . "\n";
	$place = empty($field_address[0]['country']) ? $place : $place . $field_address[0]['country'] . "\n";

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
		'presale_end' => date_format($start_date,  'c'),
		'location' => ['da' => $place],
		'has_subevents' => false
	];

	return $body;
}

/**
 * Private function to save data on the Pretix event
 *
 * @param $node
 * @param $organizer
 * @param $data
 *
 * @return array
 * @throws Exception
 */
function _ding_pretix_insert_pretix_node_info($node, $organizer, $data = null) {
	$slug = $data ? json_decode($data)->slug : null;

	$pretix_info = array(
		'nid' => $node->nid,
		'pretix_organizer' => $organizer,
		'pretix_slug' => $slug,
		'capacity' => (int) $node->pretix['capacity'],
		'maintain_copy' => $node->pretix['maintain_copy'],
		'ticket_type' => $node->pretix['ticket_type']
	);

	db_insert('ding_pretix')->fields($pretix_info)->execute();

	return $pretix_info;
}

function _ding_pretix_update_pretix_node_info($node) {
	$pretix_info = array(
		'nid' => $node->nid,
		'capacity' => (int) $node->pretix['capacity'],
		'maintain_copy' => $node->pretix['maintain_copy'],
		'ticket_type' => $node->pretix['ticket_type']
	);

	db_update('ding_pretix')
		->fields($pretix_info)
		->condition('nid', $node->nid, '=')
		->execute();
}

function _ding_pretix_get_pretix_node_info($nid) {
	$pretix_info = db_select('ding_pretix', 'p')
		->fields('p')
		->condition('nid', $nid,'=')
		->execute()
		->fetchAssoc();

	return $pretix_info;
}

function _ding_pretix_delete_pretix_node_info($nid) {
	$pretix_info = db_delete('ding_pretix')
		->condition('nid', $nid,'=')
		->execute();

	return $pretix_info;
}

function _ding_pretix_get_pretix_node_info_defaults($nid) {
	$settings = variable_get('ding_pretix_event_nodes', array());

	// If this is a new node we use the defaults. If it's an existing node we don't automatically add it to Pretix.
	$maintain_copy = ($nid === null) ? $settings['defaults']['maintain_copy'] : false;

	$pretix_defaults = [
		'capacity' => (int) $settings['capacity'],
		'ticket_type' => $settings['ticket_type'],
		'maintain_copy' => $maintain_copy,
		'passive' => $settings['defaults']['passive'],
	];

	return $pretix_defaults;
}
