<?php

namespace Drupal\unhcr_salesforce_charity;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

class UnhcrSalesforceCharityServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container
      ->getDefinition('bisnodecharity.service')
      ->setClass('Drupal\unhcr_salesforce_charity\CharityOverrideService');
  }

}
