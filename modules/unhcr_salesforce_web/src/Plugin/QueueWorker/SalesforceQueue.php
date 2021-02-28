<?php

namespace Drupal\unhcr_salesforce_web\Plugin\QueueWorker;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\error_notifier\ErrorNotifier;
use Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface;
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
  use StringTranslationTrait;

  // A number will be considered mobile if it starts with the following
  // prefixes: 4670, 4672, 4673, 4676, 4679.
  const MOBILE_PREFIXES = ['4670', '4672', '4673', '4676', '4679'];

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
   * The currency formatter.
   *
   * @var \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  protected $currencyFormatter;

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

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
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currency_formatter
   *   The currency formatter.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A config factory for retrieving required config objects.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, SalesforceApiInterface $salesforce_client, LoggerChannelFactoryInterface $logger_factory, ErrorNotifier $error_notifier, CurrencyFormatterInterface $currency_formatter, ConfigFactoryInterface $config_factory, RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->salesforceClient = $salesforce_client;
    $this->logger = $logger_factory->get('salesforce_queue');
    $this->errorNotifier = $error_notifier;
    $this->currencyFormatter = $currency_formatter;
    $this->config = $config_factory->get('unhcr_salesforce.settings');
    $this->renderer = $renderer;
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
      $container->get('commerce_price.currency_formatter'),
      $container->get('config.factory'),
      $container->get('renderer')
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
      $this->warning('Failed to find a submission when trying to process it with Salesforce. Submission id was @id, dropped it from the queue.', ['@id' => $data]);
      return;
    }

    // @TODO Review what makes a submission "valid".
    if (!$this->validateSubmissionState($submission)) {
      return;
    }
    $submission_data = Json::decode($submission->get('submission_data')->value, TRUE);

    // This is the place where decide if send autogiro or one time donation.
    switch ($submission_data['order_type']) {
      case 'unhcr_monthly_order_type':
        $type = 'recurring';
        $donation_data = $this->prepareAutoGiroData($submission, $submission_data);
        break;
      case 'unhcr_honorial_':
      case 'engasgava_order':
      case 'unhcr_one_time_company_':
      case 'unhcr_gift':
        $type = 'single';
        $donation_data = $this->prepareOneTimeData($submission, $submission_data);
        break;
    }
    if ($donation_data) {
      try {
        $this->salesforceClient->createDonation($donation_data, ['type' => $type, 'submission_data' => $submission_data, 'submission' => $submission->id()]);
      } catch (\Exception $e) {
        throw new \Exception('Salesforce error, try this one again later.');
      }
    }
  }

  /**
   * Prepare one time / memorial / gift donation submission data.
   *
   * @param \Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface $submission
   *   Submission entity.
   * @param array $submission_data
   *   Decoded submission data
   *
   * @return array
   *   Donation data array as expected by Give Clarity/Salesforce API
   */
  protected function prepareOneTimeData($submission, $submission_data) {
    $data = [
      'data' => [],
    ];

    $ssn = isset($submission_data['field_org_number']) ? $submission_data['field_org_number'] : (isset($submission_data['pnum']) ? $submission_data['pnum'] : '');
    $ssn = str_replace('-', '', $ssn);
    $date = new DrupalDateTime();
    $company_name = !empty($submission_data['field_company_name']) ? $submission_data['field_company_name'] : '';
    $shipping_street = $submission_data['street_address'];
    if (!empty($company_name)) {
      $shipping_street .= '\r\n' . $company_name;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $submission->get('commerce_order')->entity;
    $utm_codes = $this->getUTM($order);
    $gift_cert = $submission_data['order_type'] === 'unhcr_gift' &&
      $order->hasField('field_purchase_type') &&
      $order->get('field_purchase_type')->value == 'invoice';

    $phone_number = $this->processPhoneNumber($submission_data);
    if (in_array(substr($phone_number, 0, 4), self::MOBILE_PREFIXES)) {
      $mobile_number = $phone_number;
    }
    else {
      $landline_number = $phone_number;
    }

    switch ($submission_data['field_customer_type_value']) {
      case 'C':
        $data['data'][] = [
          'attributes' => [
            'sObject' => 'Account',
            'referenceId' => 'ACCOUNT',
            'matchRecord' => 'true',
            'doNotOverride' => 'unig__Partner_Type__c,unig__Partner_Sub_Type__c,unig__Office_Type__c,unig__Income_Team_Manual__c',
          ],
          'record' => [
            'Organisational_Number_S4U__c' => $ssn,
            'Name' => !empty($company_name) ? $company_name : $submission_data['first_name'] . ' ' . $submission_data['last_name'],
            'ShippingCity' => $submission_data['city'],
            'ShippingStreet' => $shipping_street,
            'ShippingPostalCode' => str_replace(' ', '', $submission_data['postal_code']),
            'unig__Partner_Type__c' => 'Corporate',
            'unig__Partner_Sub_Type__c' => 'SME',
            'unig__Office_Type__c' => 'Headquarters',
            'unig__Income_Team_Manual__c' => 'PPH',
            'unig__Industry_Sector__c' => 'Unknown',
          ],
        ];
        // Ensure empty records are not sent to SF to avoid overrides.
        // @TODO: this should be made generic, however we're at the end of the
        // project and the specs keep changing so leaving it like this for now.
        $not_nullable_fields = [
          'Organisational_Number_S4U__c',
          'Name',
          'ShippingCity',
          'ShippingStreet',
          'ShippingPostalCode',
        ];
        foreach ($not_nullable_fields as $field) {
          if (isset($data['data'][0]['record'][$field]) && empty($data['data'][0]['record'][$field])) {
            unset($data['data'][0]['record'][$field]);
          }
        }

        $data['data'][] = [
          'attributes' => [
            'sObject' => 'Contact',
            'referenceId' => 'CONTACT',
            'matchRecord' => 'true',
            'doNotOverride' => "unig__Source_Campaign__c,unig__Source_Type__c",
          ],
          'record' => [
            'npsp__Primary_Affiliation__c' => '@ACCOUNT',
            'FirstName' => $submission_data['first_name'],
            'LastName' => $submission_data['last_name'],
            'Email' => $submission_data['email'],
            'unig__Source_Type__c' => 'Donation',
            'unig__Source_Campaign__c' => $submission_data['field_charity_campaign'],
            'Phone' =>  $landline_number ?? '',
            'MobilePhone' => $mobile_number ?? '',
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
          if (isset($data['data'][1]['record'][$field]) && empty($data['data'][0]['record'][$field])) {
            unset($data['data'][1]['record'][$field]);
          }
        }

        $data['data'][] = [
          'attributes' => [
            'sObject' => 'gcdt__Holding__c',
          ],
          'record' => [
            'gcdt__Account__c' => '@ACCOUNT',
            'gcdt__Contact__c' => '@CONTACT',
            'gcdt__Payment_Method__c' => $this->getPaymentMethod($submission_data),
            'gcdt__Payment_Reference__c' => $submission_data['transaction_id'],
            'gcdt__Opportunity_Amount__c' => (int) $submission_data['amount'],
            'gcdt__Campaign__c' => $submission_data['field_charity_campaign'],
            'Giftshop_Summary_S4U__c' => $submission_data['order_type'] === 'unhcr_gift' ? $this->getGiftshopSummary($order) : '',
            'Is_Giftshop_Gift_S4U__c' => $submission_data['order_type'] === 'unhcr_gift',
            'Postal_Gift_Cert_Requested_S4U__c' => $gift_cert,
            'Drupal_Order_ID_S4U__c' => $submission_data['order_id'],
            'gcdt__Opportunity_CloseDate__c' => $date->format('Y-m-d'),
            'CurrencyISOCode' => 'SEK',
            'gcdt__Process_Type__c' => 'WebSingle',
            'Customer_Type_S4U__c' => 'Company',
            // UTM codes.
            "UTM_Source_S4U__c" => $utm_codes['source'],
            "UTM_Medium_S4U__c" => $utm_codes['medium'],
            "UTM_Campaign_S4U__c" => $utm_codes['campaign'],
            "UTM_Content_S4U__c" => $utm_codes['content'],
            "UTM_Term_S4U__c" => $utm_codes['term'],
          ],
        ];
        break;

      case 'P':
      default:
        $data['data'][] = [
          'attributes' => [
            'sObject' => 'Contact',
            'referenceId' => 'CONTACT',
            'matchRecord' => 'true',
            'doNotOverride' => 'Personal_ID_S4U__c,unig__Source_Campaign__c,unig__Source_Type__c',
          ],
          'record' => [
            'Personal_ID_S4U__c' => $ssn,
            'FirstName' => $submission_data['first_name'],
            'LastName' => $submission_data['last_name'],
            'Email' => $submission_data['email'],
            'MailingCity' => $submission_data['city'],
            'MailingStreet' => $shipping_street,
            'MailingPostalCode' => str_replace(' ', '', $submission_data['postal_code']),
            'unig__Source_Type__c' => 'Donation',
            'unig__Source_Campaign__c' => $submission_data['field_charity_campaign'],
            'Phone' =>  $landline_number ?? '',
            'MobilePhone' => $mobile_number ?? '',
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

        $data['data'][] = [
          'attributes' => [
            'sObject' => 'gcdt__Holding__c',
          ],
          'record' => [
            'gcdt__Contact__c' => '@CONTACT',
            'gcdt__Payment_Method__c' => $this->getPaymentMethod($submission_data),
            'gcdt__Payment_Reference__c' => $submission_data['transaction_id'],
            'gcdt__Opportunity_Amount__c' => (int) $submission_data['amount'],
            'gcdt__Campaign__c' => $submission_data['field_charity_campaign'],
            'Giftshop_Summary_S4U__c' => $submission_data['order_type'] === 'unhcr_gift' ? $this->getGiftshopSummary($order) : '',
            'Is_Giftshop_Gift_S4U__c' => $submission_data['order_type'] === 'unhcr_gift',
            'Postal_Gift_Cert_Requested_S4U__c' => $gift_cert,
            'Drupal_Order_ID_S4U__c' => $submission_data['order_id'],
            'gcdt__Opportunity_CloseDate__c' => $date->format('Y-m-d'),
            'CurrencyISOCode' => 'SEK',
            'gcdt__Process_Type__c' => 'WebSingle',
            'Customer_Type_S4U__c' => 'Private',
            // UTM codes.
            "UTM_Source_S4U__c" => $utm_codes['source'],
            "UTM_Medium_S4U__c" => $utm_codes['medium'],
            "UTM_Campaign_S4U__c" => $utm_codes['campaign'],
            "UTM_Content_S4U__c" => $utm_codes['content'],
            "UTM_Term_S4U__c" => $utm_codes['term'],
          ],
        ];
    }

    return $data;
  }

  /**
   * Prepare one time / memorial / gift donation submission data.
   *
   * @param \Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface $submission
   *   Submission entity.
   * @param array $submission_data
   *   Decoded submission data
   *
   * @return array
   *   Donation data array as expected by Give Clarity/Salesforce API
   */
  protected function prepareAutoGiroData($submission, $submission_data) {
    $data = [
      'data' => [],
    ];

    $ssn = isset($submission_data['field_org_number']) ? $submission_data['field_org_number'] : (isset($submission_data['pnum']) ? $submission_data['pnum'] : '');
    $ssn = str_replace('-', '', $ssn);
    $date = new DrupalDateTime();
    $signed = FALSE;
    if ($submission->hasField('submission_state') && !$submission->get('submission_state')->isEmpty()) {
      $state = $submission->get('submission_state')->value;
      $signed = ($state == 'signed' || $state == 'missing_bank_signed');
    }
    $company_name = !empty($submission_data['field_company_name']) ? $submission_data['field_company_name'] : '';
    $shipping_street = $submission_data['street_address'];
    if (!empty($company_name)) {
      $shipping_street .= '\r\n' . $company_name;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $submission->get('commerce_order')->entity;
    $utm_codes = $this->getUTM($order);

    $phone_number = $this->processPhoneNumber($submission_data);
    if (in_array(substr($phone_number, 0, 4), self::MOBILE_PREFIXES)) {
      $mobile_number = $phone_number;
    }
    else {
      $landline_number = $phone_number;
    }

    $data['data'][] = [
      'attributes' => [
        'sObject' => 'Contact',
        'referenceId' => 'CONTACT',
        'matchRecord' => 'true',
        'doNotOverride' => 'unig__Source_Campaign__c',
      ],
      'record' => [
        'Personal_ID_S4U__c' => $ssn,
        'FirstName' => $submission_data['first_name'],
        'LastName' => $submission_data['last_name'],
        'Email' => $submission_data['email'],
        'MailingCity' => $submission_data['city'],
        'MailingStreet' => $shipping_street,
        'MailingPostalCode' => str_replace(' ', '', $submission_data['postal_code']),
        'unig__Source_Type__c' => 'Donation',
        'unig__Source_Campaign__c' => $submission_data['field_charity_campaign'] ?? '',
        'Phone' =>  $landline_number ?? '',
        'MobilePhone' => $mobile_number ?? '',
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

    $data['data'][] = [
      'attributes' => [
        'sObject' => 'gcdt__Holding__c',
      ],
      'record' => [
        'gcdt__Contact__c' => '@CONTACT',
        'gcdt__Recurring_Start_Date__c' => $date->format('Y-m-d'),
        'gcdt__Recurring_Amount__c' => (int) $submission_data['amount'],
        'gcdt__Payment_Method__c' => 'Autogiro',
        'gcdt__Campaign__c' => $submission_data['field_charity_campaign'] ?? '',
        'Mandate_Signed_S4U__c' => $signed,
        'Bank_Account_Number_S4U__c' => $submission_data['bank_number'] ?? '',
        'CurrencyISOCode' => 'SEK',
        'gcdt__Process_Type__c' => 'WebRegular',
        'Drupal_Order_ID_S4U__c' => $submission_data['order_id'],
        // UTM codes.
        "UTM_Source_S4U__c" => $utm_codes['source'],
        "UTM_Medium_S4U__c" => $utm_codes['medium'],
        "UTM_Campaign_S4U__c" => $utm_codes['campaign'],
        "UTM_Content_S4U__c" => $utm_codes['content'],
        "UTM_Term_S4U__c" => $utm_codes['term'],
      ],
    ];

    return $data;
  }

  /**
   * Validate submission state.
   *
   * @param \Drupal\unhcr_form_submissions\Entity\UnhcrFormSubmissionInterface $submission
   *  Submission entity.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function validateSubmissionState(UnhcrFormSubmissionInterface $submission) {
    switch ($submission->get('submission_state')->value) {
      case 'signed':
        $this->info(
          'Sending submission @id to Salesforce',
          ['@id' => $submission->id()]
        );
        return TRUE;

      case 'missing_bank_interest_queued':
        $this->info(
          'Submission @id is being sent to Salesforce without bank details.',
          ['@id' => $submission->id()]
        );
        return TRUE;

      case 'created_bisnode':
      case 'missing_bank_interest_created':
        $this->warning(
          'Submission @id was already sent to Salesforce, skipping.',
          ['@id' => $submission->id()]
        );
        return FALSE;

      case 'error':
        if ($submission->get(
            'error_type'
          )->value === 'charity_communication_error') {
          $this->info(
            'Retrying sending previously errored submission @id top Salesforce.',
            ['@id' => $submission->id()]
          );
          return TRUE;
        }
        $this->error(
          'Submission @id is in error state @error_type, will not retry sending it.',
          [
            '@id' => $submission->id(),
            '@error_type' => $submission->getErrorTypeLabel(),
          ]
        );
        return FALSE;

      default:
        $this->error(
          'Submission @id is in the wrong state(@state) to be sent to Charity, skipping.',
          [
            '@id' => $submission->id(),
            '@state' => $submission->get('submission_state')->value,
          ]
        );
        return FALSE;
    }
  }

  /**
   * Summary of the gift donation items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string
   *   Concatenated list of the items included.
   */
  protected function getGiftshopSummary(OrderInterface $order) {
    $table = [
      '#type' => 'table',
      '#header' => [$this->t('Name'), $this->t('Qty'), $this->t('Price')],
    ];
    $rows = [];
    foreach ($order->getItems() as $item) {
      $rows[] = [
        $item->getPurchasedEntity()->getProduct()->label(),
        (int) $item->getQuantity(),
        (int) $this->currencyFormatter->format($item->getTotalPrice()->getNumber(), $item->getTotalPrice()->getCurrencyCode()),
      ];
    }
    $table['#rows'] = $rows;

    return $this->renderer->renderPlain($table);
  }

  /**
   * Returns the UTM codes from the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   UTM codes array.
   */
  protected function getUTM(OrderInterface $order) {
    $utm_codes = [];
    $utm_codes['source'] = $order->getData('utm_source', '');
    $utm_codes['medium'] = $order->getData('utm_medium', '');
    $utm_codes['campaign'] = $order->getData('utm_campaign', '');
    $utm_codes['content'] = $order->getData('utm_content', '');
    $utm_codes['term'] = $order->getData('utm_term', '');

    return $utm_codes;
  }

  /**
   * Get the payment method for a submission.
   *
   * @param array $submission_data
   *   The submission data.
   *
   * @return string
   *   The payment method.
   */
  protected function getPaymentMethod(array $submission_data) {
    $gateway_plugin = '';
    $payment_gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($submission_data['payment_gateway_id']);
    if ($payment_gateway) {
      $gateway_plugin = $payment_gateway->getPluginId();
    }
    // Salesforce values could be:
    // Autogiro: For monthly donations.
    // SMS
    // PGBG
    // PGBG OCR: 'Faktura' that physically gets sent out.
    // Swish
    // Internet Banking
    // Credit Card
    // Facebook
    // Betternow
    // Receipt
    // Other
    switch ($gateway_plugin) {
      case 'swedbank_pay_card':
        return 'Credit Card';
      case 'swedbank_pay_swish':
        return 'Swish';
      case 'swedbank_pay_trustly':
        return 'Internet Banking';
      case 'unhcr_onsite_invoice':
        return 'PGBG OCR';
      default:
        return 'Other';
    }
  }

  /**
   * Convert the phone number received to a more predictable format.
   *
   * Swedish phone number classification. This code is not pretty but it seems
   * Salesforce is not able to process is very well otherwise.
   *
   * @param array $submission_data
   *   The submission data.
   *
   * @return string
   *   The processed phone number.
   */
  protected function processPhoneNumber(array $submission_data) {
    if (!empty($submission_data['mobile_phone'])) {
      $phone_number = $submission_data['mobile_phone'];
      // Remove anything that is not a number.
      $phone_number = preg_replace('~\D~', '', $phone_number);

      // Remove the 46 in case is prepended and someone enters a 4607XXXXX
      // number.
      if (substr($phone_number, 0, 2 ) === '46') {
        $phone_number = substr($phone_number, 2);
      }

      // Remove 0 from the phone and prefix with 46 to make it more standard
      // with other systems.
      $phone_number = (int) $phone_number;
      $phone_number = '46' . $phone_number;
    }

    return $phone_number ?? '';
  }

}
