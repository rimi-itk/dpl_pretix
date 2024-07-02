<?php

namespace Drupal\dpl_pretix\Pretix;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Drupal\Core\Url;
use Drupal\dpl_pretix\Pretix\ApiClient\Client;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent;
use Drupal\node\NodeInterface;

/**
 * Pretix order helper.
 */
class OrderHelper extends AbstractHelper {
  // @see https://docs.pretix.eu/en/latest/api/resources/webhooks.html#resource-description
  public const PRETIX_EVENT_ORDER_PLACED = 'pretix.event.order.placed';

  public const PRETIX_EVENT_ORDER_PLACED_REQUIRE_APPROVAL = 'pretix.event.order.placed.require_approval';

  public const PRETIX_EVENT_ORDER_PAID = 'pretix.event.order.paid';

  public const PRETIX_EVENT_ORDER_CANCELED = 'pretix.event.order.canceled';

  public const PRETIX_EVENT_ORDER_EXPIRED = 'pretix.event.order.expired';

  public const PRETIX_EVENT_ORDER_MODIFIED = 'pretix.event.order.modified';

  public const PRETIX_EVENT_ORDER_CONTACT_CHANGED = 'pretix.event.order.contact.changed';

  public const PRETIX_EVENT_ORDER_CHANGED = 'pretix.event.order.changed.*';

  public const PRETIX_EVENT_ORDER_REFUND_CREATED_EXTERNALLY = 'pretix.event.order.refund.created.externally';

  public const PRETIX_EVENT_ORDER_APPROVED = 'pretix.event.order.approved';

  public const PRETIX_EVENT_ORDER_DENIED = 'pretix.event.order.denied';

  public const PRETIX_EVENT_CHECKIN = 'pretix.event.checkin';

  public const PRETIX_EVENT_CHECKIN_REVERTED = 'pretix.event.checkin.reverted';

  /**
   * Get pretix order augmented with quota information and expanded sub-events.
   *
   * @param string|object $organizer
   *   The organizer (slug).
   * @param string|object $event
   *   The event (slug).
   * @param string $orderCode
   *   The order code.
   *
   * @return \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Order
   *   The order.
   */
  public function getOrder($organizer, $event, $orderCode) {
    try {
      $order = $this->pretixClient->getOrder($organizer, $event, $orderCode);
    }
    catch (\Exception $exception) {
      throw $this->clientException($this->t('Cannot get order'), $exception);
    }

    // Get all items.
    try {
      $items = $this->pretixClient->getItems($event);
    }
    catch (\Exception $exception) {
      throw $this->clientException(
        $this->t('Cannot get event items'),
        $exception
          );
    }

    // Index by id.
    $items = $this->indexCollection($items, 'id');

    // Get all quotas.
    try {
      $allQuotas = $this->pretixClient->getQuotas($event);
    }
    catch (\Exception $exception) {
      throw $this->clientException('Cannot get event quotas', $exception);
    }

    // Index quotas by item id and sub-event id and augment with availability.
    $quotas = [];
    foreach ($allQuotas as $quota) {
      foreach ($quota->getItems() as $itemId) {
        try {
          $availability = $this->pretixClient->getQuotaAvailability(
            $event,
            $quota
          );
        }
        catch (\Exception $exception) {
          throw $this->clientException(
            'Cannot get quota availability',
            $exception
                  );
        }
        $quota->setAvailability($availability);
        $quotas[$itemId][$quota->getSubevent()][] = $quota;
      }
    }

    // Get sub-events.
    try {
      $subEvents = $this->pretixClient->getSubEvents($event);
    }
    catch (\Exception $exception) {
      throw  $this->clientException('Cannot get sub-events', $exception);
    }
    // Index by id.
    $subEvents = $this->indexCollection($subEvents, 'id');

    // Add quotas and expanded sub-events to order lines.
    foreach ($order->getPositions() as $position) {
      $position
        ->setQuotas($quotas[$position->getItem()][$position->getSubevent()])
        ->setSubevent($subEvents[$position->getSubevent()]);
    }

    return $order;
  }

  /**
   * Index a collection by a property.
   *
   * @param \Doctrine\Common\Collections\Collection $collection
   *   The collection.
   * @param string $property
   *   The property name.
   *
   * @return \Doctrine\Common\Collections\ArrayCollection
   *   The re-indexed collection.
   */
  private function indexCollection(Collection $collection, string $property) {
    $elements = [];
    $method = 'get' . ucfirst($property);

    foreach ($collection as $index => $element) {
      $elements[$element->{$method}()] = $element;
    }

    return new ArrayCollection($elements);
  }

  /**
   * Get order lines grouped by sub-event.
   *
   * @param object $order
   *   The pretix order.
   *
   * @return array<string, mixed>
   *   The order lines.
   *
   * @throws \Exception
   */
  public function getOrderLines($order): array {
    throw new \RuntimeException(__METHOD__ . ' not implemented');
  }

  /**
   * Get availability information for a pretix event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array<string, mixed>
   *   The availability.
   */
  public function getAvailability(NodeInterface $node): array {
    throw new \RuntimeException(__METHOD__ . ' not implemented');
  }

  /**
   * Get sub-event availability from pretix.
   *
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $subEvent
   *   The sub-event.
   *
   * @return \Doctrine\Common\Collections\Collection
   *   A pretix API result with quotas enriched with availability information.
   */
  public function getSubEventAvailabilities(SubEvent $subEvent) {
    $event = $subEvent->getEvent();
    try {
      $quotas = $this->pretixClient
        ->getQuotas($event, ['query' => ['subevent' => $subEvent->getId()]]);
    }
    catch (\Exception $exception) {
      throw $this->clientException(
        $this->t('Cannot get quotas for sub-event'),
        $exception
          );
    }

    foreach ($quotas as $quota) {
      try {
        $availability = $this->pretixClient->getQuotaAvailability(
          $event,
          $quota
        );
      }
      catch (\Exception $exception) {
        throw $this->clientException(
          $this->t('Cannot get quota availability'),
          $exception
              );
      }
      $quota->setAvailability($availability);
    }

    return $quotas;
  }

  /**
   * Ensure that the pretix callback webhook exists.
   *
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Client $client
   *   The pretix client.
   *
   * @return \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Webhook
   *   The webhook.
   */
  public function ensureWebhook(Client $client) {
    $targetUrl = Url::fromRoute(
      'dpl_pretix.pretix_webhook',
      [],
      ['absolute' => TRUE]
    )->toString();
    $existingWebhook = NULL;

    $webhooks = $client->getWebhooks();
    foreach ($webhooks as $webhook) {
      if ($targetUrl === $webhook->getTargetUrl()) {
        $existingWebhook = $webhook;
        break;
      }
    }

    $actionTypes = [
      self::PRETIX_EVENT_ORDER_PLACED,
      self::PRETIX_EVENT_ORDER_PLACED_REQUIRE_APPROVAL,
      self::PRETIX_EVENT_ORDER_PAID,
      self::PRETIX_EVENT_ORDER_CANCELED,
      self::PRETIX_EVENT_ORDER_EXPIRED,
      self::PRETIX_EVENT_ORDER_MODIFIED,
      self::PRETIX_EVENT_ORDER_CONTACT_CHANGED,
      self::PRETIX_EVENT_ORDER_CHANGED,
      self::PRETIX_EVENT_ORDER_REFUND_CREATED_EXTERNALLY,
      self::PRETIX_EVENT_ORDER_APPROVED,
      self::PRETIX_EVENT_ORDER_DENIED,
      self::PRETIX_EVENT_CHECKIN,
      self::PRETIX_EVENT_CHECKIN_REVERTED,
    ];

    $webhookSettings = [
      'target_url' => $targetUrl,
      'enabled' => TRUE,
      'all_events' => TRUE,
      'limit_events' => [],
      'action_types' => $actionTypes,
    ];

    return NULL === $existingWebhook
      ? $client->createWebhook($webhookSettings)
      : $client->updateWebhook($existingWebhook, $webhookSettings);
  }

  /**
   * Get sub-event availability from pretix.
   *
   * @param \Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent $subEvent
   *   The sub-event.
   *
   * @return \Doctrine\Common\Collections\Collection
   *   A collection of quotas enriched with availability information.
   */
  public function getSubEventAvailability(SubEvent $subEvent) {
    $event = $subEvent->getEvent();
    try {
      $quotas = $this->pretixClient->getQuotas(
        $event,
        ['query' => ['subevent' => $subEvent->getId()]]
      );
    }
    catch (\Exception $exception) {
      throw $this->clientException(
        'Cannot get quotas for sub-event',
        $exception
          );
    }

    foreach ($quotas as $quota) {
      try {
        $availability = $this->pretixClient->getQuotaAvailability(
          $event,
          $quota
        );
      }
      catch (\Exception $exception) {
        throw $this->clientException(
          'Cannot get quota availability',
          $exception
              );
      }
      $quota->setAvailability($availability);
    }

    return $quotas;
  }

}
