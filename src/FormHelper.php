<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Form helper.
 */
class FormHelper {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public const FORM_KEY = 'dpl_pretix';
  public const ELEMENT_MAINTAIN_COPY = 'maintain_copy';
  public const ELEMENT_TEMPLATE_EVENT = 'template_event';
  public const ELEMENT_PSP_ELEMENT = 'psp_element';

  public function __construct(
    private readonly Settings $settings,
    private readonly EntityHelper $eventHelper,
    private readonly EventDataHelper $eventDataHelper,
    private readonly AccountInterface $currentUser,
  ) {
  }

  /**
   * Implements hook_form_alter().
   */
  public function formAlter(array &$form, FormStateInterface $formState, string $formId): void {
    if ($event = $this->getEventSeriesEntity($formState)) {
      $this->formAlterEventSeries($form, $formState, $event);
    }
  }

  /**
   * Alters event form.
   */
  private function formAlterEventSeries(
    array &$form,
    FormStateInterface $formState,
    EventSeries $entity,
  ): void {
    // @todo If we are cloning we need to find and set the pretix settings from the event being cloned from.
    $eventData = $this->eventDataHelper->getEventData($entity)
      ?? $this->eventDataHelper->createEventData($entity);

    $form[self::FORM_KEY] = [
      '#weight' => $this->settings->getEventForm()->weight ?? 9999,
      '#type' => 'details',
      '#title' => $this->t('pretix'),
      '#tree' => TRUE,
      '#open' => TRUE,
    ];

    // We don't allow manual change of the ticket link if pretix is used.
    if ($eventData->maintainCopy) {
      if (isset($form['field_event_link'])) {
        $element = &$form['field_event_link'];
        $element['#disabled'] = TRUE;
        $element['widget'][0]['#description'] = $this->t('This field is managed by pretix for this event.');
        unset($element);
      }
    }

    $pretixEventId = $eventData->pretixEvent;

    // We don't allow updates to capacity after the event is created in pretix,
    // must be updated in pretix.
    $disabled = isset($pretixEventId);
    $description = $disabled
      ? $this->t('Please update capacity in pretix if needed.')
      : $this->t('Optional. Maximum capacity on this event. Set to 0 for unlimited capacity.');

    $ding_pretix_psp_elements = $this->settings->getPspElements();
    $metaKey = $ding_pretix_psp_elements->pretixPspMetaKey ?? NULL;
    $elements = $ding_pretix_psp_elements->list ?? [];
    if (!empty($metaKey) && is_array($elements) && !empty($elements)) {
      $options = [];
      foreach ($elements as $element) {
        $options[$element->value] = $element->name;
      }

      // PSP is a code for accounting. If an event has orders, we don't allow
      // this to be changed, as this would invalidate the accounting.
      $disabled = isset($pretixEventId) && $this->eventHelper->hasOrders($pretixEventId);
      $description = $disabled
        ? $this->t('Event has active orders - For accounting reasons the PSP element can no longer be changed.')
        : $this->t('Select the PSP element the ticket sales should be registered under.');

      $form[self::FORM_KEY][self::ELEMENT_PSP_ELEMENT] = [
        '#type' => 'select',
        '#title' => $this->t('PSP Element'),
        '#options' => $options,
        '#default_value' => $eventData->pspElement,
        '#required' => TRUE,
        '#empty_option' => $this->t('Select PSP Element'),
        '#description' => $description,
        '#disabled' => $disabled,
      ];
    }

    $form[self::FORM_KEY][self::ELEMENT_MAINTAIN_COPY] = [
      '#type' => 'checkbox',
      '#title' => t('Maintain copy in pretix'),
      '#default_value' => $eventData->maintainCopy,
      '#description' => t('When set, a corresponding event is created and updated on the pretix ticket booking service.'),
    ];

    $options = [
      'pdf_ticket' => t('PDF Tickets'),
      'email_ticket' => t('Email Tickets'),
    ];
    $form[self::FORM_KEY][self::ELEMENT_TEMPLATE_EVENT] = [
      '#type' => 'radios',
      '#title' => t('Template event'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $eventData->templateEvent,
      '#description' => t('Template event used to create event in pretix'),
    ];

    if (!$entity->isNew()) {
      if ($pretixAdminUrl = $eventData->getEventAdminUrl()) {
        $pretix_link = Link::fromTextAndUrl($pretixAdminUrl, Url::fromUri($pretixAdminUrl))->toString();
      }
      else {
        $pretix_link = $this->t('None');
      }

      $form[self::FORM_KEY]['pretix_info'] = [
        '#type' => 'item',
        '#title' => $this->t('pretix event'),
        '#markup' => $pretix_link,
        '#description' => $this->t('A link to the corresponding event on the pretix ticket booking service.'),
      ];
    }

    // Make it easy for administrators to edit pretix settings.
    if ($this->currentUser->hasPermission('administer dpl_pretix settings')) {
      $form[self::FORM_KEY]['pretix_settings'] = [
        '#type' => 'container',

        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Edit pretix settings'),
          '#url' => Url::fromRoute('dpl_pretix.settings'),
        ],
      ];
    }

    $form['#validate'][] = [$this, 'validateForm'];

    if (isset($form['actions']['submit']['#submit'])) {
      $form['actions']['submit']['#submit'][] = [$this, 'submitHandler'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array $form, FormStateInterface $formState): void {
    // @todo add validation?
  }

  /**
   * Submit handler for event form.
   */
  public function submitHandler(array $form, FormStateInterface $formState): void {
    if ($event = $this->getEventSeriesEntity($formState)) {
      // We're lucky, and even new events have already been saved when our
      // submit handler is run.
      $data = $this->eventDataHelper->getEventData($event) ?? new EventData();

      $values = $formState->getValue(self::FORM_KEY) ?? [];
      $data->capacity = (int) ($values[self::ELEMENT_CAPACITY] ?? 0);
      $data->maintainCopy = (bool) ($values[self::ELEMENT_MAINTAIN_COPY] ?? FALSE);
      $data->ticketType = $values[self::ELEMENT_TICKET_TYPE] ?? '';
      $data->pspElement = $values[self::ELEMENT_PSP_ELEMENT] ?? NULL;

      $this->eventHelper->setEventData($event, $data);
    }
  }

  /**
   * Get event entity for a form.
   */
  private function getEventSeriesEntity(FormStateInterface $formState): EventSeries|null {

    $formObject = $formState->getFormObject();
    if ($formObject instanceof EntityForm) {
      $entity = $formObject->getEntity();
      if ($entity instanceof EventSeries) {
        return $entity;
      }
    }

    return NULL;
  }

}
