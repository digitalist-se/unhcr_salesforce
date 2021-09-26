<?php

namespace Drupal\unhcr_salesforce_f2f\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\unhcr_form\Event\SubmissionEvent;
use Drupal\unhcr_form\Event\SubmissionEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class SalesforceSubmissionSubscriber.
 *
 * @package unhcr_salesforce
 */
class SalesforceSubmissionSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  public function __construct(QueueFactory $queueFactory ) {
    $this->queueFactory = $queueFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      SubmissionEvents::POST_SAVE => 'onPostSave',
    ];
  }

  /**
   * Adds the submission to the queue after being created.
   *
   * @param \Drupal\unhcr_form\Event\SubmissionEvent $event
   *   The event.
   */
  public function onPostSave(SubmissionEvent $event) {
    $submission = $event->getSubmission();
    $options = $event->getOptions();
    $queue = $this->queueFactory->get('salesforce_queue');
    if ($options['update'] === TRUE && $submission->hasField('submission_state') && !$submission->get('submission_state')->isEmpty()) {
      // In case the user has signed in Assently, with or without bank
      // details, the submission is processed.
      $state = $submission->get('submission_state')->value;
      if ($state === 'signed' || $state === 'missing_bank_signed' || $state === 'missing_bank_interest_queued') {
        $queue->createItem($submission->id());
      }
    }
  }

}
