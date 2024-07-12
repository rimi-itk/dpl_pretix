<?php

namespace Drupal\dpl_pretix\Controller;

use Drupal\Core\Controller\ControllerBase;
use Safe\Exceptions\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use function Safe\json_decode;

/**
 * Pretix webhook controller.
 */
class PretixWebhookController extends ControllerBase {

  /**
   * Handle pretix webhook.
   */
  public function main(Request $request): Response {
    try {
      $payload = json_decode($request->getContent(), TRUE);
    }
    catch (JsonException) {
      throw new BadRequestHttpException('Invalid or empty payload');
    }

    return new JsonResponse($payload);
  }

}
