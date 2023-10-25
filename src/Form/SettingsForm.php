<?php

namespace Drupal\unhcr_salesforce\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\unhcr_salesforce\Service\SalesforceApiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures Salesforce settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The Salesforce service.
   *
   * @var \Drupal\unhcr_salesforce\Service\SalesforceApiInterface
   */
  protected $salesforceClient;

  /**
   * The logger.
   *
   * @var \Drupal\Core\logger\LoggerChannelInterface
   */
  protected $logger;


  /**
   * Constructs SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\unhcr_salesforce\Service\SalesforceApiInterface $salesforce_client
   *   The Salesforce service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, SalesforceApiInterface $salesforce_client, LoggerInterface $logger) {
    $this->salesforceClient = $salesforce_client;
    $this->logger = $logger;
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('unhcr_salesforce.client'),
      $container->get('logger.factory')->get('unhcr_salesforce')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unhcr_salesforce';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['unhcr_salesforce.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    try {
      $config = $this->config('unhcr_salesforce.settings');
      $form['salesforce_campaign_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Select which field to filter campaigns by on Salesforce'),
        '#default_value' => $config->get('salesforce_campaign_field'),
        '#empty_option' => $this->t('- Show all campaigns -'),
        '#options' => [
          'Display_in_F2F_S4U__c' => $this->t('Face 2 Face'),
          'Display_in_Drupal_S4U__c' => $this->t('Main website'),
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Error building form: @message', ['@message' => $e->getMessage()]);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    if ($form_state->hasValue('salesforce_campaign_field')) {
      $config = $this->config('unhcr_salesforce.settings');
      $config->set('salesforce_campaign_field', $form_state->getValue('salesforce_campaign_field'));
      $config->save();
    }
  }

}
