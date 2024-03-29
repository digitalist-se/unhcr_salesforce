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
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

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
    $this->logger = $loggerFactory->get('unhcr_salesforce');
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
      $this->logger->error('Error getting campaign: @message', ['@message' => $e->getMessage()]);
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
      $this->logger->error('Error getting campaigns: @message', ['@message' => $e->getMessage()]);
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
      $this->logger->error('Error getting recruiters: @message', ['@message' => $e->getMessage()]);
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
      $this->logger->error('Error getting contact by SSN: @message', ['@message' => $e->getMessage()]);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTributes() {
    try {
      $soql_query = new SelectQuery('Tribute_Collection__c');
      $soql_query->fields = [
        'Id',
        'Address_City__c',
        'Address_Postal_Zip__c',
        'Address_Street__c',
        'Formal_Name_Of_Honoree__c',
        'Notification_Date__c',
        'Location_Name__c',
      ];
      $soql_query->addCondition('Active__c', 'TRUE');
      return $this->sfapi->query($soql_query)->records();
    }
    catch (Exception $e) {
      $this->logger->error('Error getting tributes: @message', ['@message' => $e->getMessage()]);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEarmarks() {
    try {
      $soql_query = new SelectQuery('Campaign');
      $soql_query->fields = ['Id', 'Name', 'Display_Friendly_Name__c'];
      $soql_query->addCondition('IsActive', 'TRUE');
      $soql_query->addCondition('Display_as_Honoree_Campaign__c', 'TRUE');
      return $this->sfapi->query($soql_query)->records();
    }
    catch (Exception $e) {
      $this->logger->error('Error getting earmarks: @message', ['@message' => $e->getMessage()]);
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
      // Carry over salesforce request for debugging purposes.
      $metadata['submission_data']['salesforce_response'] = $response->data;
      // Allow other modules to act after the donation has been created.
      $event = new SubmissionEvent($response, $metadata);
      $this->eventDispatcher->dispatch($event, SubmissionEvents::CREATE_DONATION);
      return $response;
    }
    catch (Exception $e) {
      $this->logger->error('Error creating donation: @message', ['@message' => $e->getMessage()]);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    $this->logger->log($level, $message, $context);
  }

}
