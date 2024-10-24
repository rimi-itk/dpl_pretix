<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\webform\Utility\WebformArrayHelper;

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

  public const FIELD_TICKET_URL = 'field_event_link';
  public const FIELD_TICKET_CATEGORIES = 'field_ticket_categories';
  public const FIELD_TICKET_CAPACITY = 'field_ticket_capacity';

  public const CUSTOM_FORM_VALUES = 'custom_form_values';

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
   * Implements hook_prepare_form().
   */
  public function prepareForm(EntityInterface $entity, string $operation, FormStateInterface $formState): void {
    if (!in_array($operation, ['add', 'edit'], TRUE)) {
      return;
    }

    if (!$this->settings->getPretixSettings()->isReady()) {
      return;
    }

    if ($event = $this->getEventSeriesEntity($formState)) {
      $this->prepareFormEventSeries($event, $operation, $formState);
    }
  }

  /**
   * Prepare event.
   */
  public function prepareFormEventSeries(EventSeries $event, string $operation, FormStateInterface $formState): void {
    /** @var \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList $ticketCategories */
    $ticketCategories = $event->get(self::FIELD_TICKET_CATEGORIES);
    // Make sure that (at least) one ticket category exists.
    if ($ticketCategories->isEmpty()) {
      $paragraph = Paragraph::create([
        'type' => 'event_ticket_category',
        'field_ticket_category_name' => $this->settings->getEventNodes()->defaultTicketCategoryName,
        // Force user to set price.
        'field_ticket_category_price' => NULL,
      ]);
      $ticketCategories->appendItem($paragraph);
    }
  }

  /**
   * Implements hook_form_alter().
   */
  public function formAlter(array &$form, FormStateInterface $formState, string $formId): void {
    $formObject = $formState->getFormObject();
    if (!($formObject instanceof ContentEntityFormInterface)) {
      return;
    }
    $operation = $formObject->getOperation();
    if (!in_array($operation, ['add', 'edit'], TRUE)) {
      return;
    }

    if ($event = $this->getEventSeriesEntity($formState)) {
      $this->formAlterEventSeries($form, $formState, $event);
    }
    elseif ($instance = $this->getEventInstanceEntity($formState)) {
      $this->formAlterEventInstance($form, $formState, $instance);
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
    if (!$settings->isReady()) {
      $form[self::FORM_KEY]['warning'] = [
        // Wrap message in container to make states work.
        '#type' => 'container',

        'message' => [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              $this->t('No pretix settings found for domain %domain.', ['%domain' => $settings->domain]),
            ],
          ],
        ],
      ];

      return;
    }

    $form[self::FORM_KEY][self::ELEMENT_MAINTAIN_COPY] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create and update in pretix'),
      '#default_value' => $eventData->maintainCopy,
      '#return_value' => TRUE,
      '#description' => $this->t('When set, a corresponding event is created and updated in <a href=":organizer_url">pretix</a>.',
        [
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
    $form[self::FIELD_TICKET_URL]['#states'] = [
      'disabled' => [
        ':input[name="dpl_pretix[maintain_copy]"]' => ['checked' => TRUE],
      ],
    ];

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

    $disabled = !empty($eventData->templateEvent);
    if ($disabled) {
      $options = [$eventData->templateEvent => $options[$eventData->templateEvent] ?? $eventData->templateEvent];
    }

    $form[self::FORM_KEY][self::ELEMENT_TEMPLATE_EVENT] = [
      '#type' => 'select',
      '#title' => $this->t('Template event'),
      '#default_value' => $eventData->templateEvent,
      '#options' => $options,
      '#empty_option' => $this->t('Select template event'),
      '#disabled' => $disabled,
      '#description' => $this->t('Template event used to create event in pretix.'),
    ] + $states;

    if (!$entity->isNew()) {
      if ($pretixAdminUrl = $eventData->getEventAdminUrl()) {
        $pretix_link = Link::fromTextAndUrl($pretixAdminUrl,
          Url::fromUri($pretixAdminUrl))->toString();
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

    $form['#validate'][] = [$this, 'validateEventForm'];

    if (isset($form[self::FIELD_TICKET_CATEGORIES])) {
      $element = [
        // Wrap message in container to make states work.
        '#type' => 'container',

        'message' => [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              $this->t('Create a ticket category to set the event price in pretix. Only the price from the first category will be used.'),
            ],
          ],
        ],
        '#states' => [
          'visible' => [
            ':input[name="dpl_pretix[maintain_copy]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      if (isset($form[self::FIELD_TICKET_CATEGORIES]['#weight'])) {
        $element['#weight'] = $form[self::FIELD_TICKET_CATEGORIES]['#weight'];
      }
      WebformArrayHelper::insertBefore($form, self::FIELD_TICKET_CATEGORIES, self::FIELD_TICKET_CATEGORIES . '_message', $element);
      // Require (at least) one ticket category.
      $form[self::FIELD_TICKET_CATEGORIES]['widget']['#required'] = TRUE;
    }
  }

  /**
   * Alters event form.
   */
  private function formAlterEventInstance(
    array &$form,
    FormStateInterface $formState,
    EventInstance $entity,
  ): void {
    /** @var \Drupal\recurring_events\Entity\EventSeries $series */
    $series = $entity->getEventSeries();
    $eventData = $this->eventDataHelper->getEventData($series);
    if ($eventData?->maintainCopy && isset($eventData->pretixEvent)) {
      // We don't allow manual change of the ticket link if pretix is used.
      $this->disableElement($form, self::FIELD_TICKET_URL,
        $this->t('This field is managed by pretix for this event instance.'));

      $form[self::FORM_KEY] = [
        '#weight' => $this->settings->getEventForm()->weight ?? 9999,
        '#type' => 'details',
        '#title' => $this->t('pretix'),
        '#tree' => TRUE,
        '#open' => TRUE,
      ];

      $instanceData = $this->eventDataHelper->getEventData($entity);
      if ($pretixAdminUrl = $instanceData?->getEventAdminUrl()) {
        $pretix_link = Link::fromTextAndUrl($pretixAdminUrl, Url::fromUri($pretixAdminUrl))->toString();

        $form[self::FORM_KEY]['pretix_info'] = [
          '#type' => 'item',
          '#title' => $this->t('pretix date (sub-event)'),
          '#markup' => $pretix_link,
          '#description' => $this->t('A link to the corresponding date (sub-event) in pretix.'),
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateEventForm(array $form, FormStateInterface $formState): void {
    if ($event = $this->getEventSeriesEntity($formState)) {
      // Store our custom values for use in entity save/update hook.
      $values = $formState->getValue(self::FORM_KEY) ?? [];
      $values += [
        self::CUSTOM_FORM_VALUES => [],
      ];
      EntityHelper::setFormValues($event, $values);

      if ($values[self::ELEMENT_MAINTAIN_COPY] ?? FALSE) {
        if (empty($values[self::ELEMENT_PSP_ELEMENT])) {
          $formState->setErrorByName(self::FORM_KEY . '][' . self::ELEMENT_PSP_ELEMENT, $this->t('@field is required.', [
            '@field' => $this->t('PSP element'),
          ]));
        }
        if (empty($values[self::ELEMENT_TEMPLATE_EVENT])) {
          $formState->setErrorByName(self::ELEMENT_TEMPLATE_EVENT, $this->t('@field is required.', [
            '@field' => $this->t('Template event'),
          ]));
        }
      }
    }
  }

  /**
   * Get event series entity for a form.
   */
  private function getEventSeriesEntity(FormStateInterface $formState): ?EventSeries {

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
   * Get event instance entity for a form.
   */
  private function getEventInstanceEntity(FormStateInterface $formState): ?EventInstance {

    $formObject = $formState->getFormObject();
    if ($formObject instanceof EntityForm) {
      $entity = $formObject->getEntity();
      if ($entity instanceof EventInstance) {
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
      // @fixme We only handle link fields for now.
      if (isset($element['widget'][0]['uri']['#description']['#items'])) {
        $element['widget'][0]['uri']['#description']['#items'][] = $reason;
      }
    }
  }

}
