<?php

namespace Drupal\dpl_pretix;

use Drupal\Component\Serialization\Yaml;
use Drupal\dpl_pretix\Exception\ValidationException;
use Drupal\dpl_pretix\Pretix\ApiClient\Client;
use Drupal\dpl_pretix\Settings\PretixSettings;
use function Safe\json_encode;
use function Safe\sprintf;

/**
 * Pretix helper.
 */
final class PretixHelper {
  private const PRETIX_DATETIME_FORMAT = \DateTimeInterface::ATOM;

  public const EVENT_HAS_SUBEVENTS = 'has_subevents';

  /**
   * The pretix API client.
   */
  private Client $client;

  /**
   * Constructor.
   */
  public function __construct(
    private readonly Settings $settings,
  ) {
  }

  /**
   * Parse template events.
   */
  public function parseTemplateEvents(string $value): array {
    $values = Yaml::decode($value);

    if (empty($values)
      || !is_array($values)
      || array_is_list($values)
      || !empty(array_filter($values, static fn ($value) => !is_string($value)))) {
      throw new ValidationException(sprintf('Invalid value'));
    }

    return $values;
  }

  /**
   * Validate template event.
   *
   * @return \Drupal\dpl_pretix\Exception\ValidationException[]
   *   A list of validation errors.
   */
  public function validateTemplateEvent(string $templateEvent, ?PretixSettings $settings = NULL): array {
    $errors = [];

    $client = NULL !== $settings ? self::createClientFromSettings($settings) : $this->client();

    try {
      $event = $client->getEvent($templateEvent);
    }
    catch (\Exception $e) {
      return [
        new ValidationException(
          sprintf('Cannot get template event %s.', $templateEvent)
        ),
      ];
    }

    $data = $event->toArray();

    $expectedValues = [
      'live' => FALSE,
    ];
    foreach ($expectedValues as $name => $expectedValue) {
      $actualValue = $data[$name] ?? NULL;
      if ($expectedValue !== $actualValue) {
        $errors[] = new ValidationException(
          sprintf('Property %s on template event %s must be %s. Found %s.',
            $name, $event->getSlug(), json_encode($expectedValue), json_encode($actualValue)
          )
        );
      }
    }

    if ($data[self::EVENT_HAS_SUBEVENTS] ?? FALSE) {
      $subEvents = $client->getSubEvents($event);
      if (1 !== $subEvents->count()) {
        $errors[] = new ValidationException(sprintf('Template event %s must have exactly 1 sub-event; %d found.',
          $event->getSlug(), $subEvents->count()));
      }
    }

    $quotas = $client->getQuotas($event);
    if (1 !== $quotas->count()) {
      $errors[] = new ValidationException(sprintf('Template event %s must have exactly 1 quota; %d found.', $event->getSlug(), $quotas->count()));
    }

    return $errors;
  }

  /**
   * Format a date time for pretix.
   */
  public function formatDate(?\DateTimeInterface $date): ?string {
    return $date?->format(self::PRETIX_DATETIME_FORMAT);
  }

  /**
   * Format an amount (money) for pretix.
   */
  public function formatAmount(float $amount): string {
    return number_format($amount, 2, '.', '');
  }

  /**
   * Determine if an event is a singular event.
   *
   * @param string|array<string, mixed>|null $event
   *   The event slug or data.
   *
   * @see https://docs.pretix.eu/en/latest/user/events/create.html
   */
  public function isSingularEvent(string|array|null $event = NULL): bool {
    try {
      if (is_string($event)) {
        $event = $this->client()->getEvent($event);
        $event = $event->toArray();
      }

      return !($event[self::EVENT_HAS_SUBEVENTS] ?? FALSE);
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Get pretix API client.
   */
  public function client(): Client {
    if (!isset($this->client)) {
      $settings = $this->settings->getPretixSettings();
      $this->client = new Client([
        'url' => $settings->url ?? '',
        'organizer' => $settings->organizer,
        'api_token' => $settings->apiToken,
      ]);
    }

    return $this->client;
  }

  /**
   * Get organizer URL.
   */
  public function getOrganizerUrl(PretixSettings $settings): string {
    return sprintf('%s/control/organizer/%s', rtrim($settings->url ?? '', '/'), $settings->organizer);
  }

  /**
   * Get organizer URL.
   */
  public function getEventAdminUrl(PretixSettings $settings, string $eventShortForm): string {
    return sprintf('%s/control/event/%s/%s', rtrim($settings->url ?? '', '/'), $settings->organizer, $eventShortForm);
  }

  /**
   * Ping pretix API.
   */
  public function pingApi(?PretixSettings $settings = NULL): bool {
    if (NULL !== $settings && !isset($settings->url, $settings->organizer, $settings->apiToken)) {
      return FALSE;
    }

    $client = NULL !== $settings ? self::createClientFromSettings($settings) : $this->client();

    try {
      $client->getEvents([]);
      return TRUE;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Create pretix API client from settings.
   */
  private function createClientFromSettings(PretixSettings $settings): Client {
    return new Client([
      'url' => $settings->url,
      'organizer' => $settings->organizer,
      'api_token' => $settings->apiToken,
    ]);
  }

  /**
   * Get pretix name, i.e. the first localized value.
   *
   * @param array<string, mixed>|null $data
   *   The data (should be a serialized pretix item).
   */
  public function getPretixName(?array $data): ?string {
    if (empty($data)) {
      return NULL;
    }

    $values = $data['name'] ?? [];

    return reset($values) ?: NULL;
  }

}
