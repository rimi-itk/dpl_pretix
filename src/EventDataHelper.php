<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\recurring_events\EventInterface;

/**
 * Event data manager.
 *
 * Manages event and subevent data in Drupal database.
 */
class EventDataHelper {
  use DependencySerializationTrait;

  private const EVENT_TABLE_NAME = 'dpl_pretix_events';

  public function __construct(
    private readonly Settings $settings,
    private readonly Connection $database,
  ) {
  }

  /**
   * Create EventData instance.
   */
  public function createEventData(EventInterface $event, bool $withDefaults = TRUE): EventData {
    $data = EventData::createFromEvent($event);

    return $withDefaults ? $this->setDefaults($data) : $data;
  }

  /**
   * Get event data.
   */
  public function getEventData(EventInterface $event, bool $withDefaults = FALSE): ?EventData {
    $data = $this->loadEventData($event);

    return $withDefaults && NULL !== $data ? $this->setDefaults($data) : $data;
  }

  /**
   * Save event data in database.
   */
  public function saveEventData(EventInterface $event, EventData $data): ?int {
    $values = [
      'maintain_copy' => (int) $data->maintainCopy,
      'psp_element' => $data->pspElement,
      'ticket_type' => $data->ticketType,
      'data' => \Safe\json_encode($data->data),
      'pretix_event' => $data->pretixEvent,
      'pretix_subevent_id' => $data->pretixSubeventId,
    ];
    // Add pretix data if set (and never set null values in database).
    if ($data->hasPretixEvent()) {
      $values += array_filter([
        'pretix_url' => $data->pretixUrl,
        'pretix_organizer' => $data->pretixOrganizer,
      ]);
    }

    return $this->database
      ->merge(self::EVENT_TABLE_NAME)
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
   * @param \Drupal\recurring_events\EventInterface|int $event
   *   The event.
   * @param string|null $entityType
   *   The entity type. Required if $event is not an event entity.
   *
   * @return \Drupal\dpl_pretix\Entity\EventData|null
   *   The event data if any.
   *
   * @throws \Exception
   */
  public function loadEventData(EventInterface|int $event, string $entityType = NULL): ?EventData {
    $list = $this->loadEventDataList($entityType, $event);

    return 1 === count($list) ? reset($list) : NULL;
  }

  /**
   * Load event data list.
   *
   * @param string|null $entityType
   *   The entity type.
   * @param \Drupal\recurring_events\EventInterface|int|null $event
   *   The event (ID).
   *
   * @return array<EventData>
   *   The event data list.
   *
   * @throws \Exception
   */
  public function loadEventDataList(string $entityType = NULL, EventInterface|int $event = NULL): array {
    $entityType = $event instanceof EventInterface ? $event->getEntityTypeId() : $entityType;
    $entityId = $event instanceof EventInterface ? $event->id() : $event;
    if (NULL !== $event && NULL === $entityType) {
      throw new \InvalidArgumentException('Missing event type');
    }

    /** @var \Drupal\Core\Database\Query\SelectInterface $query */
    $query = $this->database
      ->select(self::EVENT_TABLE_NAME, 't')
      ->fields('t');
    if (NULL !== $entityType) {
      $query->condition('t.entity_type', $entityType);
    }
    if (NULL !== $entityId) {
      $query->condition('t.entity_id', $entityId);
    }

    $statement = $query->execute();
    assert(NULL !== $statement);
    $result = $statement->fetchAll();

    return array_map(
      EventData::createFromDatabaseRow(...),
      $result
    );
  }

  /**
   * Detach event data.
   */
  public function detachEventData(EventData $data): bool {
    /** @var \Drupal\Core\Database\Query\Update $query */
    $query = $this->database
      ->update(self::EVENT_TABLE_NAME)
      ->condition('entity_type', $data->entityType)
      ->condition('entity_id', $data->entityId)
      ->fields([
        'pretix_url' => NULL,
        'pretix_organizer' => NULL,
        'pretix_event' => NULL,
      ]);

    return 1 === $query->execute();
  }

  /**
   * Delete event data.
   */
  public function deleteEventData(EventData $data): bool {
    /** @var \Drupal\Core\Database\Query\Delete $query */
    $query = $this->database
      ->delete(self::EVENT_TABLE_NAME)
      ->condition('entity_type', $data->entityType)
      ->condition('entity_id', $data->entityId);

    return 1 === $query->execute();
  }

  /**
   * Set default values on event data.
   */
  private function setDefaults(EventData $data): EventData {
    $defaults = $this->settings->getEventNodes();

    // Set default value on null values.
    foreach ($defaults->toArray() as $name => $default) {
      $data->setDefault($name, $default);
    }

    return $data;
  }

}
