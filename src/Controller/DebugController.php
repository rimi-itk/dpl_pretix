<?php

namespace Drupal\dpl_pretix\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dpl_pretix\EventHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use function Safe\ksort;
use function Safe\preg_replace;
use function Safe\sprintf;
use function Safe\substr;
use function Safe\preg_match;

/**
 *
 */
class DebugController extends ControllerBase {
  public function __construct(
    private readonly EventHelper $eventHelper,
  )
  {
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get(EventHelper::class)
    );
  }

  /**
   *
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
          } else {
            throw new \RuntimeException(sprintf('Invalid action: %s', $action));
          }
        } catch (\Throwable $exception) {
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
   * An "action" is a function whose name starts with "action" follow by an uppercase letter, e.g. actionShowStuff.
   */
  private function getActions(?string $action): array {
    $actions = array_filter(
      get_class_methods($this),
      static fn ($action) => preg_match('/^action[A-Z]/', $action),
    );

    $actions = array_combine(
    // Remove "action" prefix.
      array_map(static fn ($action) => (substr($action, 6)), $actions),
      // Remove "action" prefix and convert CamelCase to space separated and upcase first letter.
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
   *
   */
  private function actionEvents(Request $request): array|string {
    $events = $this->eventHelper->loadEventData();

    $build = [
      '#theme' => 'table',
      '#empty' => 'No events found.',
      '#caption' => 'Events',
      '#header' => empty($events) ? [] : array_keys(reset($events)),
      '#rows' => array_map(
        static fn (array $data) => $data          +          [
            [
              'data' => [
              '#type' => 'dropbutton',
              '#links' => [
                'edit' => [
                  'title' => 'edit',
                  'url' => Url::fromRoute('entity.'.$data['entity_type'].'.edit_form', [$data['entity_type'] => $data['entity_id']]),
                ],
                'show' => [
                  'title' => 'show',
                  'url' => Url::fromRoute('entity.'.$data['entity_type'].'.canonical', [$data['entity_type'] => $data['entity_id']]),
                ],
              ],
              ],
              ],
          ],

        $events
      ),
    ];

    return $build;
  }

  /**
   *
   */
  private function actionsTest(Request $request): array {
    throw new \RuntimeException(sprintf('%s not implemented', __FUNCTION__));
  }

}
