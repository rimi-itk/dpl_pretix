<?php

namespace Drupal\dpl_pretix;

use Drupal\dpl_pretix\Exception\ValidationException;
use Drupal\dpl_pretix\Pretix\ApiClient\Client;

/**
 * Pretix helper.
 */
class PretixHelper {
  private const PRETIX_DATETIME_FORMAT = \DateTimeInterface::ATOM;

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
   * Validate template event.
   *
   * @return \Drupal\dpl_pretix\Exception\ValidationException[]|array
   *   A list of validation errors.
   */
  public function validateTemplateEvent(): array {
    $errors = [];

    $settings = $this->settings->getPretixSettings();
    $event = $this->client()->getEvent($settings->templateEvent);
    $data = $event->toArray();

    var_export($data);
    $expectedValues = [
      'has_subevents' => TRUE,
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

    $subEvents = $this->client()->getSubEvents($event);
    if (1 !== $subEvents->count()) {
      $errors[] = new ValidationException(sprintf('Template event %s must have exactly 1 sub-event; %d found.', $event->getSlug(), $subEvents->count()));
    }

    $quotas = $this->client()->getItems($event);
    if (1 !== $quotas->count()) {
      $errors[] = new ValidationException(sprintf('Template event %s must have exactly 1 product; %d found.', $event->getSlug(), $quotas->count()));
    }

    $quotas = $this->client()->getQuotas($event);
    if (1 !== $quotas->count()) {
      $errors[] = new ValidationException(sprintf('Template event %s must have exactly 1 quota; %d found.', $event->getSlug(), $quotas->count()));
    }

    return $errors;
  }

  /**
   * Format a date time for pretix.
   */
  public function formatDate(?\DateTimeInterface $date) {
    return NULL === $date ? NULL : $date->format(self::PRETIX_DATETIME_FORMAT);
  }

  /**
   * Get pretix API client.
   */
  public function client(): Client {
    if (!isset($this->client)) {
      $settings = $this->settings->getPretixSettings();
      $this->client = new Client([
        'url' => $settings->url,
        'organizer' => $settings->organizer,
        'api_token' => $settings->apiToken,
      ]);
    }

    return $this->client;
  }

}
