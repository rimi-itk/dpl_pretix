<?php

namespace Drupal\dpl_pretix\Pretix\ApiClient;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Response as HttpResponse;
use GuzzleHttp\RequestOptions;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollection;
use Drupal\dpl_pretix\Pretix\ApiClient\Collections\EntityCollectionInterface;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\AbstractEntity;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\CheckInList;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event\Settings as EventSettings;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Exporter;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Item;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Order;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Organizer;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Question;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Quota;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\QuotaAvailability;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent;
use Drupal\dpl_pretix\Pretix\ApiClient\Entity\Webhook;
use Drupal\dpl_pretix\Pretix\ApiClient\Exception\ClientException;
use Drupal\dpl_pretix\Pretix\ApiClient\Exception\InvalidArgumentException;

/**
 * Pretix client.
 *
 * @see https://docs.pretix.eu/en/latest/api/resources/index.html
 */
class Client
{
    /** @var array */
    private $options;

    /**
     * The pretix url.
     *
     * @var string
     */
    private $url;

    /**
     * The pretix organizer slug.
     *
     * @var string
     */
    private $organizer;

    /**
     * The pretix api token.
     *
     * @var string
     */
    private $apiToken;

    /** @var HttpClient */
    private $client;

    /**
     * Constructor.
     *
     * @param string $url           The pretix api url
     * @param string $organizerSlug The organizer slug
     * @param string $apiToken      The api token
     */
    public function __construct(array $options)
    {
        $this->options = $options;
        $this->url = trim($this->options['url'], '/');
        $this->organizer = $this->options['organizer'];
        $this->apiToken = $this->options['api_token'];
    }

    /**
     * Set organizer slug.
     *
     * @param string $organizer The organizer slug
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Client
     */
    public function setOrganizer($organizer): self
    {
        $this->organizer = $organizer;

        return $this;
    }

    /**
     * Get api endpoints.
     */
    public function getApiEndpoints(): array
    {
        $response = $this->get('/');

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Get organizers.
     *
     * @return EntityCollectionInterface<Organizer>
     */
    public function getOrganizers(): EntityCollectionInterface
    {
        return $this->getCollection(Organizer::class, 'organizers/');
    }

    /**
     * Get organizer.
     *
     * @param mixed $organizer
     */
    public function getOrganizer($organizer): Organizer
    {
        return $this->getEntity(
            Organizer::class,
            'organizers/'.$organizer.'/'
        );
    }

    /**
     * Get Events.
     *
     * @return EntityCollectionInterface|Event[]
     */
    public function getEvents(array $options = []): EntityCollectionInterface
    {
        return $this->getCollection(
            Event::class,
            'organizers/'.$this->organizer.'/events/',
            $options
        );
    }

    /**
     * Get event.
     *
     * @param object|string $event
     *                             The event or event slug
     *
     * @return Event
     */
    public function getEvent($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getEntity(
            Event::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/'
        );
    }

    /**
     * Create event.
     *
     * @param array $data The data
     */
    public function createEvent(array $data): Event
    {
        return $this->postEntity(
            Event::class,
            'organizers/'.$this->organizer.'/events/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Clone event.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param array         $data
     *                             The data
     */
    public function cloneEvent($event, array $data): Event
    {
        $eventSlug = $this->getSlug($event);

        return $this->postEntity(
            Event::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/clone/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Update event.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param array         $data
     *                             The data
     */
    public function updateEvent($event, array $data): Event
    {
        $eventSlug = $this->getSlug($event);

        return $this->patchEntity(
            Event::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Delete event.
     *
     * @param object|string $event
     *                             The event or event slug
     *
     * @return object
     *                The result
     */
    public function deleteEvent($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->delete('organizers/'.$this->organizer.'/events/'.$eventSlug.'/');
    }

    /**
     * Get event settings.
     *
     * @see https://docs.pretix.eu/en/latest/api/resources/events.html#get--api-v1-organizers-(organizer)-events-(event)-settings-
     *
     * @param object|string $event
     *                             The event or event slug
     *
     * @return eventSettings
     *                       The event settings
     */
    public function getEventSettings($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getEntity(
            EventSettings::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/settings/'
        );
    }

    /**
     * Set event settings.
     *
     * @see https://docs.pretix.eu/en/latest/api/resources/events.html#patch--api-v1-organizers-(organizer)-events-(event)-settings-
     *
     * @param object|string $event
     *                                The event or event slug
     * @param array         $settings
     *                                The settings
     *
     * @return eventSettings
     *                       The event settings
     */
    public function setEventSettings($event, array $settings)
    {
        $eventSlug = $this->getSlug($event);

        return $this->patchEntity(
            EventSettings::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/settings/',
            ['json' => $settings]
        );
    }

    /**
     * Set event setting.
     *
     * @see https://docs.pretix.eu/en/latest/api/resources/events.html#patch--api-v1-organizers-(organizer)-events-(event)-settings-
     *
     * @param object|string $event
     *                             The event or event slug
     * @param string        $name
     *                             The setting name
     * @param mixed         $value
     *                             The setting value
     *
     * @return eventSettings
     *                       The event settings
     */
    public function setEventSetting($event, string $name, $value)
    {
        return $this->setEventSettings($event, [$name => $value]);
    }

    /**
     * Get items (products).
     *
     * @param object|string $event
     *                             The event or event slug
     *
     * @return EntityCollectionInterface<Item>
     */
    public function getItems($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(
            Item::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/items/'
        );
    }

    /**
     * Update item.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param int|object    $item
     *                             The item or item id
     * @param array         $data
     *                             The data
     *
     * @return object
     *                The result
     */
    public function updateItem($event, $item, array $data)
    {
        $eventSlug = $this->getSlug($event);
        $itemId = $this->getId($item);

        return $this->patchEntity(
            Item::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/items/'.$itemId.'/',
            ['json' => $data]
        );
    }

    /**
     * Get quotas.
     *
     * @param object|string $event
     *                               The event or event slug
     * @param array         $options
     *                               The options
     *
     * @return EntityCollectionInterface<\Drupal\dpl_pretix\Pretix\ApiClient\Entity\Quota>
     */
    public function getQuotas($event, array $options = [])
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(
            Quota::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/quotas/',
            $options
        );
    }

    /**
     * Create quota.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param array         $data
     *                             The data
     *
     * @return object
     *                The result
     */
    public function createQuota($event, array $data)
    {
        $eventSlug = $this->getSlug($event);

        return $this->postEntity(
            Quota::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/quotas/',
            ['json' => $data]
        );
    }

    /**
     * Update quota.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param int|object    $quota
     *                             The quota or quota id
     * @param array         $data
     *                             The data
     *
     * @return object
     *                The result
     */
    public function updateQuota($event, $quota, array $data)
    {
        $eventSlug = $this->getSlug($event);
        $quotaId = $this->getId($quota);

        return $this->patchEntity(
            Quota::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/quotas/'.$quotaId.'/',
            ['json' => $data]
        );
    }

    /**
     * Get quota availability.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param int|object    $quota
     *                             The quota or quota id
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Entity\QuotaAvailability
     */
    public function getQuotaAvailability($event, $quota)
    {
        $eventSlug = $this->getSlug($event);
        $quotaId = $this->getId($quota);

        return $this->getEntity(
            QuotaAvailability::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/quotas/'.$quotaId.'/availability/'
        );
    }

    /**
     * Get sub-events (event series dates).
     *
     * @param object|string $event
     *                             The event or event slug
     *
     * @return EntityCollectionInterface<\Drupal\dpl_pretix\Pretix\ApiClient\Entity\SubEvent>
     */
    public function getSubEvents($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(SubEvent::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/subevents/');
    }

    /**
     * Create sub-event.
     *
     * @param object|string $event
     *                             The event or event slug
     * @param array         $data
     *                             The data
     *
     * @return SubEvent
     */
    public function createSubEvent($event, array $data)
    {
        $eventSlug = $this->getSlug($event);

        return $this->postEntity(
            SubEvent::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/subevents/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Update sub-event.
     *
     * @param object|string $event
     *                                The event or event slug
     * @param object|string $subEvent
     *                                The sub-event or sub-event slug
     * @param array         $data
     *                                The data
     *
     * @return object
     *                The result
     */
    public function updateSubEvent($event, $subEvent, array $data)
    {
        $eventSlug = $this->getSlug($event);
        $subEventId = $this->getId($subEvent);

        return $this->patchEntity(
            SubEvent::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/subevents/'.$subEventId.'/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Delete sub-event.
     *
     * @param object|string $event
     *                                The event or event slug
     * @param object|string $subEvent
     *                                The sub-event or sub-event slug
     *
     * @return object
     *                The result
     */
    public function deleteSubEvent($event, $subEvent)
    {
        $eventSlug = $this->getSlug($event);
        $subEventId = $this->getId($subEvent);

        return $this->delete('organizers/'.$this->organizer.'/events/'.$eventSlug.'/subevents/'.$subEventId.'/');
    }

    /**
     * Get webhooks.
     *
     * @return EntityCollectionInterface|Webhook[]
     */
    public function getWebhooks(): EntityCollectionInterface
    {
        return $this->getCollection(Webhook::class, 'organizers/'.$this->organizer.'/webhooks/');
    }

    /**
     * Get webhook.
     *
     * @param string $id
     *                   The id
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Webhook
     */
    public function getWebhook($id): Webhook
    {
        return $this->getEntity(
            Webhook::class,
            'organizers/' . $this->organizer . '/webhooks/' . $id
        );
    }

    /**
     * Create webhook.
     *
     * @param array $data
     *                    The data
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    public function createWebhook(array $data): Webhook
    {
        return $this->postEntity(
            Webhook::class,
            'organizers/'.$this->organizer.'/webhooks/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Update webhook.
     *
     * @param int|Webhook $webhook
     *                             The webhook
     * @param array       $data
     *                             The data
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    public function updateWebhook($webhook, array $data): Webhook
    {
        $id = $this->getId($webhook);

        return $this->patchEntity(
            Webhook::class,
            'organizers/'.$this->organizer.'/webhooks/'.$id.'/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * @param string|\Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event $event
     */
    public function getOrders($event, array $query = [], array $options = []): EntityCollection
    {
        $eventSlug = $this->getSlug($event);

        $orders = $this->getCollection(Order::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/orders/', $options);

        $subEventId = isset($query['subevent']) ? $this->getId($query['subevent']) : null;
        /** @var Order $order */
        foreach ($orders as $order) {
            $order->setEvent($event);

            if (null !== $subEventId) {
                // Filter order positions on sub-events.
                $filtered = $order->getPositions()->filter(function (
                    Order\Position $position
                ) use ($subEventId) {
                    return $this->getId($position->getSubevent()) === $subEventId;
                });
                $order->setPositions($filtered);
            }
        }

        return $orders;
    }

    /**
     * Get order.
     *
     * @param object|string $organizer
     *                                 The organizer
     * @param object|string $event
     *                                 The event
     * @param string        $code
     *                                 The code
     */
    public function getOrder($organizer, $event, $code): Order
    {
        $organizerSlug = $this->getSlug($organizer);
        $eventSlug = $this->getSlug($event);

        return $this->getEntity(Order::class, 'organizers/'.$organizerSlug.'/events/'.$eventSlug.'/orders/'.$code.'/');
    }

    /**
     * @see https://docs.pretix.eu/en/latest/api/resources/orders.html#get--api-v1-organizers-(organizer)-events-(event)-orderpositions-
     *
     * @param $event
     */
    public function getOrderPositions($event, array $query = []): EntityCollectionInterface
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(Order\Position::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/orderpositions/');
    }

    /**
     * Get check-in lists.
     *
     * @param object|string $event
     *                             The event
     *
     * @return Doctrine\Common\Collections|Question[]
     */
    public function getCheckInLists($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(CheckInList::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/checkinlists/');
    }

    /**
     * Create check-in list.
     *
     * @param array $data The data
     *
     * @return CheckInList
     */
    public function createCheckInList($event, array $data)
    {
        $eventSlug = $this->getSlug($event);

        $data += [
            'all_products' => true,
            'limit_products' => [],
        ];

        return $this->postEntity(
            CheckInList::class,
            'organizers/'.$this->organizer.'/events/'.$eventSlug.'/checkinlists/',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * Get questions.
     *
     * @param object|string $organizer
     *                                 The organizer
     * @param object|string $event
     *                                 The event
     *
     * @return Doctrine\Common\Collections|Question[]
     */
    public function getQuestions($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(Question::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/questions/');
    }

    public function getEventExporters($event)
    {
        $eventSlug = $this->getSlug($event);

        return $this->getCollection(Exporter::class, 'organizers/'.$this->organizer.'/events/'.$eventSlug.'/exporters/');
    }

    public function runExporter($event, $identifier, $parameters)
    {
        $eventSlug = $this->getSlug($event);

        return $this->post('organizers/'.$this->organizer.'/events/'.$eventSlug.'/exporters/'.$identifier.'/run/', ['json' => $parameters]);
    }

    public function getExport(array $run)
    {
        $url = $run['download'];

        // We want to handle http errors ourselves (cf. https://docs.pretix.eu/en/latest/api/resources/exporters.html#downloading-the-result)
        return $this->get($url, [RequestOptions::HTTP_ERRORS => false]);
    }

    private function getEntity($class, $path, array $options = [])
    {
        return $this->requestEntity($class, 'GET', $path, $options);
    }

    private function getCollection($class, $path, array $options = [])
    {
        return $this->requestCollection($class, 'GET', $path, $options);
    }

    private function postEntity($class, $path, array $options = [])
    {
        return $this->requestEntity($class, 'POST', $path, $options);
    }

    private function patchEntity($class, $path, array $options = [])
    {
        return $this->requestEntity($class, 'PATCH', $path, $options);
    }

    /**
     * GET request.
     *
     * @param string $path
     *                        The path
     * @param array  $options
     *                        The options
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    private function get($path, array $options = []): HttpResponse
    {
        return $this->request('GET', $path, $options);
    }

    /**
     * POST request.
     *
     * @param string $path
     *                        The path
     * @param array  $options
     *                        The options
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    private function post($path, array $options = []): HttpResponse
    {
        return $this->request('POST', $path, $options);
    }

    /**
     * PATCH request.
     *
     * @param string $path
     *                        The path
     * @param array  $options
     *                        The options
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    private function patch($path, array $options = []): HttpResponse
    {
        return $this->request('PATCH', $path, $options);
    }

    /**
     * DELETE request.
     *
     * @param string $path
     *                     The path
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response
     */
    private function delete($path): HttpResponse
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Request.
     *
     * @param string $method
     *                        The method
     * @param string $path
     *                        The path
     * @param array  $options
     *                        The options
     *
     * @return \Drupal\dpl_pretix\Pretix\ApiClient\Response The result
     *                                     The result
     */
    private function request(
        $method,
        $path,
        array $options = []
    ): HttpResponse {
        if (null === $this->client) {
            $this->client = new HttpClient([
                'base_uri' => $this->url,
            ]);
        }
        $headers = [
            'accept' => 'application/json, text/javascript',
            'authorization' => 'Token '.$this->apiToken,
            'content-type' => 'application/json',
        ];

        $options += [
            'headers' => $headers,
        ];

        // If we get an absolute url we use that. Otherwise we compute an api path.
        $uri = filter_var($path, FILTER_VALIDATE_URL)
            ? $path
            : 'api/v1/'.$path;

        try {
            return $this->client->request($method, $uri, $options);
        } catch (\Exception $exception) {
            throw new ClientException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    private function requestEntity(
        string $class,
        string $method,
        string $path,
        array $options = []
    ): AbstractEntity {
        if (!is_subclass_of($class, AbstractEntity::class)) {
            throw new \RuntimeException(sprintf('Class %s must be an %s', $class, AbstractEntity::class));
        }
        $response = $this->request($method, $path, $options);

        return $this->loadEntity($class, $response);
    }

    private function requestCollection(
        string $class,
        string $method,
        string $path,
        array $options = []
    ): EntityCollectionInterface {
        if (!is_subclass_of($class, AbstractEntity::class)) {
            throw new \RuntimeException(sprintf('Class %s must be an %s', $class, AbstractEntity::class));
        }
        $items = [];

        $response = $this->request($method, $path, $options);
        try {
            $data = json_decode((string) $response->getBody(), true);
            if (isset($data['results'])) {
                $items = array_merge($items, $data['results']);
            }
            $fetchAll = $options['fetch_all'] ?? false;
            unset($options['fetch_all']);
            while ($fetchAll && $data['next']) {
                $response = $this->request($method, $data['next'], $options);
                $data = json_decode((string) $response->getBody(), true);
                if (isset($data['results'])) {
                    $items = array_merge($items, $data['results']);
                }
            }
        } catch (\Exception $exception) {
            throw $exception;
            // @TODO What to do?
        }

        return $this->loadCollection($class, $items);
    }

    private function loadCollection(
        string $class,
        array $items
    ): EntityCollectionInterface {
        return new EntityCollection(array_map(function ($item) use ($class) {
            return $this->createEntity($class, $item);
        }, $items));
    }

    /**
     * Get event slug.
     *
     * @param \Drupal\dpl_pretix\Pretix\ApiClient\Entity\Event|string $event The event or event
     *                                                      slug
     *
     * @return string The event slug
     */
    private function getSlug($event): string
    {
        if ($event instanceof Event) {
            $event = $event->getSlug();
        } elseif (!is_string($event)) {
            throw new InvalidArgumentException('String expected');
        }

        return $event;
    }

    /**
     * Get id.
     *
     * @param int|Item $item The object or object id
     *
     * @return int The id
     */
    private function getId($item): int
    {
        if ($item instanceof AbstractEntity) {
            $item = $item->getId();
        } elseif (!is_int($item)) {
            throw new InvalidArgumentException('Integer expected');
        }

        return $item;
    }

    private function loadEntity(string $class, HttpResponse $response)
    {
        $data = json_decode((string) $response->getBody(), true);

        return $this->createEntity($class, $data);
    }

    private function createEntity(string $class, array $data)
    {
        return new $class($data + [
            'pretix_options' => [
                'url' => $this->url,
                'organizer' => $this->organizer,
            ],
        ]);
    }
}
