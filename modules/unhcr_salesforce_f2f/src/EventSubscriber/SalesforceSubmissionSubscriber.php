<?php

namespace Drupal\unhcr_salesforce_f2f\EventSubscriber;

use Drupal\unhcr_form\Event\SubmissionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class SalesforceSubmissionSubscriber.
 *
 * @package unhcr_salesforce
 */
class SalesforceSubmissionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'unhcr_form.post_save' => 'onPostSave',
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
    /* @var \Drupal\Core\Queue\QueueInterface $queue */
    // @TODO: Inject dependencies.
    $queue = \Drupal::service('queue')->get('salesforce_queue');
    if ($options['update'] === TRUE && $submission->hasField('submission_state') && !$submission->get('submission_state')->isEmpty()) {
      // In case the user has signed in Assently, with or without bank
      // details, the submission is processed.
      $state = $submission->get('submission_state')->value;
      if ($state == 'signed' || $state == 'missing_bank_signed') {
        $queue->createItem($submission->id());
      }
    }
  }

}
