<?php

namespace Drupal\dpl_pretix\Pretix;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\dpl_pretix\Exception\SynchronizeException;
use Drupal\dpl_pretix\Plugin\Field\FieldType\PretixDate;
use Drupal\dpl_pretix\Pretix\ApiClient\Client;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Abstract helper.
 */
abstract class AbstractHelper {
  use StringTranslationTrait;

  public const PRETIX_ORGANIZER_SHORT_CODE = 'pretix_organizer_slug';
  public const PRETIX_EVENT_SLUG = 'pretix_event_slug';

  /**
   * The pretix client.
   *
   * @var \Drupal\dpl_pretix\Pretix\ApiClient\Client|null
   */
  protected ?Client $pretixClient = NULL;

  /**
   * Constructor for abstract helper class.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Set pretix client.
   *
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Client $pretixClient
   *   The client.
   *
   * @return \Drupal\dpl_pretix\Pretix\AbstractHelper
   *   The helper.
   */
  public function setPretixClient(Client $pretixClient): static {
    $this->pretixClient = $pretixClient;

    return $this;
  }

  /**
   * Get pretix client.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return \Drupal\dpl_pretix\Pretix\ApiClient\Client
   *   The client if any.
   */
  public function getPretixClient(NodeInterface $node): Client {
    if (NULL === $this->pretixClient) {
      $config = $this->getPretixConfiguration($node);

      $this->pretixClient = new Client([
        'url' => $config['pretix_url'],
        'organizer' => $config['organizer_slug'],
        'api_token' => $config['api_token'],
      ]);
    }

    return $this->pretixClient;
  }

  /**
   * Get pretix configuration for a node.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node.
   *
   * @return array
   *   The configuration.
   */
  public function getPretixConfiguration(NodeInterface $node = NULL): array {
    $config = $this->configFactory->get('dpl_pretix.pretixconfig');

    // @todo Handle node, e.g. to get user specific configuration.
    return $config->get();
  }

  /**
   * Get node by organizer and event.
   *
   * @param string|object $organizer
   *   The organizer.
   * @param string|object $event
   *   The event.
   *
   * @return null|NodeInterface
   *   The node if found.
   */
  public function getNode($organizer, $event): ?NodeInterface {
    $organizerSlug = $this->getSlug($organizer);
    $eventSlug = $this->getSlug($event);

    $result = $this->database
      ->select('dpl_pretix_events', 'p')
      ->fields('p')
      ->condition('pretix_organizer_slug', $organizerSlug, '=')
      ->condition('pretix_event_slug', $eventSlug, '=')
      ->execute()
      ->fetch();

    return Node::load($result->nid ?? NULL);
  }

  /**
   * Load pretix event info from database.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param bool $reset
   *   If set, data will be reset (from database).
   *
   * @return array|null
   *   The info if any.
   */
  public function loadPretixEventInfo(NodeInterface $node, $reset = FALSE): ?array {
    $nid = $node->id();
    $info = &drupal_static(__METHOD__, []);

    if ($reset || !isset($info[$nid])) {
      $record = $this->database
        ->select('dpl_pretix_events', 'p')
        ->fields('p')
        ->condition('nid', $nid, '=')
        ->execute()
        ->fetch();

      if (!empty($record)) {
        $info[$nid] = [
          'nid' => $record->nid,
          'pretix_organizer_slug' => $record->pretix_organizer_slug,
          'pretix_event_slug' => $record->pretix_event_slug,
          'data' => json_decode($record->data, TRUE),
        ];
      }
    }

    return $info[$nid] ?? NULL;
  }

  /**
   * Add pretix event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event $event
   *   The event.
   * @param array $data
   *   The data.
   * @param bool $reset
   *   If set, the data will be reset.
   *
   * @return array
   *   The event data.
   *
   * @throws \Exception
   */
  protected function addPretixEventInfo(NodeInterface $node, Event $event, array $data, $reset = FALSE): array {
    $info = $this->loadPretixEventInfo($node, TRUE);

    // The values to store in the database.
    $fields = [];
    if (NULL === $info || $reset) {
      $fields = [
        'nid' => $node->id(),
        'pretix_organizer_slug' => $event->getOrganizerSlug(),
        'pretix_event_slug' => $event->getSlug(),
      ];

      $data += [
        'pretix_url' => $event->getPretixUrl(),
        'pretix_event_url' => $event->getUrl(),
        'pretix_event_shop_url' => $event->getShopUrl(),
        'pretix_organizer_slug' => $event->getOrganizerSlug(),
        'pretix_event_slug' => $event->getSlug(),
        'event' => $event->toArray(),
      ];
    }

    // Add any existing data.
    $data += $info['data'] ?? [];

    $fields['data'] = json_encode($data);

    $result = $this->database
      ->merge('dpl_pretix_events')
      ->key(['nid' => $node->id()])
      ->fields($fields)
      ->execute();

    return $data;
  }

  /**
   * Add pretix sub-event info.
   *
   * @param object|null $item
   *   The item collection item.
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $subEvent
   *   The sub-event (id).
   * @param array $data
   *   The data.
   * @param bool $reset
   *   If set, the data will be reset.
   *
   * @return array
   *   The sub-event data.
   *
   * @throws \Exception
   */
  public function addPretixSubEventInfo(PretixDate $item, SubEvent $subEvent, array $data, $reset = FALSE): array {
    $info = $this->loadPretixSubEventInfo($item, TRUE);
    // The values to store in the database.
    $fields = [];
    if (NULL === $info || $reset) {
      $fields = [
        'item_uuid' => $item->uuid,
        'pretix_organizer_slug' => $subEvent->getOrganizerSlug(),
        'pretix_event_slug' => $subEvent->getEventSlug(),
        'pretix_subevent_id' => $subEvent->getId(),
      ];

      $data += [
        'pretix_subevent_id' => $subEvent->getId(),
        'pretix_url' => $subEvent->getPretixUrl(),
        'pretix_subevent_url' => $subEvent->getUrl(),
        'pretix_subevent_shop_url' => $subEvent->getShopUrl(),
      ];
    }
    $data['subevent'] = $subEvent->toArray();

    // Add any existing data.
    $data += $info['data'] ?? [];
    $fields['data'] = json_encode($data);

    $this->database
      ->merge('dpl_pretix_subevents')
      ->key([
        'item_uuid' => $item->uuid,
        'pretix_subevent_id' => $subEvent->getId(),
      ])
      ->fields($fields)
      ->execute();

    return $data;
  }

  /**
   * Load pretix sub-event info from database.
   *
   * @param array|PretixDate $item
   *   The date item.
   * @param bool $reset
   *   If set, data will be read from database.
   *
   * @return array|null
   *   The sub-event data.
   */
  public function loadPretixSubEventInfo(PretixDate $item, bool $reset = FALSE): ?array {
    $info = &drupal_static(__METHOD__, []);

    if ($reset || !isset($info[$item->uuid])) {
      $record = $this->database
        ->select('dpl_pretix_subevents', 'p')
        ->fields('p')
        ->condition('item_uuid', $item->uuid, '=')
        ->execute()
        ->fetch();

      if (!empty($record)) {
        $info[$item->uuid] = [
          'item_uuid' => $record->item_uuid,
          'pretix_subevent_id' => (int) $record->pretix_subevent_id,
          'data' => json_decode($record->data, TRUE),
        ];
      }
    }

    return $info[$item->uuid] ?? NULL;
  }

  /**
   * Get keys for looking up an item in the dpl_pretix_events table.
   *
   * @param object $item
   *   The item_id.
   *
   * @return array
   *   [field_name, item_id].
   */
  private function getItemKeys($item): array {
    if ($item instanceof NodeInterface) {
      return [
        $item->field_name->value(),
        (int) $item->item_id->value(),
      ];
    }

    return [
      $item->field_name,
      (int) $item->item_id,
    ];
  }

  /**
   * Get slug.
   *
   * @param string|object $object
   *   The object or object slug.
   *
   * @return string
   *   The object slug.
   */
  protected function getSlug($object): object|string {
    return $object->slug ?? $object;
  }

  /**
   * Get pretix event url.
   *
   * @param object $node
   *   The node.
   *
   * @return string|null
   *   The pretix event shop url if any.
   */
  public function getPretixEventShopUrl($node): ?string {
    $info = $this->loadPretixEventInfo($node);

    return $info['data']['pretix_event_shop_url'] ?? NULL;
  }

  /**
   * Get pretix event url.
   *
   * @param object $node
   *   The node.
   * @param string $path
   *   An optional url path.
   *
   * @return string|null
   *   The pretix event url if any.
   */
  public function getPretixEventUrl($node, $path = ''): ?string {
    $info = $this->loadPretixEventInfo($node);

    if (isset($info['data']['pretix_event_url'])) {
      return $info['data']['pretix_event_url'] . $path;
    }

    return NULL;
  }

  /**
   * Handle a pretix api client exception.
   */
  protected function clientException(string $message, \Exception $clientException = NULL): SynchronizeException {
    // @todo Log the exception.
    if (NULL === $clientException) {
      $this->loggerFactory->get('dpl_pretix')->error($message);
    }
    else {
      $this->loggerFactory->get('dpl_pretix')->error('@message: @client_message: ', [
        '@message' => $message,
        '@client_message' => $clientException->getMessage(),
        'client_exception' => $clientException,
      ]);
    }

    return new SynchronizeException($message, 0, $clientException);
  }

}
