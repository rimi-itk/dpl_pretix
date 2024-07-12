<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\dpl_pretix\Exception\InvalidEventSeriesException;
use Drupal\dpl_pretix\Pretix\ApiClient\Client as PretixApiClient;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollectionInterface;
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
   * The pretix API client.
   */
  private PretixApiClient $pretixApiClient;

  public function __construct(
    private readonly Settings $settings,
    private readonly EventDataHelper $eventDataHelper,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MessengerInterface $messenger,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      // @see https://drupal.stackexchange.com/a/225627
      drupal_register_shutdown_function($this->postEntityInsert(...), $entity);
    }
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      $this->synchronizeEvent($entity, self::UPDATE);
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity): void {
    if ($entity instanceof EventSeries) {
      $this->synchronizeEvent($entity, self::DELETE);
    }
  }

  /**
   * Syncronize event in pretix.
   */
  public function synchronizeEvent(EventSeries $event, string $action): void {
    if ($event instanceof EventSeries) {
      try {
        $data = $this->eventDataHelper->loadEventData($event) ?? $this->eventDataHelper->createEventData($event);
        if ($data->hasPretixEvent()) {
          // Update event in pretix.
          assert(NULL !== $data->pretixEvent);
          $pretixEvent = $this->pretix()->updateEvent($data->pretixEvent, [
            'name' => ['da' => $event->label()],
            'date_from' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
          ]);
        }
        else {
          // Create event in pretix.
          $pretixEvent = $this->pretix()->createEvent([
            'name' => ['da' => $event->label()],
            'slug' => str_replace(['{id}'], [$event->id()], 'dpl-pretix-{id}'),
            'date_from' => (new DateTimeImmutable())->format(\DateTimeInterface::ATOM),
          ]);
          $settings = $this->settings->getPretixSettings();
          $data->pretixUrl = $settings->url;
          $data->pretixOrganizer = $settings->organizer;
          $data->pretixEvent = $pretixEvent->getSlug();
          $this->eventDataHelper->saveEventData($event, $data);

          $this->setTicketLink($event, save: TRUE);
        }
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
    }
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
   * Get pretix API client.
   */
  private function pretix(): PretixApiClient {
    if (!isset($this->pretixApiClient)) {
      $settings = $this->settings->getPretixSettings();
      $this->pretixApiClient = new PretixApiClient([
        'url' => $settings->url,
        'organizer' => $settings->organizer,
        'api_token' => $settings->apiToken,
      ]);
    }

    return $this->pretixApiClient;
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
          $event->save();
        }
      }
    }
  }

}
