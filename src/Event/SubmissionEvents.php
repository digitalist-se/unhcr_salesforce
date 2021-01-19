<?php

namespace Drupal\unhcr_salesforce\Event;

/**
 * Defines events for donations via Salesforce.
 */
final class SubmissionEvents {

  /**
   * Create donation.
   *
   * @Event
   */
  const CREATE_DONATION = 'unhcr_salesforce.create_donation';

}
