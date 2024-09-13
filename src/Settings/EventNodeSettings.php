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
   * The default ticket category name.
   */
  public ?string $defaultTicketCategoryName = NULL;

}
