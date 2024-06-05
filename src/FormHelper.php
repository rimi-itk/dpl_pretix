<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\recurring_events\Entity\EventSeries;

/**
 *
 */
class FormHelper {
  use StringTranslationTrait;

  public function __construct(
    private readonly Settings $settings,
    private readonly EntityHelper $eventHelper,
    private readonly PretixHelper $pretixHelper,
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   *
   */
  public function formAlter(array &$form, FormStateInterface $formState, string $formId): void {
    $formObject = $formState->getFormObject();
    if ($formObject instanceof EntityForm) {
      $entity = $formObject->getEntity();
      if ($entity instanceof EventSeries) {
        $this->formAlterEventSeries($form, $formState, $entity);
      }
      // \Drupal::messenger()->addMessage(sprintf('entity: %s', $entity::class));
      //       header('content-type: text/plain'); echo var_export($entity::class, true); die(__FILE__.':'.__LINE__.':'.__METHOD__);
    }
    // eventseries_default_add_form
    // eventseries_default_edit_form
    // eventinstance_default_edit_form
    // header('content-type: text/plain'); var_dump($form_id); die(__FILE__.':'.__LINE__.':'.__METHOD__);
    //    \Drupal::messenger()->addMessage(sprintf('%s; formId: %s', __METHOD__, $formId));.
  }

  /**
   *
   */
  private function formAlterEventSeries(
    array &$form,
    FormStateInterface $formState,
    EventSeries $entity,
  ): void {
    $maintain_copy = (bool) $this->settings->getEventNodes('maintain_copy_in_pretix');

    $pretix_node_info = $this->eventHelper->getEntityInfo($entity);
    $pretix_node_defaults = $this->eventHelper->getEntityDefaults($entity);

    // @todo
    // If we are cloning we need to find and set the pretix settings from the event being cloned from.
    if (isset($form['clone_from_original_nid'])) {
      $original_pretix_node_info = $this->eventHelper->getEntityInfo($form['clone_from_original_nid']['#value']);
      $capacity = $original_pretix_node_info['capacity'];
      $maintain_copy = $original_pretix_node_info['maintain_copy'];
      $psp_element = $original_pretix_node_info['psp_element'];
      $ticket_form = $original_pretix_node_info['ticket_form'];
    }
    else {
      $capacity = $pretix_node_info['capacity'] ?? $pretix_node_defaults['capacity'] ?? 0;
      $maintain_copy = (bool) ($pretix_node_info['maintain_copy'] ?? $pretix_node_defaults['maintain_copy'] ?? FALSE);
      $psp_element = $pretix_node_info['psp_element'] ?? $pretix_node_defaults['psp_element'] ?? NULL;
      $ticket_form = $pretix_node_info['ticket_form'] ?? $pretix_node_defaults['default_ticket_form'] ?? NULL;
    }

    // If ($pretix_node_info['maintain_copy']) {
    //      $pretix_url = _ding_pretix_get_event_admin_url($service_settings, $pretix_node_info['pretix_slug']);
    //      $pretix_info = t('Please update price in pretix if needed, go to <a href="@pretix-url">the pretix event</a>. (Note: You may need to log on)', ['@pretix-url' => $pretix_url]);
    //    }
    //    else {
    //      $pretix_info = t('If more ticket types/prices on this event are needed, edit the corresponding event in pretix after the event has been created.');
    //    }
    //    $form['field_ding_event_price']['und'][0]['value']['#description'] = $pretix_info;.
    $pretixEventId = $pretix_node_info['pretix_slug'] ?? NULL;

    $form['dpl_pretix'] = [
      '#weight' => -100,
      '#type' => 'details',
      '#title' => $this->t('pretix'),
      // '#group' => 'additional_settings',
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    // // We don't allow updates to price after the event is created in pretix, must be updated in pretix.
    //    if ($pretix_node_info['maintain_copy']) {
    //      $form['field_ding_event_price']['#disabled'] = TRUE;
    //    }
    // We don't allow manual change of the ticket link if pretix is used.
    if ($maintain_copy) {
      if (isset($form['field_event_link'])) {
        $element = &$form['field_event_link'];
        $element['#disabled'] = TRUE;
        $element['widget'][0]['#description'] = $this->t('This field is managed by pretix for this event.');
      }
    }

    // We don't allow updates to capacity after the event is created in pretix, must be updated in pretix.
    $disabled = isset($pretixEventId);
    $description = $disabled ? t('Please update capacity in pretix if needed.') : t('Optional. Maximum capacity on this event. Set to 0 for unlimited capacity.');

    $form['dpl_pretix']['capacity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event capacity'),
      '#size' => 5,
      '#maxlength' => 5,
      '#default_value' => $capacity,
      '#description' => $description,
      '#disabled' => $disabled,
    ];

    $ding_pretix_psp_elements = $this->settings->getPspElements();
    $metaKey = $ding_pretix_psp_elements['pretix_psp_meta_key'] ?? NULL;
    $elements = $ding_pretix_psp_elements['list'] ?? [];
    if (!empty($metaKey) && is_array($elements)) {
      $options = [];
      foreach ($elements as $element) {
        $options[$element['value']] = $element['name'];
      }

      // PSP is a code for accounting. If an event has orders, we don't allow this to be
      // changed, as this would invalidate the accounting.
      $disabled = isset($pretixEventId) && $this->pretixHelper->hasOrders($pretixEventId);
      $description = $disabled
        ? $this->t('Event has active orders - For accounting reasons the PSP element can no longer be changed.')
        : $this->t('Select the PSP element the ticket sales should be registered under.');

      $form['dpl_pretix']['psp_element'] = [
        '#type' => 'select',
        '#title' => $this->t('PSP Element'),
        '#options' => $options,
        '#default_value' => $psp_element,
        '#required' => TRUE,
        '#empty_option' => $this->t('Select PSP Element'),
        '#description' => $description,
        '#disabled' => $disabled,
      ];
    }

    $form['dpl_pretix']['maintain_copy'] = [
      '#type' => 'checkbox',
      '#title' => t('Maintain copy in pretix'),
      '#default_value' => $maintain_copy,
      '#description' => t('When set, a corresponding event is created and updated on the pretix ticket booking service.'),
    ];

    // $form['dpl_pretix']['ticket_form'] = [
    //      '#type' => 'radios',
    //      '#title' => t('Use PDF or Email tickets'),
    //      '#options' => [
    //        'pdf_ticket' => t('PDF Tickets'),
    //        'email_ticket' => t('Email Tickets'),
    //      ],
    //      '#required' => TRUE,
    //      '#default_value' => $ticket_form,
    //      '#description' => t('Use PDF or Email tickets for the event?'),
    //    ];
    if ($pretixEventId) {
      $pretix_url = $this->pretixHelper->getEventAdminUrl($pretixEventId);
      $pretix_link = Link::fromTextAndUrl($pretix_url, Url::fromUri($pretix_url))->toString();
    }
    else {
      $pretix_link = $this->t('None');
    }

    $form['dpl_pretix']['pretix_slug'] = [
      '#type' => 'item',
      '#title' => $this->t('pretix event'),
      '#markup' => $pretix_link,
      '#description' => $this->t('A link to the corresponding event on the pretix ticket booking service.'),
    ];

    if ($this->currentUser->hasPermission('administer pretix settings')) {
      $form['dpl_pretix']['pretix_settings'] = [
        '#type' => 'container',

        'link' => [
          '#type' => 'link',
          '#title' => $this->t('pretix settings'),
          '#url' => Url::fromRoute('dpl_pretix.settings'),
        ],
      ];
    }
  }

}
