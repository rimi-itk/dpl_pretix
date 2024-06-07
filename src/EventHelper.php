<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\dpl_pretix\Pretix\ApiClient\Client as PretixApiClient;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollectionInterface;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\EventInterface;

/**
 * Event helper.
 */
class EventHelper {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  private const TABLE_NAME = 'dpl_pretix';
  private const INSERT = 'insert';
  private const UPDATE = 'update';
  private const DELETE = 'delete';


  /**
   * The pretix API client.
   */
  private PretixApiClient $pretixApiClient;

  public function __construct(
    private readonly Settings $settings,
    private readonly Connection $database,
  ) {
  }

  /**
   * Implements hook_entity_insert().
   */
  public function entityInsert(EntityInterface $entity) {
    if ($entity instanceof EventInterface) {
      $this->syncronizeEvent($entity, self::INSERT);
    }
  }

  /**
   * Implements hook_entity_update().
   */
  public function entityUpdate(EntityInterface $entity) {
    if ($entity instanceof EventInterface) {
      $this->syncronizeEvent($entity, self::UPDATE);
    }
  }

  /**
   * Implements hook_entity_delete().
   */
  public function entityDelete(EntityInterface $entity) {
    if ($entity instanceof EventInterface) {
      $this->syncronizeEvent($entity, self::DELETE);
    }
  }

  /**
   * Syncronize event in pretix.
   */
  private function syncronizeEvent(EventInterface $event, string $action): void {
    if ($event instanceof EventSeries) {
      $data = $this->loadEventData($event);
      if (!empty($data)) {
        // @todo syncronize event
      }
    }
  }

  /**
   * Set event data.
   */
  public function setEventData(EventInterface $event, EventData $data): void {
    $this->saveEventData($event, $data);
    $this->syncronizeEvent($event, $event->isNew() ? self::INSERT : self::UPDATE);
  }

  /**
   * Get event data.
   */
  public function getEventData(EventInterface $event, bool $withDefaults = FALSE): ?EventData {
    $info = $this->loadEventData($event);

    if (NULL !== $info && $withDefaults) {
      $defaults = $this->settings->getEventNodes();

      // Set default value on null values.
      foreach ($defaults as $name => $default) {
        if (property_exists($info, $name)) {
          $info->$name ??= $default;
          ;
        }
      }
    }

    return $info;
  }

  /**
   * Get pretix admin event URL.
   */
  public function getEventAdminUrl(string $event): ?string {
    return $this->pretixClient()->getEventAdminUrl($event);
  }

  /**
   * Decide if event has orders in pretix.
   */
  public function hasOrders(string $event) {
    return count($this->getOrders($event)) > 0;
  }

  /**
   * Get event orders form pretix.
   */
  public function getOrders(string $event): EntityCollectionInterface {
    return [];
  }

  /**
   * Get events from pretix.
   */
  public function getEvents(array $query): EntityCollectionInterface {
    return $this->pretix()->getEvents($query);
  }

  /**
   * Save event data in database.
   */
  private function saveEventData(EventInterface $event, EventData $data): int {
    $values = [
      // 'pretix_url',
      // 'pretix_organizer',
      // 'pretix_event',
      'capacity' => $data['capacity'],
      'maintain_copy' => (bool) $data['maintain_copy'] ? 1 : 0,
      'psp_element' => $data['psp_element'] ?? '',
      'ticket_type' => $data['ticket_type'],
    ];

    return $this->database
      ->merge(self::TABLE_NAME)
      ->keys([
        'entity_type' => $event->getEntityTypeId(),
        'entity_id' => $event->id(),
      ])
      ->fields($values)
      ->execute();
  }

  /**
   * Load event data.
   *
   * @param \Drupal\recurring_events\EventInterface|int|null $event
   *   The event. If null, data for all events will be returned.
   * @param string|null $entityType
   *   The entity type. Required if $event is not an entity.
   *
   * @throws \Exception
   */
  public function loadEventData(EventInterface|int $event = NULL, string $entityType = NULL): array|EventData|null {
    $type = $event instanceof EventInterface ? $event->getEntityTypeId() : $entityType;
    $id = $event instanceof EventInterface ? $event->id() : $event;
    if (NULL !== $event && NULL === $type) {
      throw new \InvalidArgumentException('Missing event type');
    }

    $query = $this->database
      ->select(self::TABLE_NAME, 't')
      ->fields('t');
    if (NULL !== $event) {
      $query
        ->condition('t.entity_type', $type)
        ->condition('t.entity_id', $id);
    }

    $result = $query
      ->execute()
      ->fetchAll(\PDO::FETCH_CLASS, EventData::class);

    return NULL === $event ? $result : (reset($result) ?: NULL);
  }

  /**
   * Ping pretix API.
   */
  public function pingApi() {
    return $this->getEvents([]);
  }

  /**
   * Get pretix API client.
   */
  private function pretix(): PretixApiClient {
    if (!isset($this->pretixApiClient)) {
      $this->pretixApiClient = new PretixApiClient($this->settings->getPretix());
    }

    return $this->pretixApiClient;
  }

}
