<?php

namespace Drupal\unhcr_salesforce_f2f\Plugin\QueueWorker;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Url;
use Drupal\error_notifier\ErrorNotifier;
use Drupal\unhcr_salesforce\Service\SalesforceApiInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Salesforce Queue queue worker.
 *
 * @QueueWorker(
 *   id = "salesforce_queue",
 *   title = @Translation("Salesforce Queue"),
 *   cron = {"time" = 60}
 * )
 */
class SalesforceQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use RfcLoggerTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Salesforce service.
   *
   * @var \Drupal\unhcr_salesforce\Service\SalesforceApiInterface
   */
  protected $salesforceClient;

  /**
   * A logging channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The error notifier service.
   *
   * @var \Drupal\error_notifier\ErrorNotifier
   */
  protected $errorNotifier;

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * Constructs a SalesforceQueue object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\unhcr_salesforce\Service\SalesforceApiInterface $salesforce_client
   *   The Salesforce service.
   * @param LoggerChannelFactoryInterface $logger_factory
   *   A logger factory.
   * @param ErrorNotifier $error_notifier
   *   The error notifier service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A config factory for retrieving required config objects.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, SalesforceApiInterface $salesforce_client, LoggerChannelFactoryInterface $logger_factory, ErrorNotifier $error_notifier, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->salesforceClient = $salesforce_client;
    $this->logger = $logger_factory->get('salesforce_queue');
    $this->errorNotifier = $error_notifier;
    $this->config = $config_factory->get('unhcr_salesforce.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('unhcr_salesforce.client'),
      $container->get('logger.factory'),
      $container->get('error_notifier'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    $this->logger->log($level, $message, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    /* @var \Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface $submission */
    $submission = $this->entityTypeManager->getStorage('unhcr_form_submission')->load($data);
    if (!$submission) {
      $this->warning('Failed to find a submission when trying to create a Salesforce Autogiro. Submission id was @id, dropped it from the queue.', ['@id' => $data]);
      return;
    }

    $signed = FALSE;
    switch ($submission->get('submission_state')->value) {
      case 'signed':
        $signed = TRUE;
        $this->info('Sending submission @id to Salesforce', ['@id' => $submission->id()]);
        break;

      case 'missing_bank_interest_queued':
      case 'missing_bank_signed':
        $signed = TRUE;
        $this->info('Sending submission @id to Salesforce without bank details', ['@id' => $submission->id()]);
        break;

      case 'created_bisnode':
      case 'missing_bank_interest_created':
        $this->warning('Submission @id was already sent to Salesforce, skipping.', ['@id' => $submission->id()]);
        return;

      default:
        $this->error('Submission @id is in the wrong state to be sent to Salesforce, skipping.', ['@id' => $submission->id()]);
        return;
    }

    try {
      $submission_data = Json::decode($submission->get('submission_data')->value, TRUE);
      $donation_data = [
        'data' => [],
      ];
      $date = new DrupalDateTime();
      $continuation_url = Url::fromRoute('unhcr_form.assently.create_secondary', ['submission' => $submission->id(), 'uuid' => $submission->uuid(),], ['absolute' => TRUE])->toString();

      if ($submission->get('submission_state')->value === 'missing_bank_signed') {
        $donation_data['data'][] = [
          'attributes' => [
            'sObject' => 'gcdt__Holding__c',
          ],
          'record' => [
            'Bank_Account_Number_S4U__c' => $submission_data['bank_number'],
            'gcdt__Process_Type__c' => 'WebF2FContinuation',
            'Sign_Up_Continuation_ID_S4U__c' => $submission->id(),
            'Sign_Up_Continuation_URL_S4U__c' => $continuation_url,
          ],
        ];
      }
      else {
        $donation_data['data'][] = [
          'attributes' => [
            'sObject' => 'Contact',
            'referenceId' => 'CONTACT',
            'matchRecord' => 'true',
            'doNotOverride' => 'unig__Source_Type__c,unig__Source_Campaign__c',
          ],
          'record' => [
            'Personal_ID_S4U__c' => $submission_data['pnum'],
            'FirstName' => $submission_data['first_name'],
            'LastName' => $submission_data['last_name'],
            'Email' => $submission_data['email'],
            'MailingCity' => $submission_data['city'],
            'MailingStreet' => $submission_data['street_address'],
            'MailingPostalCode' => (int) str_replace(' ', '', $submission_data['postal_code']),
            'unig__Source_Type__c' => 'Donation',
            'unig__Source_Campaign__c' => $submission->get('campaign')->value,
            'MobilePhone' => !empty($submission_data['mobile_phone']) ? '46' . substr($submission_data['mobile_phone'], 1) : NULL,
          ],
        ];
        // Ensure empty records are not sent to SF to avoid overrides.
        $not_nullable_fields = [
          'Personal_ID_S4U__c',
          'FirstName',
          'LastName',
          'Email',
          'Phone',
          'MobilePhone',
          'MailingCity',
          'MailingStreet',
          'MailingPostalCode',
        ];
        foreach ($not_nullable_fields as $field) {
          if (isset($data['data'][0]['record'][$field]) && empty($data['data'][0]['record'][$field])) {
            unset($data['data'][0]['record'][$field]);
          }
        }
        $donation_data['data'][] = [
          'attributes' => [
            'sObject' => 'gcdt__Holding__c',
          ],
          'record' => [
            'gcdt__Contact__c' => '@CONTACT',
            'gcdt__Recurring_Start_Date__c' => $date->format('Y-m-d'),
            'gcdt__Recurring_Amount__c' => (int) $submission_data['amount'],
            'gcdt__Payment_Method__c' => 'Autogiro',
            'gcdt__Campaign__c' => $submission->get('campaign')->value,
            'Recruiter_S4U__c' => $submission->get('recruiter')->value,
            'Mandate_Signed_S4U__c' => $signed,
            'Bank_Account_Number_S4U__c' => $submission_data['bank_number'] ?? '',
            'CurrencyISOCode' => 'SEK',
            'gcdt__Process_Type__c' => 'WebRegular',
            'Sign_Up_Continuation_URL_S4U__c' => empty($submission_data['bank_number']) ? $continuation_url : '',
            'Sign_Up_Continuation_ID_S4U__c' => $submission->id(),
          ],
        ];
      }

      $donor_info = $this->salesforceClient->createDonation($donation_data, ['type' => 'recurring', 'submission_data' => $submission_data, 'submission' => $submission->id()]);
      if (isset($donor_info->data['errors'])) {
        foreach ($donor_info->data['errors'] as $error) {
          $this->log('error', $error['message'] . ' ' . $error['detail']);
        }
        throw new \Exception('Salesforce error, try this one again later.');
      }
    }
    catch (\Exception $e) {
      throw new \Exception('Salesforce error, try this one again later.');
    }
  }

}
