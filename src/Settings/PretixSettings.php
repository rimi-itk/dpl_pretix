<?php

namespace Drupal\dpl_pretix\Settings;

/**
 * Pretix settings.
 */
class PretixSettings extends AbstractSettings {
  /**
   * The Drupal domain.
   */
  public ?string $domain = NULL;

  /**
   * The URL.
   */
  public ?string $url = NULL;

  /**
   * The organizer.
   */
  public ?string $organizer = NULL;

  /**
   * The API token.
   */
  public ?string $apiToken = NULL;

  /**
   * The template event (slug).
   */
  public ?string $templateEvent = NULL;

  /**
   * The event slug template.
   */
  public string $eventSlugTemplate = '{id}';

  /**
   * The default language code.
   */
  public string $defaultLanguageCode = 'en';

}
