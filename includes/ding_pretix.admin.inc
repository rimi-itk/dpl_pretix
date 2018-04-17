<?php

/**
 * @file ding_pretix.admin.inc
 * Admin forms, page callbacks and related functions.
 */

/**
 * The main settings form.
 */
function ding_pretix_admin_settings_form($form_state) {
  global $user;

  $form = array(
    '#tree' => TRUE,
  );

  $form['ding_pretix_ticketinfo_labels'] = array(
    '#type' => 'item',
    '#title' => t('Labels for display of ticket information'),
    '#markup' => t('<p>5 texts exist for displaying ticket information:</p><ul><li>"The event has already taken place"</li><li>"The event is closed for admission"</li><li>"Ticket sale has not yet started for this event"</li><li>"Sold out"</li><li>"Book a ticket"</li></ul><p>These can all be translated to local needs - just paste the exact texts into the search box at <a href="/admin/config/regional/translate/translate">/admin/config/regional/translate/translate</a>.</p>'),
  );

  // Load default settings
  $defaults = variable_get('ding_pretix', array());

  // Default settings
  $form['ding_pretix'] = array(
    '#type' => 'fieldset',
    '#title' => t('Pretix service'),
    '#collapsible' => TRUE,
    '#collapsed' => isset($defaults['service_url']) ? TRUE : FALSE,
  );

  $form['ding_pretix']['service_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Pretix service URL'),
    '#default_value' => array_key_exists('service_url', $defaults) ? $defaults['service_url'] : 'https://www.pretix.eu/',
    '#description' => t('Enter a valid Pretix service endpoint without path info, such as https://www.pretix.eu/'),
    '#required' => TRUE,
    '#size' => 100,
  );

	$form['ding_pretix']['organizer_slug'] = array(
		'#type' => 'textfield',
		'#title' => t('Pretix default organizer slug'),
		'#default_value' => array_key_exists('organizer_slug', $defaults) ? $defaults['organizer_slug'] : $user->name,
		'#required' => TRUE,
		'#description' => t('This is the default organizer slug used when connecting to Pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
	);

  $form['ding_pretix']['api_key'] = array(
    '#type' => 'textfield',
    '#title' => t('The API key of the Organizer Team'),
    '#default_value' => array_key_exists('api_key', $defaults) ? $defaults['api_key'] : '',
    '#required' => TRUE,
    '#description' => t('This is the default API key used when connecting to Pretix. If you provide slug/API key for a specific library (below), events related to that library will use that key instead of the default key.'),
  );

	$form['ding_pretix']['template_event_slug'] = array(
		'#type' => 'textfield',
		'#title' => t('The slug of the default event template'),
		'#default_value' => array_key_exists('template_event_slug', $defaults) ? $defaults['template_event_slug'] : '',
		'#required' => TRUE,
		'#description' => t('This is the slug of the default event template. When events are created their setting etc. are copied from this event.'),
	);

  $form['ding_pretix_libraries'] = array(
    '#type' => 'fieldset',
    '#title' => t('Individual library slug/API keys'),
    '#description' => t('Optional. If you have several organizers at Pretix, each library can have their own slug/API key. In that case, the base slug/API key will be overridden by the provided key when sending data on events related to this library.'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );

  // Load default settings
  $defaults = variable_get('ding_pretix_libraries', array());

  // Find all libraries and order them by title.
  $query = new EntityFieldQuery();
	$query->entityCondition('entity_type', 'node')
	      ->entityCondition('bundle', 'ding_library')
	      ->propertyOrderBy('title', 'fid', 'DESC')
				->addMetaData('account', user_load(1));
	$result = $query->execute();

	if (isset($result['node'])) {
		$libraries_nids = array_keys($result['node']);
		$libraries = entity_load('node', $libraries_nids);
	}

  foreach ($libraries as $library) {
	  $form['ding_pretix_libraries'][$library->nid] = array(
	  	'#type' => 'fieldset',
		  '#title' => check_plain($library->title) . t(' API keys'),
		  '#collapsible' => TRUE,
		  '#collapsed' => FALSE,
	  );

	  $form['ding_pretix_libraries'][$library->nid][$library->nid.'_slug'] = array(
		  '#type' => 'textfield',
		  '#title' => t('Slug ') . check_plain($library->title),
		  '#default_value' => array_key_exists($library->nid, $defaults) ? $defaults[$library->nid] : '',
		  '#description' => t('The slug of the Pretix organizer to map to.'),
	  );

    $form['ding_pretix_libraries'][$library->nid][$library->nid.'_apikey'] = array(
      '#type' => 'textfield',
      '#title' => t('API key ') . check_plain($library->title),
      '#default_value' => array_key_exists($library->nid, $defaults) ? $defaults[$library->nid] : '',
      '#description' => t('The API key of the Organizer Team'),
    );
  }

  $form['ding_pretix_event_nodes'] = array(
    '#type' => 'fieldset',
    '#title' => t('Pretix event node defaults'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );

  // Load default settings
  $defaults = variable_get('ding_pretix_event_nodes', array());

  $form['ding_pretix_event_nodes']['capacity'] = array(
    '#type' => 'textfield',
    '#title' => t('Capacity'),
    '#default_value' => array_key_exists('capacity', $defaults) ? $defaults['capacity'] : 0,
    '#size' => 5,
    '#maxlength' => 5,
    '#description' => t('The default capacity for new events. Set to 0 for unlimited capacity.'),
  );

  $form['ding_pretix_event_nodes']['ticket_type'] = array(
    '#type' => 'textfield',
    '#title' => t('Ticket type'),
    '#default_value' => array_key_exists('ticket_type', $defaults) ? $defaults['ticket_type'] : 'Adgang',
    '#size' => 30,
    '#maxlength' => 255,
    '#description' => t('The default ticket type for new events.'),
  );

  $form['ding_pretix_event_nodes']['defaults'] = array(
    '#type' => 'checkboxes',
    '#options' => array(
      'maintain_copy' => t('Maintain copy'),
      'passive' => t('Passive event'),
    ),
    '#default_value' => array_key_exists('defaults', $defaults) ? $defaults['defaults'] : array(),
  );

  return system_settings_form($form);
}