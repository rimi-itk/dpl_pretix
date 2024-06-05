<?php

namespace Drupal\dpl_pretix;

use ItkDev\Pretix\Api\Client;

/**
 *
 */
class PretixHelper {
  private Client $client;

  public function __construct(
    private readonly Settings $settings,
  ) {
  }

  /**
   * Ping the pretix API.
   *
   * @throws \Throwable
   */
  public function pingApi(): void {
    $this->client()->getEvents([]);
  }

  /**
   *
   */
  public function hasOrders(string $event) {
    return $this->client()->getOrders($event)->count() > 0;
  }

  /**
   *
   */
  public function getEventUrl(string $event): ?string {
    // $this->client()->getEvent($event)->getPretixUrl()
    //    https://pretix.eu/control/event/auh/erhvervspraktik/
    $url = $this->settings->getPretix()['url'] ?? NULL;

    return $url ? Url::fromUri($url) : NULL;
  }

  /**
   *
   */
  public function getEventAdminUrl(string $event): ?string {
    // https://pretix.eu/control/event/auh/erhvervspraktik/
    $config = $this->settings->getPretix();

    return sprintf('%s/control/event/%s/%s', rtrim($config['url'], '/'), $config['organizer_slug'], $event);
  }

  /**
   *
   */
  private function client(): Client {
    if (!isset($this->client)) {
      $config = $this->settings->getPretix();
      $this->client = new Client([
        'url' => $config['url'],
        'organizer' => $config['organizer_slug'],
        'api_token' => $config['api_key'],
      ]);
    }

    return $this->client;
  }

}
