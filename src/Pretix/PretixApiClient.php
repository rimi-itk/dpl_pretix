<?php

namespace Drupal\dpl_pretix\Pretix;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use function Safe\json_decode;

/**
 *
 */
class PretixApiClient
{
  private readonly Client $client;

  public function __construct(
    private readonly array $options
  ) {
  }

  /**
   * Ping the pretix API.
   *
   * @throws \Throwable
   */
  public function pingApi(): void
  {
    $this->getEvents([]);
  }


  /**
   *
   */
  public function getEventUrl(string $event): ?string
  {
    // $this->client()->getEvent($event)->getPretixUrl()
    //    https://pretix.eu/control/event/auh/erhvervspraktik/
    $url = $this->settings->getPretix()['url'] ?? null;

    return $url ? Url::fromUri($url) : null;
  }

  /**
   *
   */
  public function getEventAdminUrl(string $event): ?string
  {
    return sprintf('%s/control/event/%s/%s', rtrim($this->config['url'], '/'), $this->config['organizer'], $event);
  }

  public function getEvents(array $query): array
  {
    $response = $this->request('GET', 'organizers/{organizer}/events/', $query);

    return $this->toArray($response);
  }

  private function toArray(ResponseInterface $response): array
  {
    return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
  }

  private function buildPath(string $path): string
  {
    return 'api/v1/'
      .str_replace(
        [
          '{organizer}',
        ],
        [
          $this->options['organizer'],
        ],
        ltrim($path, '/')
      );
  }

  private function request(string $method, string $path, array $query): ResponseInterface
  {
    if (!isset($this->client)) {
      $this->client = new Client([
        'base_uri' => $this->options['url'],
        'headers' => [
          'authorization' => 'Token '.$this->options['api_token'],
        ],
      ]);
    }

    return $this->client->request($method, $this->buildPath($path), ['query' => $query]);
  }

}
