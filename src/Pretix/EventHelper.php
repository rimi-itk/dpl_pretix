<?php

namespace Drupal\itk_pretix\Pretix;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dpl_pretix\Pretix\ApiClient\Client;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event\Settings as EventSettings;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Quota;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pretix helper.
 */
class EventHelper extends AbstractHelper {
  public const DATETIME_FORMAT = \DateTimeInterface::ATOM;

  /**
   * The order helper.
   *
   * @var \Drupal\itk_pretix\Pretix\OrderHelper
   */
  private OrderHelper $orderHelper;

  /**
   * The module handler interface.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private ModuleHandlerInterface $moduleHandler;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\itk_pretix\Pretix\OrderHelper $orderHelper
   *   The order helper.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   THe module handler interface.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    OrderHelper $orderHelper,
    ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($database, $configFactory, $loggerFactory);
    $this->orderHelper = $orderHelper;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Synchronize pretix event with a node.
   */
  public function syncronizePretixEvent(NodeInterface $node, array $options) {
    $client = $this->getPretixClient($node);

    $info = $this->loadPretixEventInfo($node);
    $isNewEvent = NULL === $info;

    /** @var \Drupal\Core\Field\FieldItemListInterface $dates */
    $dates = $options['dates'] ?? NULL;
    if (NULL === $dates || $dates->isEmpty()) {
      throw $this->clientException($this->t('No dates specified'));
    }
    /** @var \Drupal\itk_pretix\Plugin\Field\FieldType\PretixEventSettings $settings */
    $settings = $options['settings'] ?? NULL;
    if (NULL === $settings) {
      throw $this->clientException($this->t('No settings specified'));
    }
    if (!isset($settings->template_event)) {
      throw $this->clientException($this->t('No template event specified'));
    }
    $templateEventSlug = $settings->template_event;

    $name = $this->getEventName($node);
    // Get location and startDate from first date.
    $firstDate = $dates->first();
    $location = $this->getLocation($firstDate);
    $startDate = $firstDate->get('time_from')->getValue();

    // @todo Handle locales?
    $data = [
      'name' => ['en' => $name],
      'currency' => 'DKK',
      'date_from' => $this->formatDate($startDate),
      'is_public' => $node->isPublished(),
      'location' => ['en' => $location],
    ];

    // Allow modules to change event data.
    $context = [
      'is_new_event' => $isNewEvent,
    ];
    $this->moduleHandler->alter('itk_pretix_event_data', $data, $node, $context);

    $eventData = [];
    if ($isNewEvent) {
      $data['slug'] = $this->getEventSlug($node);
      // has_subevents is not cloned from source event.
      $data['has_subevents'] = TRUE;
      try {
        $event = $client->cloneEvent($templateEventSlug, $data);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot clone event'), $exception);
      }
      $eventData['template_event_slug'] = $templateEventSlug;
    }
    else {
      try {
        $event = $client->updateEvent($info['pretix_event_slug'], $data);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot update event'), $exception);
      }
    }

    $eventData['event'] = $event->toArray();

    // Event settings.
    $contactMail = $node->get('field_email_address')->getValue()[0]['value'] ?? NULL;
    if (empty($contactMail)) {
      // In pretix the contact mail cannot be empty, but it can be null.
      $contactMail = NULL;
    }
    $eventSettings = $client->setEventSetting($event, EventSettings::CONTACT_MAIL, $contactMail);
    $eventData['event_settings'] = $eventSettings->toArray();

    $info = $this->addPretixEventInfo($node, $event, $eventData);
    $subEvents = $this->synchronizePretixSubEvents($event, $node, $dates, $client);

    foreach ($subEvents as $subEvent) {
      if (isset($subEvent['error'])) {
        return $subEvent;
      }
    }

    return [
      'status' => $isNewEvent ? 'created' : 'updated',
      'info' => $info,
      'subevents' => $subEvents ?? NULL,
    ];
  }

  /**
   * Synchronize pretix sub-events.
   *
   * @param \ItkDev\Pretix\Api\Entity\Event $event
   *   The event.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\Core\Field\FieldItemListInterface $dates
   *   The dates.
   * @param \ItkDev\Pretix\Api\Client $client
   *   The client.
   *
   * @return array
   *   The sub-event info.
   *
   * @throws \Exception
   */
  public function synchronizePretixSubEvents(Event $event, NodeInterface $node, FieldItemListInterface $dates, Client $client) {
    $info = [];
    $subEventIds = [];
    foreach ($dates as $date) {
      $result = $this->synchronizePretixSubEvent($date, $event, $node, $client);
      if (isset($result['info'])) {
        $subEventIds[] = $result['info']['pretix_subevent_id'];
      }
      $info[] = $result;
    }

    foreach ($info as $subEvent) {
      if (isset($subEvent['error'])) {
        return $info;
      }
    }

    // Delete pretix sub-events that no longer exist in Drupal.
    $pretixSubEventIds = [];
    try {
      $subEvents = $client->getSubEvents($event);
      foreach ($subEvents as $subEvent) {
        if (!in_array($subEvent->getId(), $subEventIds, TRUE)) {
          $client->deleteSubEvent($event, $subEvent);
        }
        $pretixSubEventIds[] = $subEvent->getId();
      }
    }
    catch (\Exception $exception) {
      // @todo Do something clever here.
    }

    // @todo Clean up info on pretix sub-events.
    return $info;
  }

  /**
   * Synchronize pretix sub-event.
   *
   * @param \Drupal\itk_pretix\Plugin\Field\FieldType\PretixDate $item
   *   The item.
   * @param \ItkDev\Pretix\Api\Entity\Event $event
   *   The event.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \ItkDev\Pretix\Api\Client $client
   *   The client.
   *
   * @return array
   *   On success, the sub-event info. Otherwise an error.
   *
   * @throws \Exception
   */
  private function synchronizePretixSubEvent(PretixDate $item, Event $event, NodeInterface $node, Client $client) {
    $itemInfo = $this->loadPretixSubEventInfo($item, TRUE);
    $isNewItem = NULL === $itemInfo;

    $templateEvent = $this->getPretixTemplateEventSlug($node);
    try {
      $templateSubEvents = $client->getSubEvents($templateEvent);
    }
    catch (\Exception $exception) {
      throw $this->clientException($this->t('Cannot get template event sub-events'), $exception->getCode(), $exception);
    }
    if (0 === $templateSubEvents->count()) {
      throw $this->clientException($this->t('Cannot get template event sub-events'));
    }
    $templateSubEvent = $templateSubEvents->first();

    $product = NULL;
    $data = [];
    if ($isNewItem) {
      // Get first sub-event from template event.
      try {
        $items = $client->getItems($event);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot get template event items'), $exception);
      }
      if (0 === $items->count()) {
        throw $this->clientException($this->t('Missing items on template event'));
      }

      // Always use the first product.
      $product = $items->first();

      $data = $templateSubEvent->toArray();
      // Remove the template id.
      unset($data['id']);

      $data['item_price_overrides'] = [
          [
            'item' => $product->getId(),
          ],
      ];
    }
    else {
      $data = $itemInfo['data']['subevent'];
    }

    $location = $this->getLocation($item);
    [$geoLat, $geoLng] = $item->data['coordinates'] ?? [NULL, NULL];

    // @todo Handle locales.
    $data = array_merge($data, [
      'name' => ['en' => $this->getEventName($node)],
      'date_from' => $this->formatDate($item->time_from),
      'date_to' => $this->formatDate($item->time_to),
      'location' => ['en' => $location],
      'frontpage_text' => NULL,
      'geo_lat' => $geoLat,
      'get_lng' => $geoLng,
      'active' => TRUE,
      'is_public' => TRUE,
      'date_admission' => NULL,
      'presale_end' => NULL,
      'seating_plan' => NULL,
      'seat_category_mapping' => (object) [],
    ]);

    // @todo Handle prices.
    $price = 0;
    $data['item_price_overrides'][0]['price'] = $price;

    // Allow modules to change sub-event data.
    $context = [
      'is_new_subevent' => $isNewItem,
      'event' => $event,
      'pretix_date' => $item,
    ];
    $this->moduleHandler->alter('itk_pretix_subevent_data', $data, $node, $context);

    // Important: meta_data value must be an object!
    $data['meta_data'] = (object) ($data['meta_data'] ?? []);
    $subEventData = [];
    if ($isNewItem) {
      try {
        $subEvent = $client->createSubEvent($event, $data);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot create sub-event'), $exception);
      }
    }
    else {
      $subEventId = $itemInfo['pretix_subevent_id'];
      try {
        $subEvent = $client->updateSubEvent($event, $subEventId, $data);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot update sub-event'), $exception);
      }
    }

    // Get sub-event quotas.
    try {
      $quotas = $client->getQuotas(
            $event,
            ['query' => ['subevent' => $subEvent->getId()]]
        );
    }
    catch (\Exception $exception) {
      throw $this->clientException($this->t('Cannot get sub-event quotas'), $exception);
    }

    if (0 === $quotas->count()) {
      // Create a new quota for the sub-event.
      try {
        $templateQuotas = $client->getQuotas(
              $templateEvent,
              ['subevent' => $templateSubEvent->getId()]
          );
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot get template sub-event quotas'), $exception);
      }
      if (0 === $templateQuotas->count()) {
        throw $this->clientException($this->t('Missing sub-event quotas on template'));
      }

      $quotaData = $templateQuotas->first()->toArray();
      unset($quotaData['id']);
      $quotaData = array_merge($quotaData, [
        'subevent' => $subEvent->getId(),
        'items' => [$product->getId()],
        'size' => (int) $item->spots,
      ]);
      try {
        $quota = $client->createQuota($event, $quotaData);
        $quotas->add($quota);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot create quota for sub-event'), $exception);
      }
    }

    // We only and always use the first quota.
    if ($quota = $quotas->first()) {
      $quotaData = array_merge($quota->toArray(), [
        'size' => (int) $item->spots,
      ]);
      try {
        $quota = $client->updateQuota($event, $quota, $quotaData);
      }
      catch (\Exception $exception) {
        throw $this->clientException($this->t('Cannot update quota for sub-event'), $exception);
      }
    }

    $subEventData['subevent'] = $subEvent;
    try {
      $availabilities = $this->orderHelper
        ->setPretixClient($client)
        ->getSubEventAvailabilities($subEvent);
      $subEventData['availability'] = $availabilities->map(static function (Quota $quota) {
          return $quota->toArray();
      })->toArray();
    }
    catch (\Exception $exception) {
      $exception->getCode();
    }

    $info = $this->addPretixSubEventInfo($item, $subEvent, $subEventData);

    return [
      'status' => $isNewItem ? 'created' : 'updated',
      'info' => $info,
    ];
  }

  /**
   * Decide if pretix event shop is live (using local data).
   */
  public function isEventLive(NodeInterface $node) {
    $info = $this->loadPretixEventInfo($node);

    return $info['data']['event']['live'] ?? FALSE;
  }

  /**
   * Set event live (or not) in pretix.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param bool $live
   *   The live-ness of the node's event.
   *
   * @return array
   *   The event info.
   *
   * @throws \InvalidMergeQueryException
   */
  public function setEventLive(NodeInterface $node, bool $live) {
    // Note: 'live' is updated for all events including the ones that are not
    // synchronized with pretix.
    $info = $this->loadPretixEventInfo($node);
    $client = $this->getPretixClient($node);
    try {
      $event = $client->getEvent($info['pretix_event_slug']);
    }
    catch (\Exception $exception) {
      throw $this->clientException($this->t('Cannot get event'), $exception);
    }

    try {
      $event = $client->updateEvent($event, ['live' => $live]);
    }
    catch (\Exception $exception) {
      throw $this->clientException($live ? $this->t('Cannot set pretix event live') : $this->t('Cannot set pretix event not live'), $exception);
    }

    $info = $this->addPretixEventInfo($node, $event, ['event' => $event->toArray()]);

    return [
      'status' => $live ? 'live' : 'not live',
      'info' => $info,
    ];
  }

  /**
   * Update event availability for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array|null
   *   On success, the event info. Otherwise an error.
   *
   * @throws \Exception
   */
  public function updateEventAvailability(NodeInterface $node) {
    $client = $this->getPretixClient($node);
    $info = $this->loadPretixEventInfo($node);
    if (isset($info['pretix_event_slug'])) {
      try {
        $event = $client->getEvent($info['pretix_event_slug']);
      }
      catch (\Exception $exception) {
        throw $this->clientException('Cannot get event', $exception);
      }

      try {
        $quotas = $client->getQuotas($event);
      }
      catch (\Exception $exception) {
        throw $this->clientException('Cannot get quotas', $exception);
      }

      foreach ($quotas as $quota) {
        try {
          $availability = $client->getQuotaAvailability($event, $quota);
        }
        catch (\Exception $exception) {
          throw $this->clientException('Cannot get quota availability', $exception);
        }
        $quota->setAvailability($availability);
      }

      $info = $this->addPretixEventInfo($node, $event, ['quotas' => $quotas->toArray()]);
      $this->setEventAvailability($node, $event);

      return $info;
    }

    return NULL;
  }

  /**
   * Validate the the specified event is a valid template event.
   *
   * @param \ItkDev\Pretix\Api\Entity\Event $event
   *   The event.
   * @param \ItkDev\Pretix\Api\Client|null $client
   *   The client.
   *
   * @return null|array
   *   If null all is good. Otherwise, returns list of [key, error-message]
   */
  public function validateTemplateEvent(Event $event, Client $client) {
    // @todo Currently, we only support events with (multiple) dates.
    if (!$event->hasSubevents()) {
      return [
        'event_slug' => t('This event does not have sub-events.'),
      ];
    }

    try {
      $subEvents = $client->getSubEvents($event);
    }
    catch (\Exception $exception) {
      return [
        'event_slug' => t('Cannot get sub-events.'),
      ];
    }

    if (1 !== $subEvents->count()) {
      return [
        'event_slug' => t('Event must have exactly 1 date.'),
      ];
    }

    $subEvent = $subEvents->first();
    try {
      $quotas = $client->getQuotas($event, ['subevent' => $subEvent->getId()]);
    }
    catch (\Exception $exception) {
      return [
        'event_slug' => t('Cannot get sub-event quotas.'),
      ];
    }

    if (1 !== $quotas->count()) {
      return [
        'event_slug' => t('Date must have exactly 1 quota.'),
      ];
    }

    $quota = $quotas->first();
    if (1 !== count($quota->getItems())) {
      return [
        'event_slug' => t('Event date (sub-event) quota must apply to exactly 1 product.'),
      ];
    }

    return NULL;
  }

  /**
   * Get exporters for an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array|Exporter[]|\ItkDev\Pretix\Api\Collections\EntityCollectionInterface
   *   The exporters.
   */
  public function getExporters(NodeInterface $node) {
    $info = $this->loadPretixEventInfo($node);
    $eventSlug = $info['pretix_event_slug'] ?? NULL;
    $client = $this->getPretixClient($node);

    return $client->getEventExporters($eventSlug);
  }

  /**
   * Run exporter.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $identifier
   *   The exporter identifier.
   * @param array $parameters
   *   The exporter parameters.
   *
   * @return array
   *   The exporter run data.
   */
  public function runExporter(NodeInterface $node, string $identifier, array $parameters) {
    $info = $this->loadPretixEventInfo($node);
    $eventSlug = $info['pretix_event_slug'] ?? NULL;
    $client = $this->getPretixClient($node);

    $response = $client->runExporter($eventSlug, $identifier, $parameters);
    if (Response::HTTP_ACCEPTED !== $response->getStatusCode()) {
      throw new ExporterException($response->getReasonPhrase());
    }

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Get export result.
   */
  public function getExport(NodeInterface $node, array $run) {
    return $this->getPretixClient($node)->getExport($run);
  }

  /**
   * Set event availability for on a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \ItkDev\Pretix\Api\Entity\Event $event
   *   The event.
   *
   * @throws \Exception
   */
  private function setEventAvailability(NodeInterface $node, Event $event) {
    $info = $this->loadPretixEventInfo($node, TRUE);
    if (isset($info['data']['quotas'])) {
      $available = FALSE;
      foreach ($info['data']['quotas'] as $quota) {
        if (isset($quota['availability']['available']) && TRUE === $quota['availability']['available']) {
          $available = TRUE;
          break;
        }
      }

      if (!isset($info['available']) || $info['available'] !== $available) {
        $this->addPretixEventInfo($node, $event, ['available' => $available]);
        Cache::invalidateTags($node->getCacheTags());
      }
    }
  }

  /**
   * Get pretix template event slug.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string|null
   *   The template event slug if any.
   */
  private function getPretixTemplateEventSlug(NodeInterface $node) {
    $info = $this->loadPretixEventInfo($node);

    return $info['data']['template_event_slug'] ?? NULL;
  }

  /**
   * Get pretix event slug.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The event slug.
   */
  private function getEventSlug(NodeInterface $node) {
    $configuration = $this->getPretixConfiguration($node);
    $template = $configuration['pretix_event_slug_template'] ?? '!nid';

    // Make sure that node id is used in template.
    if (FALSE === strpos($template, '!nid')) {
      $template .= '-!nid';
    }

    return str_replace(['!nid'], [$node->id()], $template);
  }

  /**
   * Get event name from a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The event name.
   */
  private function getEventName(NodeInterface $node) {
    return $node->getTitle();
  }

  /**
   * Get item location.
   *
   * @param array|null $item
   *   The item.
   *
   * @return string
   *   The event location.
   */
  private function getLocation(PretixDate $item) {
    return implode(PHP_EOL, array_filter([
      $item->location ?? NULL,
      $item->address ?? NULL,
    ]));
  }

  /**
   * Get data from some value.
   *
   * @param mixed $value
   *   Something that may be converted to a DateTime.
   *
   * @return \DateTime|null
   *   The date.
   *
   * @throws \Exception
   */
  private function getDate($value) {
    if (NULL === $value) {
      return NULL;
    }

    if ($value instanceof DrupalDateTime) {
      return $value->getPhpDateTime();
    }

    if ($value instanceof \DateTime) {
      return $value;
    }

    if (is_numeric($value)) {
      return new \DateTime('@' . $value);
    }

    return new \DateTime($value);
  }

  /**
   * Format a date as a string.
   *
   * @param mixed|null $date
   *   The date.
   *
   * @return string|null
   *   The string representation of the date.
   *
   * @throws \Exception
   */
  private function formatDate($date = NULL) {
    $date = $this->getDate($date);

    return NULL === $date ? NULL : $date->format(self::DATETIME_FORMAT);
  }

}
