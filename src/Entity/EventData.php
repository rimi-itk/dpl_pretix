<?php

namespace Drupal\dpl_pretix\Entity;

use Drupal\dpl_pretix\Exception\InvalidPropertyException;
use Drupal\dpl_pretix\Settings;
use Drupal\recurring_events\EventInterface;
use Safe\Exceptions\JsonException;
use function \Safe\json_decode;
use function \Safe\sprintf;

/**
 * Event data for event series and event instance.
 */
final class EventData implements \JsonSerializable {
  /**
   * The entity type.
   */
  public ?string $entityType = NULL;

  /**
   * The entity id.
   */
  public ?string $entityId = NULL;

  /**
   * The maintain copy.
   */
  public ?bool $maintainCopy = NULL;

  /**
   * The PSP element.
   */
  public ?string $pspElement = NULL;

  /**
   * The ticket type.
   */
  public ?string $templateEvent = NULL;

  /**
   * The pretix URL.
   */
  public ?string $pretixUrl = NULL;

  /**
   * The pretix organizer short form.
   */
  public ?string $pretixOrganizer = NULL;

  /**
   * The pretix event short form.
   */
  public ?string $pretixEvent = NULL;

  /**
   * The pretix sub-event (date) id.
   */
  public ?int $pretixSubeventId = NULL;

  /**
   * The data.
   *
   * @var array<string, mixed>
   */
  public ?array $data = NULL;

  /**
   * Create event data instance.
   */
  public static function createFromEvent(EventInterface $event): static {
    return static::createFromDatabaseRow((object) [
      'entity_type' => $event->getEntityTypeId(),
      'entity_id' => $event->id(),
    ]);
  }

  /**
   * Create event data instance.
   *
   * @throws \Drupal\dpl_pretix\Exception\InvalidPropertyException
   */
  public static function createFromDatabaseRow(object $row): static {
    $data = new static();

    if (!isset($row->entity_type)) {
      throw new InvalidPropertyException('Entity type is required.');
    }

    $data->entityType = $row->entity_type;
    $data->entityId = $row->entity_id ?? NULL;
    $data->maintainCopy = (bool) ($row->maintain_copy ?? TRUE);
    $data->pspElement = $row->psp_element ?? NULL;
    $data->templateEvent = $row->template_event ?? NULL;
    $data->pretixUrl = $row->pretix_url ?? NULL;
    $data->pretixOrganizer = $row->pretix_organizer ?? NULL;
    $data->pretixEvent = $row->pretix_event ?? NULL;
    $data->pretixSubeventId = $row->pretix_subevent_id ?? NULL;
    try {
      $data->data = json_decode($row->data ?? 'NULL', TRUE);
    }
    catch (JsonException) {
    }

    return $data;
  }

  /**
   * Set value if the current value is not set.
   */
  public function setDefault(string $name, mixed $value): self {
    $name = Settings::kebab2camel($name);
    if (property_exists($this, $name)) {
      // Set property if the current value is null.
      $this->$name ??= $value;
    }

    return $this;
  }

  /**
   * Convert data to array.
   *
   * @return array<string, mixed>
   *   The data.
   */
  public function toArray(): array {
    return [
      'entity_type' => $this->entityType ?? NULL,
      'entity_id' => $this->entityId ?? NULL,
      'maintain_copy' => $this->maintainCopy,
      'psp_element' => $this->pspElement,
      'template_event' => $this->templateEvent,
      'pretix_url' => $this->pretixUrl,
      'pretix_organizer' => $this->pretixOrganizer,
      'pretix_event' => $this->pretixEvent,
      'pretix_subevent_id' => $this->pretixSubeventId,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string|bool|array<string, mixed>|null>
   *   The array.
   */
  public function jsonSerialize(): array {
    return $this->toArray();
  }

  /**
   * Decide if pretix data is set.
   */
  public function hasPretixEvent(): bool {
    return !empty($this->pretixUrl)
      && !empty($this->pretixOrganizer)
      && !empty($this->pretixEvent);
  }

  /**
   * Get pretix admin event URL.
   */
  public function getEventAdminUrl(): ?string {
    if (!$this->hasPretixEvent()) {
      return NULL;
    }

    assert(isset($this->pretixUrl, $this->pretixOrganizer, $this->pretixEvent));
    return sprintf('%s/control/event/%s/%s', rtrim($this->pretixUrl, '/'), urlencode($this->pretixOrganizer),
      urlencode($this->pretixEvent));
  }

  /**
   * Get pretix event shop URL.
   */
  public function getEventShopUrl(): ?string {
    if (!$this->hasPretixEvent()) {
      return NULL;
    }

    assert(isset($this->pretixUrl, $this->pretixOrganizer, $this->pretixEvent));
    $url = sprintf('%s/%s/%s', rtrim($this->pretixUrl, '/'), urlencode($this->pretixOrganizer), urlencode($this->pretixEvent));

    if (isset($this->pretixSubeventId)) {
      $url .= '/' . $this->pretixSubeventId;
    }

    return $url;
  }

}
