<?php

namespace Drupal\unhcr_salesforce_web\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use  Drupal\unhcr_salesforce\Event\SubmissionEvent;
use Drupal\unhcr_salesforce\Event\SubmissionEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class SalesforceCreateDonation.
 *
 * @package unhcr_salesforce
 */
class SalesforceCreateDonation implements EventSubscriberInterface {

  /**
   * @var \Drupal\unhcr_form_submissions\UnhcrFormSubmissionStorageInterface
   */
  protected $submissionStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->submissionStorage = $entityTypeManager->getStorage('unhcr_form_submission');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      SubmissionEvents::CREATE_DONATION => 'onCreateDonation',
    ];
  }

  /**
   * Processes the submission after a successful return from Salesforce.
   *
   * @param \Drupal\unhcr_form_submissions\Event\SubmissionEvent $event
   *   The event.
   */
  public function onCreateDonation(SubmissionEvent $event) {
    $data = $event->getData();
    /** @var \Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface $submission */
    // @TODO: Inject dependencies.
    $submission =  $this->submissionStorage->load($data['submission']);
    if (!empty($submission)) {
      // Include the Salesforce return into the debug information.
      $submission_data = $submission->get('submission_data')->value;
      $submission_data = Json::decode($submission_data);
      $submission_data = array_merge($submission_data, $data['submission_data']);
      $submission->set('submission_data', Json::encode($submission_data));
      // Set the state as successfully processed.
      $submission->set('submission_state', 'crm_success');
      $submission->save();

      // Also update the order to reflect the CRM status.
      $order = $submission->getOrder();
      if (!empty($order) && $order->hasField('field_remote_sent')) {
        $order->set('field_remote_sent', TRUE);
        $order->save();
      }
    }
  }

}
