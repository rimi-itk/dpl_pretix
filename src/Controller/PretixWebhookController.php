<?php

namespace Drupal\dpl_pretix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent;
use Drupal\dpl_pretix\Pretix\EventHelper;
use Drupal\dpl_pretix\Pretix\OrderHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pretix webhook controller.
 */
class PretixWebhookController extends ControllerBase {
  /**
   * The pretix order helper.
   *
   * @var \Drupal\dpl_pretix\Pretix\OrderHelper
   */
  private OrderHelper $orderHelper;

  /**
   * The node helper.
   *
   * @var \Drupal\dpl_pretix\NodeHelper
   */
  private NodeHelper $nodeHelper;

  /**
   * The event helper.
   *
   * @var \Drupal\dpl_pretix\Pretix\EventHelper
   */
  private EventHelper $eventHelper;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->orderHelper = $container->get('dpl_pretix.order_helper');
    $instance->nodeHelper = $container->get('dpl_pretix.node_helper');
    $instance->eventHelper = $container->get('dpl_pretix.event_helper');
    $instance->database = $container->get('database');

    return $instance;
  }

  /**
   * Handle pretix webhook.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The payload.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function main(Request $request) {
    $payload = json_decode($request->getContent(), TRUE);
    if (empty($payload)) {
      throw new BadRequestHttpException('Invalid or empty payload');
    }

    $action = $payload['action'] ?? NULL;
    switch ($action) {
      case OrderHelper::PRETIX_EVENT_ORDER_PAID:
      case OrderHelper::PRETIX_EVENT_ORDER_CANCELED:
        $this->handleOrderUpdated($payload, $action);
        break;
    }

    return new JsonResponse($payload);
  }

  /**
   * Load the date item associated with a sub-event.
   *
   * @param \DplDev\Pretix\Api\Entity\SubEvent $subEvent
   *   The sub-event.
   *
   * @return \Drupal\dpl_pretix\Plugin\Field\FieldType\PretixDate|null
   *   The date item.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function loadDateItem(SubEvent $subEvent): ?PretixDate {
    $item = $this->database
      ->select('dpl_pretix_subevents', 'p')
      ->fields('p')
      ->condition('pretix_organizer_slug', $subEvent->getOrganizerSlug(), '=')
      ->condition('pretix_event_slug', $subEvent->getEventSlug(), '=')
      ->condition('pretix_subevent_id', $subEvent->getId(), '=')
      ->execute()
      ->fetchAssoc();

    return isset($item['item_uuid'])
      ? $this->nodeHelper->loadDateItem($item['item_uuid'])
      : NULL;
  }

  /**
   * Handle order updated.
   *
   * @param array $payload
   *   The payload.
   * @param string $action
   *   The action.
   *
   * @return array
   *   The payload.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function handleOrderUpdated(array $payload, $action) {
    $organizerSlug = $payload['organizer'] ?? NULL;
    $eventSlug = $payload['event'] ?? NULL;
    $orderCode = $payload['code'] ?? NULL;

    $node = $this->orderHelper->getNode($organizerSlug, $eventSlug);

    if (NULL !== $node) {
      switch ($action) {
        case OrderHelper::PRETIX_EVENT_ORDER_PAID:
        case OrderHelper::PRETIX_EVENT_ORDER_CANCELED:
          break;

        default:
          return $payload;
      }

      $client = $this->orderHelper->getPretixClient($node);
      try {
        $order = $this->orderHelper
          ->setPretixClient($client)
          ->getOrder($organizerSlug, $eventSlug, $orderCode);
      }
      catch (\Exception $exception) {
        throw new HttpException(500, 'Cannot get order', $exception);
      }

      if ($this->nodeHelper->getSynchronizeWithPretix($node)) {
        $processed = [];
        foreach ($order->getPositions() as $position) {
          $subEvent = $position->getSubevent();
          if (isset($processed[$subEvent->getId()])) {
            continue;
          }

          $quotas = $this->orderHelper->getSubEventAvailability($subEvent);
          $subEventData['availability'] = $quotas->toArray();
          $item = $this->loadDateItem($subEvent);
          $this->orderHelper->addPretixSubEventInfo($item, $subEvent, $subEventData);
        }
      }

      // Update availability on event node.
      $this->eventHelper->updateEventAvailability($node);
    }

    return $payload;
  }

}
