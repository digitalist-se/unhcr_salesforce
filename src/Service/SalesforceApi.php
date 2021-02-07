<?php

namespace Drupal\unhcr_salesforce\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\salesforce\Exception;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\SelectQuery;
use Drupal\unhcr_salesforce\Event\SubmissionEvent;
use Drupal\unhcr_salesforce\Event\SubmissionEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class SalesforceApi
 *
 * Wrapper for the Salesforce API.
 */
class SalesforceApi implements SalesforceApiInterface {

  use StringTranslationTrait;

  /**
   * Rest client service.
   *
   * @var \Drupal\salesforce\Rest\RestClientInterface
   */
  protected $sfapi;

  /**
   * The Drupal config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a SalesforceApi object.
   *
   * @param \Drupal\salesforce\Rest\RestClientInterface $sfapi
   *   RestClient object.
   * @param ConfigFactoryInterface $configFactory
   *   The Drupal config factory.
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher used to notify subscribers of config import events.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(RestClientInterface $sfapi, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager, EventDispatcherInterface $eventDispatcher, LoggerChannelFactoryInterface $loggerFactory) {
    $this->sfapi = $sfapi;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->eventDispatcher = $eventDispatcher;
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function getCampaign($campaign_id) {
    try {
      $soql_query = new SelectQuery('Campaign');
      $soql_query->fields = ['Id', 'Name', 'City_S4U__c', 'unig__Sub_Channel__c'];
      $soql_query->addCondition('IsActive', 'TRUE');
      $soql_query->addCondition('Id', "'$campaign_id'");

      $config = $this->configFactory->get('unhcr_salesforce.settings');
      if ($salesforce_campaign_field = $config->get('salesforce_campaign_field')) {
        $soql_query->addCondition($salesforce_campaign_field, 'TRUE');
      }
      $records = $this->sfapi->query($soql_query)->records();
      return current($records);
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCampaigns() {
    try {
      $soql_query = new SelectQuery('Campaign');
      $soql_query->fields = ['Id', 'Name'];
      $soql_query->addCondition('IsActive', 'TRUE');

      $config = $this->configFactory->get('unhcr_salesforce.settings');
      if ($salesforce_campaign_field = $config->get('salesforce_campaign_field')) {
        $soql_query->addCondition($salesforce_campaign_field, 'TRUE');
      }
      return $this->sfapi->query($soql_query)->records();
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCampaignOptions() {
    $options = [];
    foreach ($this->getCampaigns() as $campaign) {
      $options[$campaign->field('Id')] = $campaign->field('Name');
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecruiters() {
    try {
      $soql_query = new SelectQuery('Recruiter__c');
      $soql_query->fields = ['Id', 'Name'];
      $soql_query->addCondition('Active_s4u__c', 'TRUE');
      return $this->sfapi->query($soql_query)->records();
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRecruiterOptions() {
    $options = [];
    foreach ($this->getRecruiters() as $recruiter) {
      $options[$recruiter->field('Id')] = $recruiter->field('Name');
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getContactBySSN($ssn) {
    try {
      $soql_query = new SelectQuery('Contact');
      $soql_query->fields = ['Id', 'unig__Active_Recurring_Donation__c'];
      $soql_query->addCondition('Personal_ID_S4U__c', "'$ssn'");
      $soql_query->addCondition('unig__Active_Recurring_Donation__c', 'TRUE');
      $soql_query->limit = 1;
      $contacts = $this->sfapi->query($soql_query)->records();
      return reset($contacts);
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function createDonation(array $data, array $metadata = []) {
    try {
      $response = $this->sfapi->apiCall('/services/apexrest/gcis/v1/data', $data, 'PUT', TRUE);
      // Handle errors.
      if (isset($response->data['errors'])) {
        foreach ($response->data['errors'] as $error) {
          $this->log('error', $error['message'] . ' ' . $error['detail']);
        }
        throw new \Exception('Salesforce error, try this one again later. Marketing Cloud has not been triggered.');
      }
      // Allow other modules to act after the donation has been created.
      $event = new SubmissionEvent($response, $metadata);
      $this->eventDispatcher->dispatch(SubmissionEvents::CREATE_DONATION, $event);
      return $response;
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    $this->loggerFactory->get('salesforce_queue')->log($level, $message, $context);
  }

}
