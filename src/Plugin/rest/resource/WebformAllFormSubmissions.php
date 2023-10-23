<?php

namespace Drupal\os2forms_rest_api\Plugin\rest\resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Drupal\os2forms_rest_api\WebformHelper;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates a rest resource for retrieving webform submissions.
 *
 * @RestResource(
 *   id = "webform_rest_form_submissions",
 *   label = @Translation("Webform - submissions for a form"),
 *   uri_paths = {
 *     "canonical" = "/webform_rest/{webform_id}/submissions"
 *   }
 * )
 */
class WebformAllFormSubmissions extends ResourceBase {
  /**
   * Allowed DateTime query parameters and their operation.
   */
  private const ALLOWED_DATETIME_QUERY_PARAMS = [
    'starttime' => '>=',
    'endtime' => '<=',
  ];

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $currentRequest;

  /**
   * The entity type manager object.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * The webform helper.
   *
   * @var \Drupal\os2forms_rest_api\WebformHelper
   */
  private $webformHelper;

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    $instance->webformHelper = $container->get(WebformHelper::class);

    return $instance;
  }

  /**
   * Get submissions for a given webform.
   *
   * @param string $webform_id
   *   Webform ID.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   Response object.
   */
  public function get(string $webform_id): ModifiedResourceResponse {
    if (empty($webform_id)) {
      $errors = [
        'error' => [
          'message' => 'Webform ID is required.',
        ],
      ];
      return new ModifiedResourceResponse($errors, Response::HTTP_BAD_REQUEST);
    }

    // Attempt finding webform.
    $webform = $this->webformHelper->getWebform($webform_id);

    if (NULL === $webform) {
      $errors = [
        'error' => [
          'message' => $this->t('Could not find webform with id :webform_id', [':webform_id' => $webform_id]),
        ],
      ];

      return new ModifiedResourceResponse($errors, Response::HTTP_NOT_FOUND);
    }

    // Webform access check.
    if (!$this->webformHelper->hasWebformAccess($webform, $this->webformHelper->getCurrentUser())) {
      $errors = [
        'error' => [
          'message' => $this->t('Access denied'),
        ],
      ];

      return new ModifiedResourceResponse($errors, Response::HTTP_UNAUTHORIZED);
    }

    $result = ['webform_id' => $webform_id];

    try {
      $submissionEntityStorage = $this->entityTypeManager->getStorage('webform_submission');
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errors = [
        'error' => [
          'message' => $this->t('Could not load webform submission storage'),
        ],
      ];

      return new ModifiedResourceResponse($errors, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    // Query for webform submissions with this webform_id.
    $submissionQuery = $submissionEntityStorage->getQuery()
      ->condition('webform_id', $webform_id);

    foreach (self::ALLOWED_DATETIME_QUERY_PARAMS as $param => $operator) {
      $errors = $this->updateSubmissionQuery($this->currentRequest, $submissionQuery, $param, $operator, $result);

      if (isset($errors['error'])) {
        return new ModifiedResourceResponse($errors, Response::HTTP_BAD_REQUEST);
      }
    }

    // Complete query.
    $submissionQuery->accessCheck(FALSE);
    $sids = $submissionQuery->execute();

    $submissionData = [];

    if (!empty($sids)) {
      $submissions = $submissionEntityStorage->loadMultiple($sids);

      foreach ($submissions as $submission) {
        $url = Url::fromRoute(
          'rest.webform_rest_submission.GET',
          [
            'webform_id' => $webform_id,
            'uuid' => $submission->uuid(),
          ]
        )
          ->setAbsolute()
          ->toString(TRUE)->getGeneratedUrl();

        $submissionData[$submission->id()] = $url;
      }
    }

    $result['submissions'] = $submissionData;

    return new ModifiedResourceResponse($result);
  }

  /**
   * Updates submission query with request query parameters.
   *
   * @phpstan-param array<string, mixed> $result
   * @phpstan-return array<string, mixed>
   */
  private function updateSubmissionQuery(Request $request, QueryInterface $submissionQuery, string $parameter, string $operator, array &$result): array {
    // Handle starttime request query.
    $timeQuery = $request->query->get($parameter);

    if (!empty($timeQuery)) {
      try {
        $startTime = new \DateTime($timeQuery);
        $submissionQuery->condition('created', $startTime->getTimestamp(), $operator);
        $result[$parameter] = $timeQuery;
      }
      catch (\Exception $e) {
        $errors = [
          'error' => [
            'message' => $this->t('Could not generate DateTime from :time', [':time' => $timeQuery]),
          ],
        ];
      }
    }

    return $errors ?? [];
  }

}
