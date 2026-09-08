<?php

namespace Drupal\dpl_pretix\Settings;

/**
 * Event form settings.
 */
class EventFormSettings extends AbstractSettings {
  public const string LOCATION_TOP = 'top';
  public const string LOCATION_BOTTOM = 'bottom';
  public const string LOCATION_BEFORE_PREFIX = 'before:';

  public const string FIELD_RELEVANT_TICKET_MANAGER = 'field_relevant_ticket_manager';

  /**
   * The location.
   *
   * - top
   * - bottom
   * - before:«element»
   */
  public ?string $location = NULL;

  /**
   * The weight.
   *
   * Replaced by $location.
   */
  public ?int $weight = NULL;

  /**
   * Whether to disable field_relevant_ticket_manager.
   */
  public bool $disableFieldRelevantTicketManager = FALSE;

  /**
   * Roles that can delete event instances.
   *
   * @var array<string, string>
   */
  public array $rolesThatCanDeleteEventInstances = [];

  /**
   * Whether to skip synchronization during Drupal system update.
   */
  public bool $syncDuringSystemUpdate = FALSE;

  /**
   * Whether to skip synchronization of past events.
   */
  public bool $syncPastEvents = FALSE;

  /**
   * Get roles that can delete event instances.
   */
  public function getRolesThatCanDeleteEventInstances(): array {
    return array_filter($this->rolesThatCanDeleteEventInstances);
  }

}
