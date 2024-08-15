<?php

namespace Drupal\dpl_pretix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\dpl_pretix\Entity\EventData;
use Drupal\dpl_pretix\EventDataHelper;
use Drupal\recurring_events\Entity\EventInstance;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use function Safe\array_combine;
use function Safe\ksort;
use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\sprintf;
use function Safe\substr;

/**
 * Pretix debug controller.
 */
final class AdminController extends ControllerBase {
  use StringTranslationTrait;

  public function __construct(
    private readonly EventDataHelper $eventDataHelper,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var \Drupal\dpl_pretix\EventDataHelper $eventDataHelper */
    $eventDataHelper = $container->get(EventDataHelper::class);
    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.dpl_pretix');

    return new static(
      $eventDataHelper,
      $logger,
    );
  }

  /**
   * Main action.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  public function main(Request $request, ?string $action): array {
    $build = [];

    [$actions, $action] = $this->getActions($action);

    if (count($actions) > 1) {
      $build['navigation'] = [
        '#type' => 'dropbutton',
      ];
      foreach ($actions as $key => $value) {
        $build['navigation']['#links'][$key] = [
          'title' => $value,
          'url' => Url::fromRoute('dpl_pretix.settings_debug', ['action' => $key]),
        ];
      }
    }

    if (!empty($actions)) {
      $action ??= reset($actions);

      if (isset($action)) {
        try {
          $method = 'action' . $action;
          if (method_exists($this, $method)) {
            $response = $this->$method($request);
            $build['response'] = [
              '#type' => 'container',
              'content' => is_scalar($response) ? ['#markup' => $response] : $response,
            ];
          }
          else {
            throw new \RuntimeException(sprintf('Invalid action: %s', $action));
          }
        }
        catch (\Throwable $exception) {
          $this->logger->error('Error running action: @message', [
            '@message' => $exception->getMessage(),
            '@exception' => $exception,
          ]);
          $build['message'] = [
            '#theme' => 'status_messages',
            '#message_list' => [
              'error' => [
                $exception->getMessage(),
              ],
            ],
          ];
        }
      }
    }

    return $build;
  }

  /**
   * Get actions.
   *
   * An "action" is a function whose name starts with "action" follow by an
   * uppercase letter, e.g. actionShowStuff.
   *
   * @return array<int, mixed>
   *   The actions (array<string, string>) and the current action (string).
   */
  private function getActions(?string $action): array {
    $actions = array_filter(
      get_class_methods($this),
      static fn ($action) => 1 === preg_match('/^action[A-Z]/', $action),
    );

    $actions = array_combine(
    // Remove "action" prefix.
      array_map(static fn ($action) => (substr($action, 6)), $actions),
      // Remove "action" prefix and convert CamelCase to space separated and
      // upcase first letter.
      array_map(static fn ($action) => ucfirst(strtolower(trim(preg_replace('/[A-Z]/', ' \0', (substr($action, 6)))))), $actions),
    );
    ksort($actions);

    // Move valid action to start of command list.
    if (isset($actions[$action])) {
      $actions = [$action => $actions[$action]] + $actions;
    }

    return [$actions, $action];
  }

  /**
   * Events action.
   *
   * @return array<string, mixed>
   *   The build.
   */
  private function actionEvents(Request $request): array {
    $events = $this->eventDataHelper->loadEventDataList();

    $build = [
      '#theme' => 'table',
      '#empty' => 'No events found.',
      '#caption' => 'Events',
      '#header' => [
        [
          'data' => $this->t('ID'),
          // 'field' => 'entity_id'
        ],
        $this->t('Title'),
        $this->t('Maintain copy'),
        $this->t('Ticket type'),
        $this->t('PSP Element'),

        $this->t('Event shop URL'),
        $this->t('Event admin URL'),
        $this->t('pretix ID'),
      ],
      '#rows' => array_map(
        function (EventData $data): array {
          assert(isset($data->entityType, $data->entityId));
          $id = $data->entityType . ':' . $data->entityId;
          $routeParameters = [
            'destination' => Url::fromRoute('dpl_pretix.settings_debug', ['action' => 'events'], ['fragment' => $id])->toString(TRUE)->getGeneratedUrl(),
          ];

          /** @var \Drupal\recurring_events\EventInterface $entity */
          $entity = $this->entityTypeManager()->getStorage($data->entityType)->load($data->entityId);

          $renderLink = static fn (?string $url) => $url ? Link::fromTextAndUrl($url, Url::fromUri($url)) : NULL;

          return [
            'id' => $id,
            'data' => [
              $id,
              $entity->toLink($entity->label())->toString(),
              $data->maintainCopy ? $this->t('Yes') : $this->t('No'),
              $data->ticketType,
              $data->pspElement,
              $renderLink($data->getEventShopUrl()),
              $renderLink($data->getEventAdminUrl()),

              $data->pretixSubeventId ?? $data->pretixEvent,

                [
                  'data' => [
                    '#type' => 'dropbutton',
                    '#links' => [
                      'edit' => [
                        'title' => $entity instanceof EventInstance ? $this->t('Edit event instance') : $this->t('Edit event series'),
                        'url' => $entity->toUrl('edit-form', [
                          'query' => [
                            'destination' => Url::fromRoute('<current>')->toString(TRUE)->getGeneratedUrl(),
                          ],
                        ]),
                      ],
                    ],
                  ],
                ],

            ],
          ];
        },
        array_filter(
          $events,
          static fn (EventData $data) => isset($data->entityType, $data->entityId)
        )
      ),
    ];

    return $build;
  }

}
