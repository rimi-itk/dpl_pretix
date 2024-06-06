<?php

namespace Drupal\dpl_pretix\Controller;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use function Safe\ksort;
use function Safe\preg_replace;
use function Safe\sprintf;
use function Safe\substr;

/**
 *
 */
class DebugController {

  /**
   *
   */
  public function main(Request $request, ?string $action): array {
    [$actions, $action] = $this->getActions($action);

    $build['navigation'] = [
      '#type' => 'dropbutton',
    ];
    foreach ($actions as $key => $value) {
      $build['navigation']['#links'][$key] = [
        'title' => $value,
        'url' => Url::fromRoute('dpl_pretix.settings_debug', ['action' => $key]),
      ];
    }

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

    // header('content-type: text/plain'); echo var_export($element, true); die(__FILE__.':'.__LINE__.':'.__METHOD__);.
    return $build;
  }

  /**
   *
   */
  private function getActions(?string $action): array {
    $actions = array_filter(
      get_class_methods($this),
      static fn ($action) => str_starts_with($action, 'action')
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
  private function actionShowEvents(Request $request): array|string {
    return [
      '#markup' => sprintf('%s %s', __FUNCTION__, (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM)),
    ];
  }

  /**
   *
   */
  private function actionTest(Request $request): array {
    throw new \RuntimeException(sprintf('%s not implemented', __FUNCTION__));
  }

  /**
   *
   */
  private function actionInfo(Request $request): array {
    throw new \RuntimeException(sprintf('%s not implemented', __FUNCTION__));
  }

}
