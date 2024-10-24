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
   * The template events (slugs).
   */
  public ?string $templateEvents = NULL;

  /**
   * The event slug template.
   */
  public string $eventSlugTemplate = 'test-{id}';

  /**
   * The default language code.
   */
  public string $defaultLanguageCode = 'da';

  /**
   * Decide if the settings are ready for use.
   */
  public function isReady(): bool {
    return !empty(trim($this->url ?? ''));
  }

}
