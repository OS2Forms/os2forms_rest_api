<?php

namespace Drupal\os2forms_rest_api\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\os2forms_rest_api\WebformHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Webform access event subscriber.
 */
class WebformAccessEventSubscriber implements EventSubscriberInterface {
  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private RouteMatchInterface $routeMatch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  private AccountProxyInterface $currentUser;

  /**
   * The webform helper.
   *
   * @var \Drupal\os2forms_rest_api\WebformHelper
   */
  private WebformHelper $webformHelper;

  /**
   * Constructor.
   */
  public function __construct(RouteMatchInterface $routeMatch, AccountProxyInterface $currentUser, WebformHelper $webformHelper) {
    $this->routeMatch = $routeMatch;
    $this->currentUser = $currentUser;
    $this->webformHelper = $webformHelper;
  }

  /**
   * On request handler.
   *
   * Check for user access to webform API resource.
   */
  public function onRequest(KernelEvent $event): void {
    $routeName = $this->routeMatch->getRouteName();
    $restRouteNames = [
      'rest.webform_rest_elements.GET',
      'rest.webform_rest_fields.GET',
      'rest.webform_rest_submission.GET',
      'rest.webform_rest_submission.PATCH',
      'rest.webform_rest_submit.POST',
    ];
    if (!in_array($routeName, $restRouteNames, TRUE)) {
      return;
    }

    // GET request have the webform id and (optional) submission uuid in the
    // query string.
    if (preg_match('/\.GET$/', $routeName)) {
      $webformId = $this->routeMatch->getParameter('webform_id');
      $submissionUuid = $this->routeMatch->getParameter('uuid');
    }
    else {
      // POST and PATCH requests have webform id and submission uuid in the
      // request body.
      try {
        $content = json_decode($event->getRequest()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        if (isset($content['webform_id'])) {
          $webformId = (string) $content['webform_id'];
        }
        if (isset($content['submission_uuid'])) {
          $submissionUuid = (string) $content['submission_uuid'];
        }
      }
      catch (\JsonException $exception) {
        // Invalid JSON body. We cannot get webform id from request body.
      }
    }

    if (!isset($webformId)) {
      throw new BadRequestHttpException('Cannot get webform id');
    }

    $webform = $this->webformHelper->getWebform($webformId, $submissionUuid ?? NULL);

    if (NULL === $webform) {
      return;
    }

    if (!$this->webformHelper->hasWebformAccess($webform, $this->currentUser)) {
      throw new AccessDeniedHttpException('Access denied');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      // @see https://www.drupal.org/project/drupal/issues/2924954#comment-12350447
      KernelEvents::REQUEST => ['onRequest', 31],
    ];
  }

}
