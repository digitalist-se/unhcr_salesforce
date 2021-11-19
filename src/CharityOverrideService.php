<?php

namespace Drupal\unhcr_salesforce;

use Drupal\bisnodecharity\CharityService;
use Drupal\bisnodecharity\Exception\ServerException;

/**
 * Class CharityOverrideService.
 *
 * @package unhcr_salesforce
 */
class CharityOverrideService extends CharityService {

  public function getPurposes($reset = FALSE) {
    return [];
  }

  public function getShopitems($reset = FALSE) {
    return [];
  }

  public function getDonationTypes($reset = FALSE) {
    return [];
  }

  public function getCampaigns($reset = FALSE) {
    return [];
  }

  public function getRecruiters($reset = FALSE) {
    return [];
  }

  public function getRecruiterCities($reset = FALSE) {
    return [];
  }

  public function getDonor($donorId) {
    return [];
  }

  public function updateDonor($donorId, $donorUpdate) {
    return [];
  }

  public function addAddress($donorId, $addressData, $addressIndex = 1) {
  }

  public function updateAddress($addressId, $addressUpdate) {
  }

  public function extractAddressId($donor) {
    return NULL;
  }

  public function getCollectionGet($firstName, $lastName, $eventDate, $donationTypeId) {
    return [];
  }

  public function getAutogiro($ssn) {
    return NULL;
  }

  public function getDonorMandates($donorId) {
    return [];
  }

  public function getPaymentPlans($donorId, $mandateId) {
    return [];
  }

  public function createAutogiro(array $data) {
    throw new ServerException;
  }

  public function createDonation(array $data) {
    throw new ServerException;
  }

  public function getDonations($donor_id) {
    return [];
  }

  public function getDonation($donor_id, $donation_id) {
    return [];
  }

}
