<?php

namespace Drupal\unhcr_salesforce\Service;

interface SalesforceApiInterface {

  /**
   * Returns a campaign by id from Salesforce.
   *
   * @param string $campaign_id
   *   Campaign Id
   *
   * @return \Drupal\salesforce\SObject|bool
   *   Campaign Object or FALSE if not found.
   */
  public function getCampaign($campaign_id);

  /**
   * Returns the campaigns from Salesforce.
   *
   * @return \Drupal\salesforce\SObject[]
   *   Array of object campaigns.
   */
  public function getCampaigns();

  /**
   * Returns the campaigns from Salesforce to be rendered by a form element.
   *
   * @return array
   *   Array of id => name campaigns.
   */
  public function getCampaignOptions();

  /**
   * Returns the recruiters from Salesforce.
   *
   * @return \Drupal\salesforce\SObject[]
   *   Array of Salesforce recruiter object.
   */
  public function getRecruiters();

  /**
   * Returns the recruiters from Salesforce to be rendered by a form element.
   *
   * @return array
   *   Array of id => recruiter name.
   */
  public function getRecruiterOptions();

  /**
   * Returns a contact by SSN/S4U to check active donations.
   *
   * @param string $ssn
   *   SSN or S4UN.
   *
   * @return \Drupal\salesforce\SObject[]
   *   Array of Salesforce contact object.
   */
  public function getContactBySSN($ssn);

  /**
   * Create a donation in Salesforce.
   *
   * @param array $data
   *   Array of data.
   *
   * @return mixed
   */
  public function createDonation(array $data);

}
