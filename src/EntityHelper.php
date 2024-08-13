<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\dpl_pretix\Exception\InvalidEventSeriesException;
use Drupal\dpl_pretix\Exception\SynchronizeException;
use Drupal\dpl_pretix\Pretix\ApiClient\Client as PretixApiClient;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollectionInterface;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event as PretixEvent;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventInterface;
use Psr\Log\LoggerInterface;
use Safe\DateTimeImmutable;
use function Safe\sprintf;

/**
 * Entity helper.
 */
class EntityHelper {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  public const INSERT = 'insert';
  public const UPDATE = 'update';
  public const DELETE = 'delete';

  // @see /admin/structure/events/instance/types/eventinstance_type/default/edit/fields
  private const EVENT_TICKET_LINK_FIELD = 'field_event_link';

  /**
   * Used to save entity without running our entity listener.
   *
   * @var bool
   */
  private bool $skipEntityListeners = FALSE;

  public function __construct(
    private readonly Settings $settings,
    private readonly EventDataHelper $eventDataHelper,
    private readonly PretixHelper $pretixHelper,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity): void {
    if ($this->skipEntityListeners) {
      return;
    }

    if ($entity instanceof EventSeries) {
      // @see https://drupal.stackexchange.com/a/225627
      drupal_register_shutdown_function($this->postEntityInsert(...), $entity);
    }
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity): void {
    if ($this->skipEntityListeners) {
      return;
    }

    if ($entity instanceof EventSeries) {
      $this->synchronizeEvent($entity, self::UPDATE);
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity): void {
    if ($this->skipEntityListeners) {
      return;
    }

    if ($entity instanceof EventSeries) {
      $this->synchronizeEvent($entity, self::DELETE);
    }
  }

  /**
   * Synchronize event in pretix.
   */
  public function synchronizeEvent(EventSeries $event, string $action): ?PretixEvent {
    try {
      $settings = $this->settings->getPretixSettings();
      $templateEvent = $settings->templateEvent;

      $this->logger->info('Synchronizing event ' . $event->id());
      $data = $this->eventDataHelper->loadEventData($event) ?? $this->eventDataHelper->createEventData($event);
      if ($data->hasPretixEvent()) {
        // Update event in pretix.
        assert(NULL !== $data->pretixEvent);
        $pretixEvent = $this->pretix()->updateEvent($data->pretixEvent, [
          'name' => $this->getEventName($event),
          'date_from' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
      }
      else {
        // Create event in pretix (by cloning the template event).
        // @see https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-(event)-clone-
        $pretixEvent = $this->pretix()->cloneEvent(
          $templateEvent,
          $this->getPretixEventData($event, [
            // ? 'live' => false,
            // The API documentation claims that `has_subevents` is copied when cloning, but that doesn't seem to be correct (cf. https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-(event)-clone-).
            'has_subevents' => TRUE,
          ])
        );
        $data->pretixUrl = $settings->url;
        $data->pretixOrganizer = $settings->organizer;
        $data->pretixEvent = $pretixEvent->getSlug();
        $this->eventDataHelper->saveEventData($event, $data);

        $this->setTicketLink($event, save: TRUE);
      }

      // @todo Set settings?
      // $this->pretix()->setEventSetting();
      $this->synchronizeEventInstances($templateEvent, $pretixEvent, $event);

      return $pretixEvent;
    }
    catch (\Throwable $t) {
      $this->messenger->addError($this->t('Error synchronizing @event: @message', [
        '@event' => sprintf('%s:%s', $event->getEntityTypeId(), $event->id()),
        '@message' => $t->getMessage(),
      ]));
      $this->logger->error('Error synchronizing @event: @message', [
        '@event' => sprintf('%s:%s', $event->getEntityTypeId(), $event->id()),
        '@message' => $t->getMessage(),
        '@throwable' => $t,
      ]);

      throw $t;
    }

    return NULL;
  }

  /**
   * Synchronize event instances.
   */
  private function synchronizeEventInstances(string $templateEvent, PretixEvent $pretixEvent, EventSeries $event): array {
    $this->logger->info('Synchronizing sub-events for ' . $event->id());

    $instances = $this->getEventInstances($event);
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent[] $results */
    $results = [];
    foreach ($instances as $instance) {
      $results[] = $this->synchronizeEventInstance($instance, $templateEvent, $pretixEvent, $event);
    }

    // Delete pretix sub-events that no longer exist in Drupal.
    $subEventIds = array_map(static fn (array $result) => $result['data']->pretixSubeventId, $results);
    try {
      $subEvents = $this->pretix()->getSubEvents($pretixEvent);
      foreach ($subEvents as $subEvent) {
        if (!in_array($subEvent->getId(), $subEventIds, TRUE)) {
          $this->pretix()->deleteSubEvent($event, $subEvent);
        }
      }
    }
    catch (\Exception $exception) {
      $this->logException($exception);
    }

    return $results;
  }

  /**
   * Synchronize event instance.
   *
   * @return array
   *   - status: string
   *   - data: EventData for instance
   */
  private function synchronizeEventInstance(EventInstance $instance, string $templateEvent, PretixEvent $pretixEvent, EventSeries $event): array {
    $instanceData = $this->eventDataHelper->loadEventData($instance) ?? $this->eventDataHelper->createEventData($instance);
    $isNewItem = NULL === $instanceData->pretixSubeventId;

    $pretix = $this->pretix();
    try {
      $templateSubEvents = $pretix->getSubEvents($templateEvent);
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot get sub-events for template event @event', [
        '@event' => $templateEvent,
      ]), $exception);
    }
    if ($templateSubEvents->isEmpty()) {
      throw $this->pretixException($this->t('Template event @event has no sub-events', [
        '@event' => $templateEvent,
      ]));
    }
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $templateSubEvent */
    $templateSubEvent = $templateSubEvents->first();

    /** @var ?\Drupal\dpl_pretix\Pretix\ApiClient\Entity\Item $product */
    $product = NULL;
    $data = $instanceData->data['subevent'] ?? [];
    if ($isNewItem) {
      // Get first product (item) from template event.
      try {
        $items = $pretix->getItems($pretixEvent);
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot get items for template event @event', [
          '@event' => $templateEvent,
        ]), $exception);
      }
      if ($items->isEmpty()) {
        throw $this->pretixException($this->t('Template event @event has no items', [
          '@event' => $templateEvent,
        ]));
      }

      // Always use the first product.
      $product = $items->first();

      $data = $templateSubEvent->toArray();
      // Remove the template id.
      unset($data['id']);

      $data['item_price_overrides'] = [
        [
          'item' => $product->getId(),
        ],
      ];
      $data['variation_price_overrides'] = [];

      // Store the sub-event data for future updates.
      $instanceData->data['subevent'] = $data;
    }

    $range = $this->getDateRange($instance);

    $data = array_merge($data, [
      'name' => $this->getEventName($event),
      'date_from' => $this->pretixHelper->formatDate($range[0]),
      'date_to' => $this->pretixHelper->formatDate($range[1]),
      'location' => $this->getLocation($event),
      'frontpage_text' => NULL,
      'active' => TRUE,
      'is_public' => TRUE,
      'date_admission' => NULL,
      'presale_end' => NULL,
      'seating_plan' => NULL,
      'seat_category_mapping' => (object) [],
    ]);

    // @todo Handle prices.
    $price = 0;
    $data['item_price_overrides'][0]['price'] = $price;

    // Important: meta_data value must be an object!
    $data['meta_data'] = (object) ($data['meta_data'] ?? []);
    $subEventData = [];
    if ($isNewItem) {
      try {
        $subEvent = $pretix->createSubEvent($pretixEvent, $data);
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot create sub-event for event @event', [
          '@event' => $pretixEvent->getId(),
        ]), $exception);
      }
    }
    else {
      $subEventId = $instanceData->pretixSubeventId;
      try {
        $subEvent = $pretix->updateSubEvent($pretixEvent, $subEventId, $data);
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot update sub-event @sub_event on event @event', [
          '@sub_event' => $subEventId,
          '@event' => $pretixEvent->getId(),
        ]), $exception);
      }
    }

    // Get sub-event quotas.
    try {
      $quotas = $pretix->getQuotas(
        $pretixEvent,
        ['query' => ['subevent' => $subEvent->getId()]]
      );
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot get quotas for sub-event @sub_event', [
        '@sub_event' => $subEvent->getId(),
      ]), $exception);
    }

    if ($quotas->isEmpty()) {
      if (NULL === $product) {
        throw $this->pretixException($this->t('Product is not set when creating quota from template sub-event @sub_event', [
          '@sub_event' => $templateSubEvent->getId(),
        ]));
      }
      // Create a new quota for the sub-event.
      try {
        $templateQuotas = $pretix->getQuotas(
          $templateEvent,
          ['subevent' => $templateSubEvent->getId()]
        );
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot get quotas for template sub-event @sub_event', [
          '@sub_event' => $templateSubEvent->getId(),
        ]), $exception);
      }
      if ($templateQuotas->isEmpty()) {
        throw $this->pretixException($this->t('Template sub-event @sub_event has no quotas', [
          '@sub_event' => $templateSubEvent->getId(),
        ]));
      }

      $quotaData = $templateQuotas->first()->toArray();
      unset($quotaData['id']);

      $quotaData = array_merge($quotaData, [
        'subevent' => $subEvent->getId(),
        'items' => [$product->getId()],
        'variations' => [$product->toArray()['variations'][0]['id']],
        'size' => $this->getCapacity($event),
      ]);
      try {
        $quota = $pretix->createQuota($pretixEvent, $quotaData);
        $quotas->add($quota);
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot create quota for sub-event @sub_event on event @event', [
          '@sub_event' => $subEvent->getId(),
          '@event' => $pretixEvent->getSlug(),
        ]), $exception);
      }
    }

    // We only and always use the first quota.
    if ($quota = $quotas->first()) {
      $quotaData = array_merge($quota->toArray(), [
        'size' => $this->getCapacity($event),
      ]);
      try {
        $quota = $pretix->updateQuota($pretixEvent, $quota, $quotaData);
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot update quota for sub-event'), $exception);
      }
    }

    $instanceData->pretixEvent = $pretixEvent->getSlug();
    $instanceData->pretixSubeventId = $subEvent->getId();
    $this->eventDataHelper->saveEventData($instance, $instanceData);

    return [
      'status' => $isNewItem ? 'created' : 'updated',
      'data' => $instanceData,
    ];

  }

  /**
   * Delete event in pretix.
   */
  public function deleteEvent(EventSeries $event): bool {
    $data = $this->eventDataHelper->loadEventData($event);

    if (NULL !== $data) {
      $response = $this->pretix()->deleteEvent($data->pretixEvent);

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get event series.
   */
  public function getEventSeries(string $id): EventSeries {
    /** @var ?\Drupal\recurring_events\Entity\EventSeries $event */
    $event = $this->entityTypeManager->getStorage('eventseries')->load($id);

    if (NULL === $event) {
      throw new InvalidEventSeriesException(sprintf('Invalid event series ID: %s', $id));
    }

    return $event;
  }

  /**
   * Get event instances.
   *
   * @return array<EventInstance>
   *   The instances.
   */
  public function getEventInstances(EventSeries $event): array {
    return $event->get('event_instances')->referencedEntities();
  }

  /**
   * Set event data.
   */
  public function setEventData(EventSeries $event, EventData $data): void {
    $this->eventDataHelper->saveEventData($event, $data);
    $this->synchronizeEvent($event, $event->isNew() ? self::INSERT : self::UPDATE);
  }

  /**
   * Decide if event has orders in pretix.
   */
  public function hasOrders(string $event): bool {
    return $this->getOrders($event)->count() > 0;
  }

  /**
   * Get event orders form pretix.
   */
  public function getOrders(string $event): EntityCollectionInterface {
    return $this->pretix()->getOrders($event);
  }

  /**
   * Get events from pretix.
   *
   * @param array<string, mixed> $query
   *   The query.
   */
  public function getEvents(array $query): EntityCollectionInterface {
    return $this->pretix()->getEvents($query);
  }

  /**
   * Ping pretix API.
   */
  public function pingApi(): void {
    $this->getEvents([]);
  }

  /**
   * Get pretix event data.
   *
   * @see https://docs.pretix.eu/en/latest/api/resources/events.html#resource-description
   */
  private function getPretixEventData(EventSeries $event, array $data = []): array {
    $instances = $this->getEventInstances($event);
    $firstInstance = reset($instances) ?: NULL;
    $lastInstance = end($instances) ?: NULL;
    $dateFrom = $this->getDateRange($firstInstance)[0];
    $dateTo = $this->getDateRange($lastInstance)[1];

    return $data + [
      'name' => $this->getEventName($event),
      'slug' => $this->getPretixEventSlug($event),
      'date_from' => $this->pretixHelper->formatDate($dateFrom),
      'date_to' => $this->pretixHelper->formatDate($dateTo),
      'is_public' => $event->isPublished(),
    ];
  }

  /**
   * Get pretix event short name.
   */
  private function getPretixEventSlug(EventSeries $event): string {
    $pretixSettings = $this->settings->getPretixSettings();

    return str_replace(['{id}'], [$event->id()], $pretixSettings->eventSlugTemplate);
  }

  /**
   * Get pretix API client.
   */
  private function pretix(): PretixApiClient {
    return $this->pretixHelper->client();
  }

  /**
   * Post entity insert handler.
   *
   * @see https://drupal.stackexchange.com/a/225627
   */
  private function postEntityInsert(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      $data = $this->eventDataHelper->createEventData($entity);
      $this->eventDataHelper->saveEventData($entity, $data);
      $this->synchronizeEvent($entity, self::INSERT);
    }
    elseif ($entity instanceof EventInstance) {
      // @todo $entity->getEventSeries();
    }
  }

  /**
   * Set ticket link on event entity.
   */
  private function setTicketLink(EventInterface $event, bool $save = FALSE): void {
    $data = $this->eventDataHelper->getEventData($event);
    if ($url = $data?->getEventShopUrl()) {
      if ($event->hasField(self::EVENT_TICKET_LINK_FIELD)) {
        $event->set(self::EVENT_TICKET_LINK_FIELD, [
          'uri' => $url,
        ]);
        if ($save) {
          $this->runWithoutEntityListeners(static function () use ($event) {
            $event->save();
          });
        }
      }
    }
  }

  /**
   * Run code with triggering our entity listeners.
   */
  private function runWithoutEntityListeners(callable $callable): void {
    $this->skipEntityListeners = TRUE;
    $callable();
    $this->skipEntityListeners = FALSE;
  }

  /**
   * Log exception.
   */
  private function logException(\Exception $exception, ?string $message = NULL): void {
    $message ??= $exception::class;
    $this->logger->error('@message: @exception_message', [
      '@message' => $message,
      '@exception_message' => $exception->getMessage(),
      'exception' => $exception,
    ]);
  }

  /**
   * Handle a pretix api client exception.
   */
  protected function pretixException(string $message, \Exception $exception = NULL): SynchronizeException {
    if (NULL === $exception) {
      $this->logger->error($message);
    }
    else {
      $this->logException($exception, $message);
    }

    return new SynchronizeException($message, 0, $exception);
  }

  /**
   * Get event name from an event.
   */
  private function getEventName(EventSeries $event): array {
    return [
      $this->getDefaultLanguageCode($event) => $event->label(),
    ];
  }

  /**
   * Get item location.
   */
  private function getLocation(EventSeries $event): array {
    /** @var ?\Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $event->get('field_event_address')->first();

    if (empty($address)) {
      return [];
    }

    return [
      $this->getDefaultLanguageCode($event) => implode(PHP_EOL, array_filter([
        $address->getAddressLine1(),
        $address->getAddressLine2(),
        $address->getPostalCode() . ' ' . $address->getLocality(),
      ])),
    ];
  }

  /**
   * Get default language code for an event.
   */
  private function getDefaultLanguageCode(EventSeries $event): string {
    return $this->settings->getPretixSettings()->defaultLanguageCode ?? 'en';
  }

  /**
   * Get event capacity.
   */
  private function getCapacity(EventSeries $event): ?int {
    $capacity = (int) $event->get('field_ticket_capacity')->getString();

    return $capacity > 0 ? $capacity : NULL;
  }

  /**
   * Get instance date range.
   */
  private function getDateRange(EventInstance $instance): array {
    $range = [];

    /** @var \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem $date */
    $date = $instance->get('date')->first();

    foreach (['value', 'end_value'] as $key) {
      $value = $date->get($key)->getValue();
      $range[] = $value ? (new DrupalDateTime($value))->getPhpDateTime() : NULL;
    }

    return $range;
  }

}
