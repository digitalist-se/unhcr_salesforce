<?php

namespace Drupal\unhcr_salesforce_web\EventSubscriber;

use Drupal\unhcr_form_submissions\Event\SubmissionEvent;
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
      'unhcr_form_submissions.post_save' => 'onPostSave',
    ];
  }

  /**
   * Adds the submission to the queue after being created.
   *
   * @param \Drupal\unhcr_form_submissions\Event\SubmissionEvent $event
   *   The event.
   */
  public function onPostSave(SubmissionEvent $event) {
    $submission = $event->getSubmission();
    // Skip submissions already processed successfully.
    if ($submission->hasField('submission_state') && $submission->get('submission_state')->value == 'crm_success') {
      return;
    }

    $options = $event->getOptions();
    /* @var \Drupal\Core\Queue\QueueInterface $queue */
    // @TODO: Inject dependencies.
    $queue = \Drupal::service('queue')->get('salesforce_queue');
    $create_submission = FALSE;

    // Monthly subscriptions go through Assently.
    if ($submission->hasField('assently_case') && !$submission->get('assently_case')->isEmpty()) {
      // For monthly subscriptions / Assently , the queues are populated on
      // submission update.
      if ($options['update'] === TRUE) {
        /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
        $order = $submission->get('commerce_order')->entity;
        // Signing on paper doesn't have much on it, submission needs to be
        // pushed to Salesforce.
        if ($order->getData('subscription_payment_type') == 'paper') {
          $create_submission = TRUE;
        }
        elseif ($submission->hasField('submission_state') && !$submission->get('submission_state')->isEmpty()) {
          // In case the user has signed in Assently, with or without bank
          // details, the submission is processed.
          $state = $submission->get('submission_state')->value;
          if ($state == 'signed' || $state == 'missing_bank_signed') {
            $create_submission = TRUE;
          }
        }
      }
    } else {
      // In the rest of cases, only add new submissions to the Salesforce queue.
      if ($options['update'] === FALSE) {
        $create_submission = TRUE;
      }
    }

    if ($create_submission) {
      $queue->createItem($submission->id());
    }
  }

}
