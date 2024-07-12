<?php

namespace Drupal\dpl_pretix\Settings;

/**
 * Event node settings.
 */
class EventNodeSettings extends AbstractSettings {
  /**
   * The capacity.
   */
  public ?int $capacity = NULL;

  /**
   * Maintain copy?
   */
  public ?bool $maintainCopy = NULL;

  /**
   * The ticket type.
   */
  public ?string $ticketType = NULL;

}
