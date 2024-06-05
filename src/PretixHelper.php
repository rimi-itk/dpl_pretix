<?php

namespace Drupal\dpl_pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use ItkDev\Pretix\Api\Client;

class PretixHelper
{
  private array $config;

  public function __construct(ConfigFactoryInterface $config_factory)
  {
    $this->config = $config_factory->get('dpl_pretix.settings')->get('pretix') ?? [];
  }

  /**
   * Ping the pretix API.
   *
   * @throws \Throwable
   */
  public function pingApi(): void {
    $this->client()->getEvents([]);
  }

  private function client(): Client {
    return new Client([
      'url' => $this->config['url'],
      'organizer' => $this->config['organizer_slug'],
      'api_token' => $this->config['api_key'],
    ]);
  }

}
