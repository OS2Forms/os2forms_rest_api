<?php

namespace Drupal\os2forms_rest_api\Plugin\rest\resource;

use Drupal\Core\Url;
use Drupal\os2forms_rest_api\WebformHelper;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
   * The webform helper.
   *
   * @var \Drupal\os2forms_rest_api\WebformHelper
   */
  private $webformHelper;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $database;

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $instance->currentRequest = $container->get('request_stack')->getCurrentRequest();
    $instance->webformHelper = $container->get(WebformHelper::class);
    $instance->database = $container->get('database');

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

    $requestQuery = $this->currentRequest->query;

    $query = 'SELECT sid, uuid FROM webform_submission WHERE webform_id = :webform_id';

    foreach (self::ALLOWED_DATETIME_QUERY_PARAMS as $param => $operator) {
      $value = $requestQuery->get($param);

      if (!empty($value)) {
        try {
          $dateTime = new \DateTimeImmutable($value);
          $query .= sprintf(' AND created %s %s', $operator, $dateTime->getTimestamp());
          $result[$param] = $value;
        }
        catch (\Exception $e) {
          $errors = [
            'error' => [
              'message' => $this->t('Invalid :param: :value', [':param' => $param, ':value' => $value]),
            ],
          ];

          return new ModifiedResourceResponse($errors, Response::HTTP_BAD_REQUEST);
        }
      }
    }

    $submissions = $this->database->query(
      $query,
      [':webform_id' => $webform_id]
    )->fetchAllKeyed();

    // Generate submission URLs.
    try {
      $result['submissions'] = array_map(
        static fn($submission) => Url::fromRoute(
          'rest.webform_rest_submission.GET',
          [
            'webform_id' => $webform_id,
            'uuid' => $submission,
          ]
        )
          ->setAbsolute()
          ->toString(TRUE)->getGeneratedUrl(),
        $submissions ?: []
      );
    }
    catch (\Exception $e) {
      $errors = [
        'error' => [
          'message' => $this->t('Could not generate submission URLs'),
        ],
      ];

      return new ModifiedResourceResponse($errors, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new ModifiedResourceResponse($result);
  }

}
