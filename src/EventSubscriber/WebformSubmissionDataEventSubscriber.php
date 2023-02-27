<?php

namespace Drupal\os2forms_rest_api\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform_rest\Event\WebformSubmissionDataEvent;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * WebformSubmissionEventSubscriber, for updating Webform Submission GET data.
 */
class WebformSubmissionDataEventSubscriber implements EventSubscriberInterface {
  use LoggerAwareTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Map from entity type to webform element types.
   */
  private const LINKED_ELEMENT_TYPES = [
    'file' => [
      'webform_image_file',
      'webform_document_file',
      'webform_video_file',
      'webform_audio_file',
      'managed_file',
    ],
  ];

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->setLogger($logger);
  }

  /**
   * Event handler.
   */
  public function onWebformSubmissionDataEvent(WebformSubmissionDataEvent $event): void {
    $linkedData = $this->buildLinked($event->getWebformSubmission()->getWebform(), $event->getData());

    if (!empty($linkedData)) {
      $event->setData($event->getData() + ['linked' => $linkedData]);
    }
  }

  /**
   * Builds linked entity data.
   *
   * @see https://support.deskpro.com/en/guides/developers/deskpro-api/basics/sideloading
   *
   * @phpstan-param array<string, mixed> $data
   * @phpstan-return array<string, mixed>
   */
  private function buildLinked(WebformInterface $webform, array $data): array {
    $linked = [];
    $elements = $webform->getElementsDecodedAndFlattened();

    foreach ($elements as $name => $element) {
      if (!isset($data[$name])) {
        continue;
      }

      $linkedEntityType = NULL;
      if (isset($element['#target_type'])) {
        $linkedEntityType = $element['#target_type'];
      }
      else {
        foreach (self::LINKED_ELEMENT_TYPES as $entityType => $elementTypes) {
          if (in_array($element['#type'], $elementTypes, TRUE)) {
            $linkedEntityType = $entityType;
            break;
          }
        }
      }

      if (NULL !== $linkedEntityType) {
        // $data[$name] is either a string id i.e. '127',
        // or an array of string ids i.e. ['127', '128'].
        // Casting to array allow us to handle both cases the same way.
        $values = (array) $data[$name];
        $entities = $this->entityTypeManager->getStorage($linkedEntityType)->loadMultiple($values);

        foreach ($entities as $value => $entity) {
          $link = [];
          if ($entity instanceof FileInterface) {
            $link = [
              'id' => $entity->id(),
              'url' => $entity->createFileUrl(FALSE),
              'mime_type' => $entity->getMimeType(),
              'size' => $entity->getSize(),
            ];
          }
          else {
            $this->logger->warning(sprintf('Unhandled linked entity type %s', $linkedEntityType));
          }
          if (!empty($link)) {
            $linked[$name][$value] = $link;
          }
        }
      }
    }
    return $linked;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      WebformSubmissionDataEvent::class => ['onWebformSubmissionDataEvent'],
    ];
  }

}
