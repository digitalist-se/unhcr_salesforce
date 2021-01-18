<?php

namespace Drupal\unhcr_salesforce\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\salesforce\Exception;
use Drupal\salesforce\Rest\RestClientInterface;
use Drupal\salesforce\SelectQuery;

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
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SalesforceApi object.
   *
   * @param \Drupal\salesforce\Rest\RestClientInterface $sfapi
   *   RestClient object.
   * @param ConfigFactoryInterface $configFactory
   *   The Drupal config factory.
   * @param EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(RestClientInterface $sfapi, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager) {
    $this->sfapi = $sfapi;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
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
  public function createDonation(array $data) {
    try {
      return $this->sfapi->apiCall('/services/apexrest/gcis/v1/data', $data, 'PUT', TRUE);
    }
    catch (Exception $e) {
      watchdog_exception('unhcr_salesforce', $e);
    }

    return [];
  }

}
