<?php

namespace Drupal\dpl_pretix\Settings\Item;

/**
 * Library item.
 */
class LibraryItem extends AbstractItem {

  /**
   * The organizer.
   */
  public ?string $organizer = NULL;

  /**
   * The API token.
   */
  public ?string $apiToken = NULL;

}
