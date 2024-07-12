<?php

namespace Drupal\dpl_pretix\Settings;

use Drupal\dpl_pretix\Settings\Item\LibraryItem;

/**
 * Libraries settings.
 */
class LibrariesSettings extends AbstractSettings {
  /**
   * {@inheritdoc}
   */
  protected static array $listProperties = [
    'list' => LibraryItem::class,
  ];

  /**
   * The list.
   *
   * @var array<int, \Drupal\dpl_pretix\Settings\Item\LibraryItem>|null
   */
  public ?array $list = NULL;

}
