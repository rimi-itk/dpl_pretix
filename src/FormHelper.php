<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
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

  public const FIELD_EVENT_LINK = 'field_event_link';
  private const FIELD_TICKET_CAPACITY = 'field_ticket_capacity';

  public function __construct(
    private readonly Settings $settings,
    private readonly EntityHelper $eventHelper,
    private readonly EventDataHelper $eventDataHelper,
    private readonly PretixHelper $pretixHelper,
    private readonly MessengerInterface $messenger,
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

    $settings = $this->settings->getPretixSettings();
    $form[self::FORM_KEY][self::ELEMENT_MAINTAIN_COPY] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create and update in pretix'),
      '#default_value' => $eventData->maintainCopy,
      '#return_value' => TRUE,
      '#description' => $this->t('When set, a corresponding event is created and updated in <a href=":organizer_url">pretix</a>.', [
        ':organizer_url' => $this->pretixHelper->getOrganizerUrl($settings),
      ]),
    ];

    $states = [
      '#states' => [
        'visible' => [
          ':input[name="dpl_pretix[maintain_copy]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $states['#states']['required'] = $states['#states']['visible'];

    $pretixEventId = $eventData->pretixEvent;

    // We don't allow manual change of the ticket link if pretix is used.
    if ($eventData->maintainCopy && isset($pretixEventId)) {
      $this->disableElement($form, self::FIELD_EVENT_LINK, $this->t('This field is managed by pretix for this event.'));
    }

    // We don't allow updates to capacity after the event is created in pretix,
    // must be updated in pretix.
    $disabled = isset($pretixEventId);
    if ($disabled) {
      $this->disableElement($form, self::FIELD_TICKET_CAPACITY, $this->t('Update capacity in pretix if needed.'));
    }

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
        ? $this->t('Event has active orders. For accounting reasons the PSP element can no longer be changed.')
        : $this->t('Select the PSP element the ticket sales should be registered under.');

      $form[self::FORM_KEY][self::ELEMENT_PSP_ELEMENT] = [
        '#type' => 'select',
        '#title' => $this->t('PSP element'),
        '#options' => $options,
        '#default_value' => $eventData->pspElement,
        '#empty_option' => $this->t('Select PSP element'),
        '#description' => $description,
        '#disabled' => $disabled,
      ] + $states;
    }

    try {
      $options = $this->pretixHelper->parseTemplateEvents(
        $this->settings->getPretixSettings()->templateEvents ?? ''
      );
    }
    catch (\Exception) {
      $this->messenger->addError($this->t('Error parsing pretix template events.'));
      $options = [];
    }
    $form[self::FORM_KEY][self::ELEMENT_TEMPLATE_EVENT] = [
      '#type' => 'select',
      '#title' => $this->t('Template event'),
      '#options' => $options,
      '#default_value' => $eventData->templateEvent,
      '#empty_option' => $this->t('Select template event'),

      '#description' => $this->t('Template event used to create event in pretix'),
    ] + $states;

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
        '#description' => $this->t('A link to the corresponding event in pretix.'),
      ] + $states;
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
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array $form, FormStateInterface $formState): void {
    if ($event = $this->getEventSeriesEntity($formState)) {
      // Store our custom values for use in entity save/update hook.
      EntityHelper::setFormValues($event, $formState->getValue(self::FORM_KEY) ?? []);

      $eventData = $this->eventDataHelper->getEventData($event)
        ?? $this->eventDataHelper->createEventData($event);

      if ((bool) $formState->getValue(self::ELEMENT_MAINTAIN_COPY)) {
        if (empty($formState->getValue(self::ELEMENT_PSP_ELEMENT))) {
          $formState->setErrorByName(self::ELEMENT_PSP_ELEMENT, $this->t('PSP element is required.'));
        }
        if (empty($formState->getValue(self::ELEMENT_TEMPLATE_EVENT))) {
          $formState->setErrorByName(self::ELEMENT_TEMPLATE_EVENT, $this->t('Template event is required.'));
        }
      }
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

  /**
   * Disable a form element and show the reason.
   */
  private function disableElement(array &$form, string $field, TranslatableMarkup $reason): void {
    if (isset($form[$field])) {
      $element = &$form[$field];
      $element['#disabled'] = TRUE;
      // @todo show reason somewhere reasonable.
      // $element['widget'][0]['#prefix']
      // = '<div class="form-item__description">'.$reason .'</div>';
      // if (isset($element['widget'][0]['#description'])) {
      // $element['widget'][0]['#description'] .= $reason;
      // }
      // elseif (isset($element['widget'][0])) {
      // $element['widget'][0]['#description'] = $reason;
      // }
    }
  }

}
