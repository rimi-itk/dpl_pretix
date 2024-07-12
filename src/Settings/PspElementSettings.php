<?php

namespace Drupal\dpl_pretix\Settings;

use Drupal\dpl_pretix\Settings\Item\NameValueItem;

/**
 * PSP settings.
 */
class PspElementSettings extends AbstractSettings {

  /**
   * {@inheritdoc}
   */
  protected static array $listProperties = [
    'list' => NameValueItem::class,
  ];

  /**
   * The PSP meta key.
   */
  public ?string $pretixPspMetaKey = NULL;

  /**
   * The list.
   *
   * @var \Drupal\dpl_pretix\Settings\Item\NameValueItem[]|null
   */
  public ?array $list = NULL;

}
