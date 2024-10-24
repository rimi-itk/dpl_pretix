<?php

namespace Drupal\dpl_pretix;

use Drupal\Component\Utility\Random;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\dpl_pretix\Exception\SynchronizeException;
use Drupal\dpl_pretix\Pretix\ApiClient\Client as PretixApiClient;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollectionInterface;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\AbstractEntity as PretixEntity;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event as PretixEvent;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Item as PretixItem;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent as PretixSubEvent;
use Drupal\recurring_events\Entity\EventInstance;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventInterface;
use Drupal\recurring_events\EventSeriesStorageInterface;
use Psr\Log\LoggerInterface;
use Safe\DateTimeImmutable;
use function Safe\sprintf;

/**
 * Entity helper.
 */
final class EntityHelper {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * A random object.
   *
   * @var \Drupal\Component\Utility\Random
   */
  private Random $random;

  // @see /admin/structure/events/instance/types/eventinstance_type/default/edit/fields
  private const EVENT_TICKET_LINK_FIELD = 'field_event_link';

  private const ITEM_PRICE_OVERRIDES = 'item_price_overrides';
  private const VARIATION_PRICE_OVERRIDES = 'variation_price_overrides';

  /**
   * The event series storage.
   *
   * @var \Drupal\recurring_events\EventSeriesStorageInterface
   */
  private EventSeriesStorageInterface $eventSeriesStorage;

  public function __construct(
    private readonly Settings $settings,
    private readonly EventDataHelper $eventDataHelper,
    private readonly PretixHelper $pretixHelper,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    /** @var \Drupal\recurring_events\EventSeriesStorageInterface $eventSeriesStorage */
    $eventSeriesStorage = $entityTypeManager->getStorage('eventseries');
    $this->eventSeriesStorage = $eventSeriesStorage;
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity): void {
    // The entity has already been saved and has an id.
    $this->entityUpdate($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      $this->synchronizeEvent($entity);
    }
    elseif ($entity instanceof EventInstance) {
      // Check if the event series's instances have not yet been build (cf.
      // $this->eventsWithPendingInstances).
      $series = $this->getEventSeries($entity);
      if (!$this->eventHasPendingInstances($series)) {
        $this->entityUpdate($series);
      }
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      $this->deleteEvent($entity);
    }
    elseif ($entity instanceof EventInstance) {
      // Check if the event series's instances are being rebuilt (deleted and
      // created) (cf. $this->eventsWithPendingInstances).
      $series = $this->getEventSeries($entity);
      if (!$this->eventHasPendingInstances($series)) {
        $this->deleteEventInstance($entity);
      }
    }
  }

  /**
   * Synchronize event in pretix.
   */
  public function synchronizeEvent(EventSeries $event, bool $force = FALSE): ?PretixEvent {
    $synchronized = $this->getProcessedEntity($event);
    if (!$force && $synchronized instanceof PretixEvent) {
      return $synchronized;
    }

    // Get a fresh copy of the event with updated instance data.
    $eventId = $event->id();
    $this->eventSeriesStorage->resetCache([$eventId]);
    $event = $this->eventSeriesStorage->load($eventId);
    if (!($event instanceof EventSeries)) {
      throw new SynchronizeException(sprintf('Cannot load event series %s', $eventId));
    }

    try {
      $data = $this->getEventData($event);
      if (!$data->maintainCopy) {
        return NULL;
      }

      $templateEvent = $data->templateEvent;
      if (NULL === $templateEvent) {
        throw new SynchronizeException('Template event not set');
      }

      $isNew = NULL === $data->pretixEvent;
      /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event $pretixEvent */
      $pretixEvent = $isNew
        ? $this->createEvent($event, $templateEvent, $data)
        : $this->updateEvent($event, $templateEvent, $data);

      $this->setEventLive($event, $pretixEvent, $data);
      $this->setProcessedEntity($event, $pretixEvent);
      $this->setTicketUrl($event, $data);

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
    }

    return NULL;
  }

  /**
   * Get event data and ensure that it has been persisted.
   */
  private function getEventData(EventInterface $event): EventData {
    $data = $this->eventDataHelper->loadEventData($event)
      ?? $this->eventDataHelper->createEventData($event);
    static::applyFormValues($data, $event);

    if ($event instanceof EventInstance) {
      // Copy form values from series.
      $seriesEventData = $this->getEventData($this->getEventSeries($event));
      $seriesEventData->setFormValues($seriesEventData->getFormValues() ?? []);
    }
    $this->eventDataHelper->saveEventData($event, $data);

    return $data;
  }

  /**
   * Create event in pretix.
   */
  public function createEvent(EventSeries $event, string $templateEvent, EventData $data): ?PretixEvent {
    $settings = $this->settings->getPretixSettings();

    $this->logger->info('Creating event @event in pretix', [
      '@event' => $event->id(),
    ]);

    $pretix = $this->pretixHelper->client();
    $pretixTemplateEvent = $pretix->getEvent($templateEvent);

    // Create event in pretix (by cloning the template event).
    // @see https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-(event)-clone-
    $pretixEvent = $pretix->cloneEvent(
      $pretixTemplateEvent->getSlug(),
      $this->getPretixEventData($event, $data, [
        // We cannot set the event live on create
        // (cf. https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-).
        'slug' => $this->getPretixEventSlug($event),
        // The API documentation claims that `has_subevents` is copied when cloning, but that doesn't seem to be correct (cf. https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-(event)-clone-).
        PretixHelper::EVENT_HAS_SUBEVENTS => $pretixTemplateEvent->hasSubevents(),
        'testmode' => FALSE,
      ])
    );

    $this->updateProductPrices($event, $pretixEvent, $data);

    $data->setEvent($pretixEvent->toArray());
    $data->pretixUrl = $settings->url;
    $data->pretixOrganizer = $settings->organizer;
    $data->pretixEvent = $pretixEvent->getSlug();
    $this->eventDataHelper->saveEventData($event, $data);

    $this->messenger->addStatus($this->t('Event <a href=":event_url">@event</a> created in pretix', [
      ':event_url' => $data->getEventAdminUrl(),
      '@event' => $event->label(),
    ]));

    $this->synchronizeEventInstances($templateEvent, $pretixEvent, $event);

    return $pretixEvent;
  }

  /**
   * Update event in pretix.
   */
  public function updateEvent(EventSeries $event, string $templateEvent, EventData $data): ?PretixEvent {
    $this->logger->info('Updating event @event in pretix', [
      '@event' => $event->id(),
    ]);

    assert(NULL !== $data->pretixEvent);
    $this->synchronizeEventInstances($templateEvent, $data->pretixEvent, $event);
    $pretixEvent = $this->pretixHelper->client()->updateEvent(
      $data->pretixEvent,
      $this->getPretixEventData($event, $data)
    );

    $this->updateProductPrices($event, $pretixEvent, $data);

    $this->eventDataHelper->saveEventData($event, $data);

    $this->messenger->addStatus($this->t('Event <a href=":event_url">@event</a> updated in pretix', [
      ':event_url' => $data->getEventAdminUrl(),
      '@event' => $event->label(),
    ]));

    return $pretixEvent;
  }

  /**
   * Update product prices.
   */
  private function updateProductPrices(EventSeries $event, PretixEvent $pretixEvent, EventData $data): void {
    $pretix = $this->pretixHelper->client();

    $price = $this->getPrice($event);
    $products = $pretix->getItems($pretixEvent);
    $productsData = [];
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Item $product */
    foreach ($products as $product) {
      $productDatum = $product->toArray();
      $defaultPrice = $productDatum['default_price'];
      if ($price !== $defaultPrice) {
        $pretix->updateItem($pretixEvent, $product, [
          'default_price' => $price,
        ]);
      }

      $productsData[] = $productDatum;
    }
    $data->setProducts($productsData);

    if ($this->pretixHelper->isSingularEvent($pretixEvent->toArray())) {
      $capacity = $this->getCapacity($event);
      // Set capacity (size) on all quotas.
      $quotas = $pretix->getQuotas($pretixEvent->getSlug());
      foreach ($quotas as $quota) {
        $pretix->updateQuota($pretixEvent->getSlug(), $quota->getId(), [
          'size' => $capacity,
        ]);
      }
    }
  }

  /**
   * Synchronize event instances.
   */
  private function synchronizeEventInstances(string $templateEvent, PretixEvent|string $pretixEvent, EventSeries $event): array {
    $eventData = $this->getEventData($event);
    if ($this->pretixHelper->isSingularEvent($eventData->getEvent())) {
      return [];
    }

    $this->logger->info('Synchronizing sub-events for @event', [
      '@event' => $event->id(),
    ]);

    $instances = $this->getEventInstances($event);
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent[] $results */
    $results = [];
    foreach ($instances as $instance) {
      $results[] = $this->synchronizeEventInstance($instance, $templateEvent, $pretixEvent);
    }

    // Delete pretix sub-events that no longer exist in Drupal.
    $subEventIds = array_map(static fn (PretixSubEvent $subEvent) => $subEvent->getId(), $results);
    try {
      $subEvents = $this->pretix()->getSubEvents($pretixEvent);
      foreach ($subEvents as $subEvent) {
        if (!in_array($subEvent->getId(), $subEventIds, TRUE)) {
          $this->pretix()->deleteSubEvent($pretixEvent, $subEvent);
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
   */
  private function synchronizeEventInstance(EventInstance $instance, string $templateEvent, PretixEvent|string $pretixSubEvent): PretixSubEvent {
    $synchronized = $this->getProcessedEntity($instance);
    if ($synchronized instanceof PretixSubEvent) {
      return $synchronized;
    }

    $data = $this->getEventData($instance);

    $isNew = NULL === $data->pretixSubeventId;
    $pretixSubEvent = $isNew
      ? $this->createEventInstance($instance, $templateEvent, $pretixSubEvent, $data)
      : $this->updateEventInstance($instance, $pretixSubEvent, $data);

    $this->setProcessedEntity($instance, $pretixSubEvent);
    $this->setTicketUrl($instance, $data);

    return $pretixSubEvent;
  }

  /**
   * Synchronize event instance.
   */
  private function createEventInstance(EventInstance $instance, string $templateEvent, PretixEvent|string $pretixEvent, EventData $instanceData): PretixSubEvent {
    $pretix = $this->pretix();

    try {
      $templateSubEvents = $pretix->getSubEvents($templateEvent);
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot get sub-events for template event @event',
        [
          '@event' => $templateEvent,
        ]), $exception);
    }
    if ($templateSubEvents->isEmpty()) {
      throw $this->pretixException($this->t('Template event @event has no sub-events',
        [
          '@event' => $templateEvent,
        ]));
    }
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $templateSubEvent */
    $templateSubEvent = $templateSubEvents->first();

    // Get first product (item) from template event.
    try {
      $items = $pretix->getItems($pretixEvent);
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot get items for template event @event',
        [
          '@event' => $templateEvent,
        ]), $exception);
    }
    if ($items->isEmpty()) {
      throw $this->pretixException($this->t('Template event @event has no items',
        [
          '@event' => $templateEvent,
        ]));
    }

    // Always use the first product.
    /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Item $product */
    $product = $items->first();

    $data = $this->getSubEventData($instance, $instanceData, $product)
      + $templateSubEvent->toArray();
    // Remove the template id.
    unset($data['id']);

    try {
      $subEvent = $pretix->createSubEvent($pretixEvent, $data);
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot create sub-event for event @event',
        [
          '@event' => is_string($pretixEvent) ? $pretixEvent : $pretixEvent->getSlug(),
        ]), $exception);
    }

    // Store the sub-event data for future updates.
    $instanceData->setSubEvent($data);
    $instanceData->setProduct($product->toArray());

    // Get sub-event quotas.
    try {
      $quotas = $pretix->getQuotas(
        $pretixEvent,
        ['query' => ['subevent' => $subEvent->getId()]]
      );
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot get quotas for sub-event @sub_event',
        [
          '@sub_event' => $subEvent->getId(),
        ]), $exception);
    }

    if ($quotas->isEmpty()) {
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

      // https://docs.pretix.eu/en/latest/api/resources/quotas.html#resource-description
      $quotaData = array_merge($quotaData, [
        'subevent' => $subEvent->getId(),
        'items' => [$product->getId()],
        'size' => $this->getCapacity($instance),
      ]);

      // Check if quota uses variants.
      if (!empty($quotaData['variations'])) {
        $productData = $product->toArray();
        if (!isset($productData['variations'][0]['id'])) {
          throw $this->pretixException($this->t('Cannot create quota for sub-event @sub_event on event @event; product @product has no variations',
            [
              '@sub_event' => $subEvent->getId(),
              '@event' => is_string($pretixEvent) ? $pretixEvent : $pretixEvent->getSlug(),
              '@product' => reset($productData['name']) ?: $product->getId(),
            ])
          );
        }

        $quotaData['variations'] = [$productData['variations'][0]['id']];
      }

      try {
        /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Quota $quota */
        $quota = $pretix->createQuota($pretixEvent, $quotaData);
        $instanceData->setQuota($quota->toArray());
      }
      catch (\Exception $exception) {
        throw $this->pretixException($this->t('Cannot create quota for sub-event @sub_event on event @event',
          [
            '@sub_event' => $subEvent->getId(),
            '@event' => is_string($pretixEvent) ? $pretixEvent : $pretixEvent->getSlug(),
          ]), $exception);
      }
    }

    $settings = $this->settings->getPretixSettings();
    $instanceData->pretixEvent = is_string($pretixEvent) ? $pretixEvent : $pretixEvent->getSlug();
    $instanceData->pretixSubeventId = $subEvent->getId();
    $instanceData->pretixUrl = $settings->url;
    $instanceData->pretixOrganizer = $settings->organizer;
    $this->eventDataHelper->saveEventData($instance, $instanceData);

    return $subEvent;
  }

  /**
   * Synchronize event instance.
   */
  private function updateEventInstance(EventInstance $instance, PretixEvent|string $pretixEvent, EventData $instanceData): PretixSubEvent {
    $pretix = $this->pretix();
    $subEventId = $instanceData->pretixSubeventId;
    $quotaData = $instanceData->getQuota() ?? [];

    if (empty($quotaData)) {
      throw $this->pretixException($this->t('Cannot get quota data for updating sub-event @sub_event on event @event',
        [
          '@sub_event' => $subEventId,
          '@event' => $instance->getEventSeries()->id(),
        ]));
    }
    try {
      $data = $this->getSubEventData($instance, $instanceData);
      /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $subEvent */
      // @phpstan-ignore argument.type (the type hints in https://github.com/itk-dev/pretix-api-client-php/ are f… up)
      $subEvent = $pretix->updateSubEvent($pretixEvent, $subEventId, $data);
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot update sub-event @sub_event on event @event',
        [
          '@sub_event' => $subEventId,
          '@event' => $instance->getEventSeries()->id(),
        ]), $exception);
    }

    $quotaData['size'] = $this->getCapacity($instance);
    try {
      /** @var \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Quota $quota */
      $quota = $pretix->updateQuota($pretixEvent, $quotaData['id'], $quotaData);
      $instanceData->setQuota($quota->toArray());
    }
    catch (\Exception $exception) {
      throw $this->pretixException($this->t('Cannot update quota for sub-event'),
        $exception);
    }

    $this->eventDataHelper->saveEventData($instance, $instanceData);

    return $subEvent;
  }

  /**
   * Get sub-event data.
   */
  private function getSubEventData(EventInstance $instance, EventData $instanceData, ?PretixItem $product = NULL): array {
    $range = $this->getDateRange($instance);

    $data = array_merge(
      $instanceData->getSubEvent() ?? [],
      [
        'name' => $this->getEventName($instance),
        'date_from' => $this->pretixHelper->formatDate($range[0]),
        'date_to' => $this->pretixHelper->formatDate($range[1]),
        'location' => $this->getLocation($instance),
        'frontpage_text' => NULL,
        'active' => TRUE,
        'is_public' => TRUE,
        'date_admission' => NULL,
        'presale_end' => NULL,
        'seating_plan' => NULL,
        'seat_category_mapping' => (object) [],
      ]
    );

    // @todo De we really need to override prices on products?
    $productData = $product instanceof PretixItem ? $product->toArray() : $instanceData->getProduct();
    $price = $this->getPrice($instance);

    // https://docs.pretix.eu/en/latest/api/resources/subevents.html#resource-description
    $data[self::ITEM_PRICE_OVERRIDES] = [];
    $data[self::VARIATION_PRICE_OVERRIDES] = [];

    if ($productData['has_variations'] ?? FALSE) {
      /** @var array<string, mixed> $variation */
      $variation = reset($productData['variations']);
      if (isset($variation['id'])) {
        $data[self::VARIATION_PRICE_OVERRIDES][] = [
          'variation' => $variation['id'],
          'price' => $price,
        ];
      }
    }

    // Important: meta_data value must be an object!
    $data['meta_data'] = (object) ($data['meta_data'] ?? []);

    return $data;
  }

  /**
   * Delete event in pretix.
   */
  public function deleteEvent(EventSeries $event): bool {
    $synchronized = $this->getProcessedEntity($event);
    if (is_bool($synchronized)) {
      return $synchronized;
    }

    try {
      $data = $this->eventDataHelper->loadEventData($event);

      if (NULL !== $data?->pretixEvent) {
        $this->pretix()->deleteEvent($data->pretixEvent);

        return TRUE;
      }
    }
    catch (\Throwable $t) {
      $this->messenger->addError($this->t('Error deleting @event in pretix: @message', [
        '@event' => sprintf('%s:%s', $event->getEntityTypeId(), $event->id()),
        '@message' => $t->getMessage(),
      ]));
      $this->logger->error('Error deleting @event in pretix: @message', [
        '@event' => sprintf('%s:%s', $event->getEntityTypeId(), $event->id()),
        '@message' => $t->getMessage(),
        '@throwable' => $t,
      ]);
    }

    return FALSE;
  }

  /**
   * Delete event instance in pretix.
   */
  public function deleteEventInstance(EventInstance $instance): bool {
    $synchronized = $this->getProcessedEntity($instance);
    if (is_bool($synchronized)) {
      return $synchronized;
    }

    try {
      $data = $this->eventDataHelper->loadEventData($instance);

      if (NULL !== $data && isset($data->pretixEvent, $data->pretixSubeventId)) {
        // @phpstan-ignore argument.type (the type hints in https://github.com/itk-dev/pretix-api-client-php/ are f… up)
        $this->pretix()->deleteSubEvent($data->pretixEvent, $data->pretixSubeventId);

        $result = TRUE;
        $this->setProcessedEntity($instance, $result);

        return $result;
      }
    }
    catch (\Throwable $t) {
      try {
        $event = $this->getEventSeries($instance);
      }
      catch (\Throwable) {
      }
      $this->messenger->addError($this->t('Error deleting pretix sub-event @sub_event from event @event: @message', [
        '@sub_event' => isset($data) ? $this->pretixHelper->getPretixName($data->getSubEvent()) : '👻',
        '@event' => isset($event) ? sprintf('%s:%s', $event->getEntityTypeId(), $event->id()) : '👻',
        '@message' => $t->getMessage(),
      ]));
      $this->logger->error('Error deleting pretix sub-event @sub_event from event @event: @message', [
        '@sub_event' => isset($data) ? $this->pretixHelper->getPretixName($data->getSubEvent()) : '👻',
        '@event' => isset($event) ? sprintf('%s:%s', $event->getEntityTypeId(), $event->id()) : '👻',
        '@message' => $t->getMessage(),
        '@throwable' => $t,
      ]);
    }

    return FALSE;
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
    $this->synchronizeEvent($event);
  }

  /**
   * Decide if event has orders in pretix.
   */
  public function hasOrders(string $event): bool {
    try {
      return !$this->getOrders($event)->isEmpty();
    }
    catch (\Exception) {
      return FALSE;
    }
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
   * Get pretix event data.
   *
   * @param \Drupal\recurring_events\Entity\EventSeries $event
   *   The event.
   * @param \Drupal\dpl_pretix\Entity\EventData $eventData
   *   The event data.
   * @param array<string, mixed> $data
   *   The current data, if any.
   *
   * @see https://docs.pretix.eu/en/latest/api/resources/events.html#resource-description
   */
  private function getPretixEventData(EventSeries $event, EventData $eventData, array $data = []): array {
    $instances = $this->getEventInstances($event);
    $firstInstance = reset($instances) ?: NULL;
    $lastInstance = end($instances) ?: NULL;
    $dateFrom = NULL !== $firstInstance ? $this->getDateRange($firstInstance)[0] : NULL;
    $dateTo = NULL !== $lastInstance ? $this->getDateRange($lastInstance)[1] : NULL;

    $settings = $this->settings->getPspElements();
    if (!empty($settings->pretixPspMetaKey)) {
      if (!empty($eventData->pspElement)) {
        $data['meta_data'][$settings->pretixPspMetaKey] = $eventData->pspElement;
      }
    }
    $data += [
      'name' => $this->getEventName($event),
      'date_from' => $this->pretixHelper->formatDate($dateFrom),
      'date_to' => $this->pretixHelper->formatDate($dateTo),
      'is_public' => $event->isPublished(),
      'location' => $this->getLocation($event),
    ];

    // date_from must be set (cf. https://docs.pretix.eu/en/latest/api/resources/events.html#resource-description)
    $data['date_from'] ??= $eventData->getEvent()['date_from']
      ?? $this->pretixHelper->formatDate(new DateTimeImmutable());

    // Important: meta_data value must be an object!
    $data['meta_data'] = (object) ($data['meta_data'] ?? []);

    return $data;
  }

  /**
   * Get pretix event short name.
   */
  private function getPretixEventSlug(EventSeries $event): string {
    if (!isset($this->random)) {
      $this->random = new Random();
    }

    $pretixSettings = $this->settings->getPretixSettings();

    $replacements = [
      '{id}' => $event->id(),
      '{random}' => $this->random->machineName(8, TRUE),
    ];

    return str_replace(array_keys($replacements), $replacements, $pretixSettings->eventSlugTemplate);
  }

  /**
   * Get pretix API client.
   */
  private function pretix(): PretixApiClient {
    return $this->pretixHelper->client();
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
  protected function pretixException(string $message, ?\Exception $exception = NULL): SynchronizeException {
    if (NULL === $exception) {
      $this->logger->error($message);
    }
    else {
      $this->logException($exception, $message);
    }

    return new SynchronizeException($message, 0, $exception);
  }

  /**
   * Get event name.
   */
  private function getEventName(EventSeries|EventInstance $event): array {
    $label = $event->label();
    if (empty($label) && $event instanceof EventInstance) {
      $label = $this->getEventSeries($event)->label();
    }

    if (empty($label)) {
      throw new SynchronizeException(sprintf(
        'Cannot get label for %s %s (%s)',
        $event->getEntityTypeId(),
        $event->label(),
        $event->id(),
      ));
    }

    return [
      $this->getDefaultLanguageCode($event) => $label,
    ];
  }

  /**
   * Get location.
   */
  private function getLocation(EventSeries|EventInstance $event): array {
    $fieldName = 'field_event_address';
    /** @var ?\Drupal\address\Plugin\Field\FieldType\AddressItem $address */
    $address = $event->get($fieldName)->first();
    $place = $event->get('field_event_place')->first()?->getString();

    if (empty($address)) {
      $branches = $event->get('field_branch')->referencedEntities();
      if (!empty($branches)) {
        /** @var \Drupal\node\NodeInterface $branch */
        $branch = reset($branches);
        /** @var ?\Drupal\address\Plugin\Field\FieldType\AddressItem $address */
        $address = $branch->get('field_address')->first();
      }

      if (empty($address) && $event instanceof EventInstance) {
        return $this->getLocation($this->getEventSeries($event));
      }
    }

    return [
      $this->getDefaultLanguageCode($event) => empty($address)
        ? ''
        : implode(PHP_EOL, array_filter(array_map(
            'trim',
            [
              $place ?? '',
              $address->getAddressLine1(),
              $address->getAddressLine2(),
              $address->getPostalCode() . ' ' . $address->getLocality(),
            ]
          ))),
    ];
  }

  /**
   * Get event capacity.
   */
  private function getCapacity(EventSeries|EventInstance $event): ?int {
    $fieldName = FormHelper::FIELD_TICKET_CAPACITY;

    $capacity = $event->get($fieldName)->getString();
    // We cannot use `empty` here since 0 is a valid capacity.
    if ('' === $capacity && $event instanceof EventInstance) {
      $capacity = $this->getEventSeries($event)->get($fieldName)->getString();
    }

    $capacity = (int) $capacity;

    return $capacity > 0 ? $capacity : NULL;
  }

  /**
   * Get default language code for an event.
   */
  private function getDefaultLanguageCode(EventInterface $event): string {
    return $this->settings->getPretixSettings()->defaultLanguageCode ?? 'en';
  }

  /**
   * Get location.
   */
  private function getPrice(EventSeries|EventInstance $event): string {
    if ($event instanceof EventInstance) {
      return $this->getPrice($this->getEventSeries($event));
    }

    $fieldName = FormHelper::FIELD_TICKET_CATEGORIES;
    $priceFieldName = 'field_ticket_category_price';

    $price = 0.00;
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $field */
    $field = $event->get($fieldName);
    $categories = $field->referencedEntities();
    /** @var \Drupal\paragraphs\Entity\Paragraph $category */
    foreach ($categories as $category) {
      $price = (float) $category->get($priceFieldName)->getString();
      // We support only one price.
      break;
    }

    return $this->pretixHelper->formatAmount($price);
  }

  /**
   * Get instance date range.
   *
   * @return array<?\DateTime>
   *   The start and end date.
   */
  private function getDateRange(EventInstance $instance): array {
    $range = [];

    /** @var \Drupal\datetime_range\Plugin\Field\FieldType\DateRangeItem $date */
    $date = $instance->get('date')->first();

    foreach (['start_date', 'end_date'] as $key) {
      /** @var ?\Drupal\Core\Datetime\DrupalDateTime $value */
      $value = $date->get($key)->getValue();
      $range[] = $value?->getPhpDateTime();
    }

    return $range;
  }

  /**
   * The temporary event form values.
   *
   * @var array<string, mixed>
   */
  private static array $formValues = [];

  /**
   * Set event form values needed for updating events in pretix.
   *
   * @param \Drupal\recurring_events\EventInterface $event
   *   The event.
   * @param array<string, mixed> $values
   *   The form values.
   */
  public static function setFormValues(EventInterface $event, array $values): void {
    static::$formValues[$event->getEntityTypeId() . ':' . ($event->id() ?? 'new')] = $values;
  }

  /**
   * Get form values.
   */
  private static function getFormValues(EventInterface $event): ?array {
    // Check for form values for the specific event.
    $values = static::$formValues[$event->getEntityTypeId() . ':' . $event->id()]
      // Or a new event.
      ?? static::$formValues[$event->getEntityTypeId() . ':new']
      ?? NULL;

    // @todo Should we reset values immediately after reading?
    // unset(static::$formValues[$key]);.
    return $values;
  }

  /**
   * Apply form values to event data.
   */
  private static function applyFormValues(EventData $data, EventInterface $event): void {
    if ($values = static::getFormValues($event)) {
      $data->maintainCopy = (bool) ($values[FormHelper::ELEMENT_MAINTAIN_COPY] ?? FALSE);
      $data->templateEvent = $values[FormHelper::ELEMENT_TEMPLATE_EVENT] ?? '';
      $data->pspElement = $values[FormHelper::ELEMENT_PSP_ELEMENT] ?? NULL;
      $data->setFormValues(array_merge(
        $data->getFormValues() ?? [],
        $values[FormHelper::CUSTOM_FORM_VALUES] ?? []
      ));
    }
  }

  /**
   * Used to keep track of which entities have already been processed.
   *
   * This is used for two purposes:
   *
   * 1. We update ticket URLs on event series and instances and hence save
   *    entities during Hook_entity_insert and update.
   * 2. Apparently, hook_entity_delete is called more than once (twice) and we
   *    can only delete stuff in pretix once.
   *
   * @var array<string, PretixEvent|PretixSubEvent|bool>
   */
  private static array $processedEntities = [];

  /**
   * Get data for a processed entity, if any.
   */
  private function getProcessedEntity(EventInterface $event): null|PretixEvent|PretixSubEvent|bool {
    return static::$processedEntities[$event->getEntityTypeId() . ':' . $event->id()] ?? NULL;
  }

  /**
   * Set data for a processed entity.
   */
  private function setProcessedEntity(EventInterface $event, PretixEvent|PretixSubEvent|bool $data): PretixEntity|bool {
    return static::$processedEntities[$event->getEntityTypeId() . ':' . $event->id()] = $data;
  }

  /**
   * Set event live(ness) in pretix.
   */
  private function setEventLive(EventSeries $event, PretixEvent|string $pretixEvent, EventData $data, ?bool $live = NULL): void {
    $live ??= $event->isPublished();
    $instances = $this->getEventInstances($event);

    $pretixEventData = $pretixEvent instanceof PretixEvent ? $pretixEvent->toArray() : $pretixEvent;
    if ($live && empty($instances) && !$this->pretixHelper->isSingularEvent($pretixEventData)) {
      $this->messenger->addWarning($this->t('At least one event instance is required to set <a href=":pretix_event_url">@event</a> live in pretix', [
        ':pretix_event_url' => $data->getEventAdminUrl(),
        '@event' => $event->label(),
      ]));
      return;
    }
    try {
      $this->pretix()->updateEvent($pretixEvent, [
        'live' => $live,
      ]);

      $this->messenger->addStatus(
        $live
          ? $this->t('Event <a href=":event_url">@event</a> set live in pretix', [
            ':event_url' => $data->getEventAdminUrl(),
            '@event' => $event->label(),
          ])
          : $this->t('Event <a href=":event_url">@event</a> set not live in pretix', [
            ':event_url' => $data->getEventAdminUrl(),
            '@event' => $event->label(),
          ])
      );
    }
    catch (\Exception $exception) {
      throw $live
        ? $this->pretixException($this->t('Error setting @event live in pretix: @message',
          [
            '@event' => $event->label(),
            '@message' => $exception->getMessage(),
          ]), $exception)
        : $this->pretixException($this->t('Error setting @event not live in pretix: @message',
          [
            '@event' => $event->label(),
            '@message' => $exception->getMessage(),
          ]), $exception);
    }
  }

  /**
   * Set the ticket URL on new events series and instances.
   *
   * Important: must be called after the call to `setEntitySynchronized` to
   * prevent an infinite loop.
   *
   * @see self::setProcessedEntity()
   */
  private function setTicketUrl(EventSeries|EventInstance $event, EventData $data): void {
    $url = $data->getEventShopUrl();
    if ($url && $url !== $event->get(self::EVENT_TICKET_LINK_FIELD)
      ->getString()) {
      $event->set(self::EVENT_TICKET_LINK_FIELD, $url);
      $event->save();
    }
  }

  /**
   * Get event series for an instance.
   *
   * This is basically just a wrapper to get a proper type on
   * EventInstance::getEventSeries().
   *
   * @see EventInstance::getEventSeries();
   */
  private function getEventSeries(EventInstance $instance): EventSeries {
    $series = $instance->getEventSeries();

    if (!($series instanceof EventSeries)) {
      throw new \RuntimeException(sprintf('Cannot get event series for %s (#%s)', $instance->label(), $instance->id()));
    }
    return $series;
  }

  /**
   * List of events that are not yet ready for a complete export to pretix.
   *
   * @var \Drupal\recurring_events\Entity\EventSeries[]
   */
  private array $eventsWithPendingInstances = [];

  /**
   * Tell that event has pending instances.
   */
  private function setEventHasPendingInstances(EventSeries $event): void {
    $this->eventsWithPendingInstances[$event->getEntityTypeId() . ':' . $event->id()] = $event;
  }

  /**
   * Check if event has pending instances.
   */
  private function eventHasPendingInstances(EventSeries $event): bool {
    return isset($this->eventsWithPendingInstances[$event->getEntityTypeId() . ':' . $event->id()]);
  }

  /**
   * Implements hook_recurring_events_event_instances_pre_create_alter().
   *
   * @param array<string, mixed> $events_to_create
   *   The events to create.
   * @param \Drupal\recurring_events\Entity\EventSeries $event
   *   The event.
   *
   * @see \Drupal\recurring_events\EventCreationService::createInstances()
   */
  public function recurringEventsEventInstancesPreCreateAlter(array $events_to_create, EventSeries $event): array {
    $this->setEventHasPendingInstances($event);

    return $events_to_create;
  }

}
