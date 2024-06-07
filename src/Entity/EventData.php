<?php

namespace Drupal\dpl_pretix\Entity;

/**
 * Event data.
 */
final class EventData {
  /**
   * The entity type.
   */
  public string $entityType;

  /**
   * The entity id.
   */
  public string $entityId;

  /**
   * The capacity.
   */
  public int $capacity = 0;

  /**
   * The maintain copy.
   */
  public bool $maintainCopy = false;

  /**
   * The PSP element.
   */
  public ?string $pspElement;

  /**
   * The ticket type.
   */
  public ?string $ticketType;

  /**
   * The pretix URL.
   */
  public ?string $pretixUrl;

  /**
   * The pretix organizer short form.
   */
  public ?string $pretixOrganizer;

  /**
   * The pretix event short forn.
   */
  public ?string $pretixEvent;

  /**
   * Constructor.
   */
  public function __construct(array $data = []) {
    foreach ($data as $name => $value) {
      if (property_exists($this, $name)) {
        $this->$name = $value;
      }
      else {
        $this->__set($name, $value);
      }
    }
  }

  /**
   * Used when fetching with PDO::FETCH_CLASS.
   */
  public function __set(string $name, $value): void {
    // Convert kebab_case to camelCase and set property.
    $name = lcfirst(str_replace('_', '', ucwords($name, '_')));
    if (property_exists($this, $name)) {
      $this->$name = $value;
    }
  }

}
