<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Pretix\PretixApiClient;
use Drupal\recurring_events\EventInterface;

/**
 *
 */
class EventHelper {
  use StringTranslationTrait;

  private const TABLE_NAME = 'dpl_pretix';

  private PretixApiClient $pretixApiClient;

  public function __construct(
    private readonly Settings $settings,
    private readonly Connection $database
  ) {
  }

  /**
   *
   */
  public function handleEvent(EventInterface $event, array $data): int {
    return $this->saveEventData($event, $data);
  }

  /**
   *
   */
  public function getEventInfo(EventInterface|int $event): ?array {
return $this->loadEventData($event);
  }

  /**
   *
   */
  public function getEventDefaults(EventInterface $entity): ?array {

    return [];
  }

  public function getEventAdminUrl(string $event): ?string
  {
    return $this->pretixClient()->getEventAdminUrl($event);
  }

    /**
   *
   */
  public function hasOrders(string $event) {
    return count($this->getOrders($event)) > 0;
  }

  public function getOrders(string $event): array {
    return [];
  }

  public function getEvents(array $query): array {
    return $this->pretix()->getEvents($query);
  }

  public function loadEventData(EventInterface|int $event = null, string $entityType = null): ?array
  {
    $type = $event instanceof EventInterface ? $event->getEntityTypeId() : $entityType;
    $id = $event instanceof EventInterface ? $event->id() : $event;
    if (null !== $event && null === $type) {
      throw new \InvalidArgumentException('Missing event type');
    }

    $query = $this->database
      ->select(self::TABLE_NAME, 't')
      ->fields('t');
    if (null !== $event) {
      $query
        ->condition('t.entity_type', $type)
        ->condition('t.entity_id', $id);
    }

    $result = $query
      ->execute()
      ->fetchAll();

    $result = array_map(
      static fn(object $row) => (array)$row,
      $result
    );

      return null === $event ? $result : (reset($result) ?: null);
  }

  public function saveEventData(EventInterface $event, array $data): int
  {
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

  public function pingApi()
  {
    return $this->getEvents([]);
  }

  private function pretix(): PretixApiClient {
    if (!isset($this->pretixApiClient)) {
      $this->pretixApiClient = new PretixApiClient($this->settings->getPretix());
    }

    return $this->pretixApiClient;
  }
}
