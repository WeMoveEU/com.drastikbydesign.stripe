<?php

/*
 * Payment Processor class for Stripe
 */

class CRM_Core_Payment_Stripe extends CRM_Core_Payment {

  /**
   *
   * @var string
   */
  protected $_stripeAPIVersion = '2018-11-08';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   */
  private static $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * TRUE if we are dealing with a live transaction
   *
   * @var boolean
   */
  private $_islive = FALSE;

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live' ? 1 : 0);
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Stripe');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return null|string
   *   The error message if any.
   */
  public function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Stripe Payment Processor settings.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Stripe Payment Processor settings.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * We can use the smartdebit processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit smartdebit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  /**
   * We can configure a start date for a smartdebit mandate
   * @return bool
   */
  public function supportsFutureRecurStartDate() {
    return FALSE;
  }

  /**
   * Get the currency for the transaction.
   *
   * Handle any inconsistency about how it is passed in here.
   *
   * @param $params
   *
   * @return string
   */
  public function getAmount($params) {
    // Stripe amount required in cents.
    $amount = number_format($params['amount'], 2, '.', '');
    $amount = (int) preg_replace('/[^\d]/', '', strval($amount));
    return $amount;
  }

  /**
   * Set API parameters for Stripe (such as identifier, api version, api key)
   */
  public function setAPIParams() {
    // Set plugin info and API credentials.
    \Stripe\Stripe::setAppInfo('CiviCRM', CRM_Utils_System::version(), CRM_Utils_System::baseURL());
    \Stripe\Stripe::setApiKey($this->_paymentProcessor['user_name']);
    \Stripe\Stripe::setApiVersion($this->_stripeAPIVersion);
  }

  /**
   * Handle an error from Stripe API and notify the user
   *
   * @param array $err
   * @param string $bounceURL
   *
   * @return string errorMessage (or statusbounce if URL is specified)
   */
  public static function handleErrorNotification($err, $bounceURL = NULL) {
    $errorMessage = 'Payment Response: <br />' .
      'Type: ' . $err['type'] . '<br />' .
      'Code: ' . $err['code'] . '<br />' .
      'Message: ' . $err['message'] . '<br />';

    Civi::log()->debug('Stripe Payment Error: ' . $errorMessage);

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($errorMessage, $bounceURL, 'Payment Error');
    }
    return $errorMessage;
  }

  /**
   * Stripe exceptions contain a json object in the body "error". This function extracts and returns that as an array.
   * @param String $op
   * @param Exception $e
   * @param Boolean $log
   *
   * @return array $err
   */
  public static function parseStripeException($op, $e, $log = FALSE) {
    $body = $e->getJsonBody();
    if ($log) {
      Civi::log()->debug("Stripe_Error {$op}: " . print_r($body, TRUE));
    }
    $err = $body['error'];
    if (!isset($err['code'])) {
      // A "fake" error code
      $err['code'] = 9000;
    }
    return $err;
  }

  /**
   * Create or update a Stripe Plan
   *
   * @param array $params
   * @param integer $amount
   *
   * @return \Stripe\Plan
   */
  public function createPlan($params, $amount) {
    $currency = strtolower($params['currencyID']);
    $planId = "every-{$params['frequency_interval']}-{$params['frequency_unit']}-{$amount}-" . $currency;
    if (isset($params['membership_type_tag'])) {
      $planId = $params['membership_type_tag'] . $planId;
    }

    if (!$this->_islive) {
      $planId .= '-test';
    }

    // Try and retrieve existing plan from Stripe
    // If this fails, we'll create a new one
    try {
      $plan = \Stripe\Plan::retrieve($planId);
    }
    catch (Stripe\Error\InvalidRequest $e) {
      $err = self::parseStripeException('plan_retrieve', $e, FALSE);
      if ($err['code'] == 'resource_missing') {
        $formatted_amount = number_format(($amount / 100), 2);
        $productName = "CiviCRM " . (isset($params['membership_name']) ? $params['membership_name'] . ' ' : '') . "every {$params['frequency_interval']} {$params['frequency_unit']}(s) {$formatted_amount}{$currency}";
        if (!$this->_islive) {
          $productName .= '-test';
        }
        $product = \Stripe\Product::create(array(
          "name" => $productName,
          "type" => "service"
        ));
        // Create a new Plan.
        $stripePlan = array(
          'amount' => $amount,
          'interval' => $params['frequency_unit'],
          'product' => $product->id,
          'currency' => $currency,
          'id' => $planId,
          'interval_count' => $params['frequency_interval'],
        );
        $plan = \Stripe\Plan::create($stripePlan);
      }
    }

    return $plan;
  }
  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      'stripe_token',
      'stripe_pub_key',
      'stripe_id',
    );
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    $creditCardType = array('' => ts('- select -')) + CRM_Contribute_PseudoConstant::creditCard();
    return array(
      'credit_card_number' => array(
        'htmlType' => 'text',
        'name' => 'credit_card_number',
        'title' => ts('Card Number'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 20,
          'maxlength' => 20,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'cvv2' => array(
        'htmlType' => 'text',
        'name' => 'cvv2',
        'title' => ts('Security Code'),
        'cc_field' => TRUE,
        'attributes' => array(
          'size' => 5,
          'maxlength' => 10,
          'autocomplete' => 'off',
        ),
        'is_required' => TRUE,
      ),
      'credit_card_exp_date' => array(
        'htmlType' => 'date',
        'name' => 'credit_card_exp_date',
        'title' => ts('Expiration Date'),
        'cc_field' => TRUE,
        'attributes' => CRM_Core_SelectValues::date('creditCard'),
        'is_required' => TRUE,
        'month_field' => 'credit_card_exp_date_M',
        'year_field' => 'credit_card_exp_date_Y',
        'extra' => ['class' => 'crm-form-select'],
      ),

      'credit_card_type' => array(
        'htmlType' => 'select',
        'name' => 'credit_card_type',
        'title' => ts('Card Type'),
        'cc_field' => TRUE,
        'attributes' => $creditCardType,
        'is_required' => FALSE,
      ),
      'stripe_token' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_token',
        'title' => 'Stripe Token',
        'attributes' => array(
          'id' => 'stripe-token',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_id' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_id',
        'title' => 'Stripe ID',
        'attributes' => array(
          'id' => 'stripe-id',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
      'stripe_pub_key' => array(
        'htmlType' => 'hidden',
        'name' => 'stripe_pub_key',
        'title' => 'Stripe Public Key',
        'attributes' => array(
          'id' => 'stripe-pub-key',
        ),
        'cc_field' => TRUE,
        'is_required' => TRUE,
      ),
    );
  }

  /**
   * Get form metadata for billing address fields.
   *
   * @param int $billingLocationID
   *
   * @return array
   *    Array of metadata for address fields.
   */
  public function getBillingAddressFieldsMetadata($billingLocationID = NULL) {
    $metadata = parent::getBillingAddressFieldsMetadata($billingLocationID);
    if (!$billingLocationID) {
      // Note that although the billing id is passed around the forms the idea that it would be anything other than
      // the result of the function below doesn't seem to have eventuated.
      // So taking this as a param is possibly something to be removed in favour of the standard default.
      $billingLocationID = CRM_Core_BAO_LocationType::getBilling();
    }

    // Stripe does not require the state/county field
    if (!empty($metadata["billing_state_province_id-{$billingLocationID}"]['is_required'])) {
      $metadata["billing_state_province_id-{$billingLocationID}"]['is_required'] = FALSE;
    }

    return $metadata;
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Set default values
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $publishableKey = CRM_Core_Payment_Stripe::getPublishableKey($paymentProcessorId);
    $defaults = [
      'stripe_id' => $paymentProcessorId,
      'stripe_pub_key' => $publishableKey,
    ];
    $form->setDefaults($defaults);
  }

   /**
   * Given a payment processor id, return the publishable key (password field)
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPublishableKey($paymentProcessorId) {
    try {
      $publishableKey = (string) civicrm_api3('PaymentProcessor', 'getvalue', array(
        'return' => "password",
        'id' => $paymentProcessorId,
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      return '';
    }
    return $publishableKey;
  }

  /**
   * Process payment
   * Submit a payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   * Payment processors should set payment_status_id.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    if (array_key_exists('credit_card_number', $params)) {
      $cc = $params['credit_card_number'];
      if (!empty($cc) && substr($cc, 0, 8) != '00000000') {
        Civi::log()->debug(ts('ALERT! Unmasked credit card received in back end. Please report this error to the site administrator.'));
      }
    }

    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $pendingStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    // If we have a $0 amount, skip call to processor and set payment_status to Completed.
    if (empty($params['amount'])) {
      $params['payment_status_id'] = $completedStatusId;
      return $params;
    }

    $this->setAPIParams();

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['stripe_error_url'] = NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      //See https://github.com/drastik/com.drastikbydesign.stripe/issues/205
      $entryURL = CRM_Utils_System::languageNegotiationURL($params['entryURL'], FALSE, TRUE);
      $parsed_url = parse_url($entryURL);
      $url_path = substr($parsed_url['path'], 1);
      $params['stripe_error_url'] = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }
    $amount = self::getAmount($params);

    // Use Stripe.js instead of raw card details.
    if (!empty($params['stripe_token'])) {
      $card_token = $params['stripe_token'];
    }
    else if(!empty(CRM_Utils_Array::value('stripe_token', $_POST, NULL))) {
      $card_token = CRM_Utils_Array::value('stripe_token', $_POST, NULL);
    }
    else {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Stripe.js token was not passed!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
    }

    $contactId = self::getContactId($params);
    $email = self::getBillingEmail($params, $contactId);

    // See if we already have a stripe customer
    $customerParams = [
      'contact_id' => $contactId,
      'card_token' => $card_token,
      'is_live' => $this->_islive,
      'processor_id' => $this->_paymentProcessor['id'],
      'email' => $email,
    ];

    $stripeCustomerId = CRM_Stripe_Customer::find($customerParams);

    // Customer not in civicrm database.  Create a new Customer in Stripe.
    if (!isset($stripeCustomerId)) {
      $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
    }
    else {
      // Customer was found in civicrm database, fetch from Stripe.
      $deleteCustomer = FALSE;
      try {
        $stripeCustomer = \Stripe\Customer::retrieve($stripeCustomerId);
      }
      catch (Exception $e) {
        $err = self::parseStripeException('retrieve_customer', $e, FALSE);
        if (($err['type'] == 'invalid_request_error') && ($err['code'] == 'resource_missing')) {
          $deleteCustomer = TRUE;
        }
        $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
        throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Charge: ' . $errorMessage);
      }

      if ($deleteCustomer || $stripeCustomer->isDeleted()) {
        // Customer doesn't exist, create a new one
        CRM_Stripe_Customer::delete($customerParams);
        try {
          $stripeCustomer = CRM_Stripe_Customer::create($customerParams, $this);
        }
        catch (Exception $e) {
          // We still failed to create a customer
          $errorMessage = self::handleErrorNotification($stripeCustomer, $params['stripe_error_url']);
          throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Customer: ' . $errorMessage);
        }
      }

      $stripeCustomer->card = $card_token;
      try {
        $stripeCustomer->save();
      }
      catch (Exception $e) {
        $err = self::parseStripeException('update_customer', $e, TRUE);
        if (($err['type'] == 'invalid_request_error') && ($err['code'] == 'token_already_used')) {
          // This error is ok, we've already used the token during create_customer
        }
        else {
          $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
          throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to update Stripe Customer: ' . $errorMessage);
        }
      }
    }

    // Prepare the charge array, minus Customer/Card details.
    if (empty($params['description'])) {
      $params['description'] = ts('Backend Stripe contribution');
    }

    // Handle recurring payments in doRecurPayment().
    if (CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID']) {
      // We set payment status as pending because the IPN will set it as completed / failed
      $params['payment_status_id'] = $pendingStatusId;
      return $this->doRecurPayment($params, $amount, $stripeCustomer);
    }

    // Stripe charge.
    $stripeChargeParams = [
      'amount' => $amount,
      'currency' => strtolower($params['currencyID']),
      'description' => $params['description'] . ' # Invoice ID: ' . CRM_Utils_Array::value('invoiceID', $params),
    ];

    // Use Stripe Customer if we have a valid one.  Otherwise just use the card.
    if (!empty($stripeCustomer->id)) {
      $stripeChargeParams['customer'] = $stripeCustomer->id;
    }
    else {
      $stripeChargeParams['card'] = $card_token;
    }

    try {
      $stripeCharge = \Stripe\Charge::create($stripeChargeParams);
    }
    catch (Exception $e) {
      $err = self::parseStripeException('charge_create', $e, FALSE);
      if ($e instanceof \Stripe\Error\Card) {
        civicrm_api3('Note', 'create', [
          'entity_id' => $params['contributionID'],
          'contact_id' => self::getContactId($params),
          'subject' => $err['type'],
          'note' => $err['code'],
          'entity_table' => 'civicrm_contribution',
        ]);
      }
      $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create Stripe Charge: ' . $errorMessage);
    }

    // Success!  Return some values for CiviCRM.
    $params['trxn_id'] = $stripeCharge->id;
    $params['payment_status_id'] = $completedStatusId;

    // Return fees & net amount for Civi reporting.
    // Uses new Balance Trasaction object.
    try {
      $stripeBalanceTransaction = \Stripe\BalanceTransaction::retrieve($stripeCharge->balance_transaction);
    }
    catch (Exception $e) {
      $err = self::parseStripeException('retrieve_balance_transaction', $e, FALSE);
      $errorMessage = self::handleErrorNotification($err, $params['stripe_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to retrieve Stripe Balance Transaction: ' . $errorMessage);
    }
    $params['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    $params['net_amount'] = $stripeBalanceTransaction->net / 100;

    return $params;
  }

  /**
   * Submit a recurring payment using Stripe's PHP API:
   * https://stripe.com/docs/api?lang=php
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   * @param int $amount
   *   Transaction amount in USD cents.
   * @param object $stripeCustomer
   *   Stripe customer object generated by Stripe API.
   *
   * @return array
   *   The result in a nice formatted array (or an error object).
   *
   * @throws \CiviCRM_API3_Exception
   * @throws \CRM_Core_Exception
   */
  public function doRecurPayment(&$params, $amount, $stripeCustomer) {
    $requiredParams = ['contributionRecurID', 'frequency_unit'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        Civi::log()->error('Stripe doRecurPayment: Missing mandatory parameter: ' . $required);
        throw new CRM_Core_Exception('Stripe doRecurPayment: Missing mandatory parameter: ' . $required);
      }
    }

    // Make sure frequency_interval is set (default to 1 if not)
    empty($params['frequency_interval']) ? $params['frequency_interval'] = 1 : NULL;

    $amount = $this->deprecatedHandleCiviDiscount($params, $amount, $stripeCustomer);

    // Create the stripe plan
    $planId = self::createPlan($params, $amount);

    // Attach the Subscription to the Stripe Customer.
    $subscriptionParams = [
      'prorate' => FALSE,
      'plan' => $planId,
    ];
    // Create the stripe subscription for the customer
    $stripeSubscription = $stripeCustomer->subscriptions->create($subscriptionParams);

    $recurParams = [
      'id' => $params['contributionRecurID'],
      'trxn_id' => $stripeSubscription->id,
      // FIXME processor_id is deprecated as it is not guaranteed to be unique, but currently (CiviCRM 5.9)
      //  it is required by cancelSubscription (where it is called subscription_id)
      'processor_id' => $stripeSubscription->id,
      'auto_renew' => 1,
      'cycle_day' => date('d'),
      'next_sched_contribution_date' => $this->calculateNextScheduledDate($params),
    ];
    if (!empty($params['installments'])) {
      // We set an end date if installments > 0
      if (empty($params['start_date'])) {
        $params['start_date'] = date('YmdHis');
      }
      if ($params['installments']) {
        $recurParams['end_date'] = $this->calculateEndDate($params);
      }
    }

    // Hook to allow modifying recurring contribution params
    CRM_Stripe_Hook::updateRecurringContribution($recurParams);
    // Update the recurring payment
    civicrm_api3('ContributionRecur', 'create', $recurParams);
    // Update the contribution status

    return $params;
  }

  /**
   * Calculate the end_date for a recurring contribution based on the number of installments
   * @param $params
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function calculateEndDate($params) {
    $requiredParams = ['start_date', 'installments', 'frequency_interval', 'frequency_unit'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'Stripe calculateEndDate: Missing mandatory parameter: ' . $required;
        Civi::log()->error($message);
        throw new CRM_Core_Exception($message);
      }
    }

    switch ($params['frequency_unit']) {
      case 'day':
        $frequencyUnit = 'D';
        break;

      case 'week':
        $frequencyUnit = 'W';
        break;

      case 'month':
        $frequencyUnit = 'M';
        break;

      case 'year':
        $frequencyUnit = 'Y';
        break;
    }

    $numberOfUnits = $params['installments'] * $params['frequency_interval'];
    $endDate = new DateTime($params['start_date']);
    $endDate->add(new DateInterval("P{$numberOfUnits}{$frequencyUnit}"));
    return $endDate->format('Ymd') . '235959';
  }

  /**
   * Calculate the end_date for a recurring contribution based on the number of installments
   * @param $params
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function calculateNextScheduledDate($params) {
    $requiredParams = ['frequency_interval', 'frequency_unit'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'Stripe calculateNextScheduledDate: Missing mandatory parameter: ' . $required;
        Civi::log()->error($message);
        throw new CRM_Core_Exception($message);
      }
    }
    if (empty($params['start_date']) && empty($params['next_sched_contribution_date'])) {
      $startDate = date('YmdHis');
    }
    elseif (!empty($params['next_sched_contribution_date'])) {
      if ($params['next_sched_contribution_date'] < date('YmdHis')) {
        $startDate = $params['next_sched_contribution_date'];
      }
    }
    else {
      $startDate = $params['start_date'];
    }

    switch ($params['frequency_unit']) {
      case 'day':
        $frequencyUnit = 'D';
        break;

      case 'week':
        $frequencyUnit = 'W';
        break;

      case 'month':
        $frequencyUnit = 'M';
        break;

      case 'year':
        $frequencyUnit = 'Y';
        break;
    }

    $numberOfUnits = $params['frequency_interval'];
    $endDate = new DateTime($startDate);
    $endDate->add(new DateInterval("P{$numberOfUnits}{$frequencyUnit}"));
    return $endDate->format('Ymd');
  }

  /**
   * @deprecated This belongs in a separate extension / hook as it's non-standard CiviCRM behaviour
   *
   * This adds some support for CiviDiscount on recurring contributions and changes the default behavior to discounting
   *  only the first of a recurring contribution set instead of all. (Intro offer) The Stripe procedure for discounting the
   *  first payment of subscription entails creating a negative invoice item or negative balance first,
   *  then creating the subscription at 100% full price. The customers first Stripe invoice will reflect the
   * discount. Subsequent invoices will be at the full undiscounted amount.
   * NB: Civi currently won't send a $0 charge to a payproc extension, but it should in this case. If the discount is
   *  the cost of initial payment, we still send the whole discount (or giftcard) as a negative balance.
   * Consider not selling giftards greater than your least expensive auto-renew membership until we can override this.
   *
   * @param $params
   * @param $amount
   * @param $stripeCustomer
   *
   * @return float|int
   * @throws \CiviCRM_API3_Exception
   */
  public function deprecatedHandleCiviDiscount(&$params, $amount, $stripeCustomer) {
    if (!empty($params['discountcode'])) {
      $discount_code = $params['discountcode'];
      $discount_object = civicrm_api3('DiscountCode', 'get', array(
        'sequential' => 1,
        'return' => "amount,amount_type",
        'code' => $discount_code,
      ));
      // amount_types: 1 = percentage, 2 = fixed, 3 = giftcard
      if ((!empty($discount_object['values'][0]['amount'])) && (!empty($discount_object['values'][0]['amount_type']))) {
        $discount_type = $discount_object['values'][0]['amount_type'];
        if ( $discount_type == 1 ) {
          // Discount is a percentage. Avoid ugly math and just get the full price using price_ param.
          foreach($params as $key=>$value){
            if("price_" == substr($key,0,6)){
              $price_param = $key;
              $price_field_id = substr($key,strrpos($key,'_') + 1);
            }
          }
          if (!empty($params[$price_param])) {
            $priceFieldValue = civicrm_api3('PriceFieldValue', 'get', array(
              'sequential' => 1,
              'return' => "amount",
              'id' => $params[$price_param],
              'price_field_id' => $price_field_id,
            ));
          }
          if (!empty($priceFieldValue['values'][0]['amount'])) {
            $priceset_amount = $priceFieldValue['values'][0]['amount'];
            $full_price = $priceset_amount * 100;
            $discount_in_cents = $full_price - $amount;
            // Set amount to full price.
            $amount = $full_price;
          }
        } else if ( $discount_type >= 2 ) {
          // discount is fixed or a giftcard. (may be > amount).
          $discount_amount = $discount_object['values'][0]['amount'];
          $discount_in_cents = $discount_amount * 100;
          // Set amount to full price.
          $amount = $amount + $discount_in_cents;
        }
      }
      // Apply the disount through a negative balance.
      $stripeCustomer->account_balance = -$discount_in_cents;
      $stripeCustomer->save();
    }
    return $amount;
  }

  /**
   * Default payment instrument validation.
   *
   * Implement the usual Luhn algorithm via a static function in the CRM_Core_Payment_Form if it's a credit card
   * Not a static function, because I need to check for payment_type.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    // Use $_POST here and not $values - for webform fields are not set in $values, but are in $_POST
    CRM_Core_Form::validateMandatoryFields($this->getMandatoryFields(), $_POST, $errors);
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool|object
   */
  public function cancelSubscription(&$message = '', $params = []) {
    $this->setAPIParams();

    $contributionRecurId = $this->getRecurringContributionId($params);
    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', array(
        'id' => $contributionRecurId,
      ));
    }
    catch (Exception $e) {
      return FALSE;
    }
    if (empty($contributionRecur['trxn_id'])) {
      CRM_Core_Session::setStatus(ts('The recurring contribution cannot be cancelled (No reference (trxn_id) found).'), 'Smart Debit', 'error');
      return FALSE;
    }

    try {
      $subscription = \Stripe\Subscription::retrieve($contributionRecur['trxn_id']);
      if (!$subscription->isDeleted()) {
        $subscription->cancel();
      }
    }
    catch (Exception $e) {
      $errorMessage = 'Could not delete Stripe subscription: ' . $e->getMessage();
      CRM_Core_Session::setStatus($errorMessage, 'Stripe', 'error');
      Civi::log()->debug($errorMessage);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Process incoming notification.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function handlePaymentNotification() {
    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);
    $ipnClass = new CRM_Core_Payment_StripeIPN($data);
    if ($ipnClass->main()) {
      http_response_code(200);
    }
  }


  /*******************************************************************
   * THE FOLLOWING FUNCTIONS SHOULD BE REMOVED ONCE THEY ARE IN CORE
   * getBillingEmail
   * getContactId
   ******************************************************************/

  /**
   * Get the billing email address
   *
   * @param array $params
   * @param int $contactId
   *
   * @return string|NULL
   */
  protected static function getBillingEmail($params, $contactId) {
    $billingLocationId = CRM_Core_BAO_LocationType::getBilling();

    $emailAddress = CRM_Utils_Array::value("email-{$billingLocationId}", $params,
      CRM_Utils_Array::value('email-Primary', $params,
        CRM_Utils_Array::value('email', $params, NULL)));

    if (empty($emailAddress) && !empty($contactId)) {
      // Try and retrieve an email address from Contact ID
      try {
        $emailAddress = civicrm_api3('Email', 'getvalue', array(
          'contact_id' => $contactId,
          'return' => ['email'],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        return NULL;
      }
    }
    return $emailAddress;
  }

  /**
   * Get the contact id
   *
   * @param array $params
   *
   * @return int ContactID
   */
  protected static function getContactId($params) {
    $contactId = CRM_Utils_Array::value('contactID', $params,
      CRM_Utils_Array::value('contact_id', $params,
        CRM_Utils_Array::value('cms_contactID', $params,
          CRM_Utils_Array::value('cid', $params, NULL
          ))));
    if (!empty($contactId)) {
      return $contactId;
    }
    // FIXME: Ref: https://lab.civicrm.org/extensions/stripe/issues/16
    // The problem is that when registering for a paid event, civicrm does not pass in the
    // contact id to the payment processor (civicrm version 5.3). So, I had to patch your
    // getContactId to check the session for a contact id. It's a hack and probably should be fixed in core.
    // The code below is exactly what CiviEvent does, but does not pass it through to the next function.
    $session = CRM_Core_Session::singleton();
    return $session->get('transaction.userID', NULL);
  }

  /**
   * Get url for users to manage this recurring contribution for this processor.
   * FIXME: Remove and increment min version once https://github.com/civicrm/civicrm-core/pull/13215 is merged.
   *
   * @param int $entityID
   * @param null $entity
   * @param string $action
   *
   * @return string
   */
  public function subscriptionURL($entityID = NULL, $entity = NULL, $action = 'cancel') {
    // Set URL
    switch ($action) {
      case 'cancel':
        if (!$this->supports('cancelRecurring')) {
          return NULL;
        }
        $url = 'civicrm/contribute/unsubscribe';
        break;

      case 'billing':
        //in notify mode don't return the update billing url
        if (!$this->supports('updateSubscriptionBillingInfo')) {
          return NULL;
        }
        $url = 'civicrm/contribute/updatebilling';
        break;

      case 'update':
        if (!$this->supports('changeSubscriptionAmount') && !$this->supports('editRecurringContribution')) {
          return NULL;
        }
        $url = 'civicrm/contribute/updaterecur';
        break;
    }

    $userId = CRM_Core_Session::singleton()->get('userID');
    $contactID = 0;
    $checksumValue = '';
    $entityArg = '';

    // Find related Contact
    if ($entityID) {
      switch ($entity) {
        case 'membership':
          $contactID = CRM_Core_DAO::getFieldValue("CRM_Member_DAO_Membership", $entityID, "contact_id");
          $entityArg = 'mid';
          break;

        case 'contribution':
          $contactID = CRM_Core_DAO::getFieldValue("CRM_Contribute_DAO_Contribution", $entityID, "contact_id");
          $entityArg = 'coid';
          break;

        case 'recur':
          $sql = "
    SELECT DISTINCT con.contact_id
      FROM civicrm_contribution_recur rec
INNER JOIN civicrm_contribution con ON ( con.contribution_recur_id = rec.id )
     WHERE rec.id = %1";
          $contactID = CRM_Core_DAO::singleValueQuery($sql, array(1 => array($entityID, 'Integer')));
          $entityArg = 'crid';
          break;
      }
    }

    // Add entity arguments
    if ($entityArg != '') {
      // Add checksum argument
      if ($contactID != 0 && $userId != $contactID) {
        $checksumValue = '&cs=' . CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID, NULL, 'inf');
      }
      return CRM_Utils_System::url($url, "reset=1&{$entityArg}={$entityID}{$checksumValue}", TRUE, NULL, FALSE, TRUE);
    }

    // Else login URL
    if ($this->supports('accountLoginURL')) {
      return $this->accountLoginURL();
    }

    // Else default
    return isset($this->_paymentProcessor['url_recur']) ? $this->_paymentProcessor['url_recur'] : '';
  }

  /**
   * Get the recurring contribution ID from parameters passed in to cancelSubscription
   * Historical the data passed to cancelSubscription is pretty poor and doesn't include much!
   *
   * @param array $params
   *
   * @return int|null
   */
  protected function getRecurringContributionId($params) {
    // Not yet passed, but could be added via core PR
    $contributionRecurId = CRM_Utils_Array::value('contribution_recur_id', $params);
    if (!empty($contributionRecurId)) {
      return $contributionRecurId;
    }

    // Not yet passed, but could be added via core PR
    $contributionId = CRM_Utils_Array::value('contribution_id', $params);
    try {
      return civicrm_api3('Contribution', 'getvalue', ['id' => $contributionId, 'return' => 'contribution_recur_id']);
    }
    catch (Exception $e) {
      $subscriptionId = CRM_Utils_Array::value('subscriptionId', $params);
      if (!empty($subscriptionId)) {
        try {
          return civicrm_api3('ContributionRecur', 'getvalue', ['processor_id' => $subscriptionId, 'return' => 'id']);
        }
        catch (Exception $e) {
          return NULL;
        }
      }
      return NULL;
    }
  }

}

