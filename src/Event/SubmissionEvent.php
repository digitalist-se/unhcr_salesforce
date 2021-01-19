<?php

namespace Drupal\unhcr_salesforce\Event;

use Symfony\Component\EventDispatcher\Event;
use Drupal\salesforce\Rest\RestResponse;

/**
 * Defines the event for donations via Salesforce.
 */
class SubmissionEvent extends Event {

  /**
   * The wrapped response object.
   *
   * @var \Drupal\salesforce\Rest\RestResponse
   */
  protected $response;

  /**
   * Data submitted to Salesforce.
   *
   * @var array
   */
  protected $data;

  /**
   * Constructs a new SubmissionEvent.
   *
   * @param \Drupal\salesforce\Rest\RestResponse $response
   *   The wrapped response object.
   * @param array
   *   Data submitted to Salesforce.
   */
  public function __construct(RestResponse $response, array $data = []) {
    $this->response = $response;
    $this->data = $data;
  }

  /**
   * Gets the response.
   *
   * @return \Drupal\salesforce\Rest\RestResponse
   *   The wrapped response object.
   */
  public function getResponse() {
    return $this->response;
  }

  /**
   * Gets the submitted data.
   *
   * @return array
   *   Data submitted to Salesforce.
   */
  public function getData() {
    return $this->data ?? [];
  }

}
