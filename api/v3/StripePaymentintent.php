<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * StripePaymentintent.create API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_stripe_paymentintent_create($params) {
  return _civicrm_api3_basic_create('CRM_Stripe_BAO_StripePaymentintent', $params, 'StripePaymentintent');
}

/**
 * StripePaymentintent.delete API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function civicrm_api3_stripe_paymentintent_delete($params) {
  return _civicrm_api3_basic_delete('CRM_Stripe_BAO_StripePaymentintent', $params);
}

/**
 * StripePaymentintent.get API
 *
 * @param array $params
 *
 * @return array API result descriptor
 */
function civicrm_api3_stripe_paymentintent_get($params) {
  return _civicrm_api3_basic_get('CRM_Stripe_BAO_StripePaymentintent', $params, TRUE, 'StripePaymentintent');
}

/**
 * StripePaymentintent.process API specification
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_stripe_paymentintent_process_spec(&$spec) {
  $spec['payment_method_id']['title'] = E::ts("Stripe generated code used to create a payment intent.");
  $spec['payment_method_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_method_id']['api.default'] = NULL;
  $spec['payment_intent_id']['title'] = ts("The payment intent id itself, if available.");
  $spec['payment_intent_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['payment_intent_id']['api.default'] = NULL;
  $spec['amount']['title'] = ts("The payment amount.");
  $spec['amount']['type'] = CRM_Utils_Type::T_STRING;
  $spec['amount']['api.default'] = NULL;
  $spec['capture']['title'] = ts("Whether we should try to capture the amount, not just confirm it.");
  $spec['capture']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['capture']['api.default'] = FALSE;
  $spec['description']['title'] = ts("Describe the payment.");
  $spec['description']['type'] = CRM_Utils_Type::T_STRING;
  $spec['description']['api.default'] = NULL;
  $spec['currency']['title'] = ts("Whether we should try to capture the amount, not just confirm it.");
  $spec['currency']['type'] = CRM_Utils_Type::T_STRING;
  $spec['currency']['api.default'] = CRM_Core_Config::singleton()->defaultCurrency;
  $spec['payment_processor_id']['title'] = ts("The stripe payment processor id.");
  $spec['payment_processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['payment_processor_id']['api.required'] = TRUE;
}

/**
 * StripePaymentintent.process API
 *
 * In the normal flow of a CiviContribute form, this will be called with a
 * payment_method_id (which is generated by Stripe via its javascript code),
 * in which case it will create a PaymentIntent using that and *attempt* to
 * 'confirm' it.
 *
 * This can also be called with a payment_intent_id instead, in which case it
 * will retrieve the PaymentIntent and attempt (again) to 'confirm' it. This
 * is useful to confirm funds after a user has completed SCA in their
 * browser.
 *
 * 'confirming' a PaymentIntent refers to the process by which the funds are
 * reserved in the cardholder's account, but not actually taken yet.
 *
 * Taking the funds ('capturing') should go through without problems once the
 * transaction has been confirmed - this is done later on in the process.
 *
 * Nb. confirmed funds are released and will become available to the
 * cardholder again if the PaymentIntent is cancelled or is not captured
 * within 1 week.
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \API_Exception
 * @throws \CiviCRM_API3_Exception
 * @throws \Stripe\Exception\UnknownApiErrorException
 */
function civicrm_api3_stripe_paymentintent_process($params) {
  if (class_exists('\Civi\Firewall\Firewall')) {
    if (!\Civi\Firewall\Firewall::isCSRFTokenValid(CRM_Utils_Type::validate($params['csrfToken'], 'String'))) {
      _civicrm_api3_stripe_paymentintent_returnInvalid();
    }
  }
  $paymentMethodID = CRM_Utils_Type::validate($params['payment_method_id'], 'String');
  $paymentIntentID = CRM_Utils_Type::validate($params['payment_intent_id'], 'String');
  $capture = CRM_Utils_Type::validate($params['capture'], 'Boolean', FALSE);
  $amount = CRM_Utils_Type::validate($params['amount'], 'String');
  // $capture is normally true if we have already created the intent and just need to get extra
  //   authentication from the user (eg. on the confirmation page). So we don't need the amount
  //   in this case.
  if (empty($amount) && !$capture) {
    _civicrm_api3_stripe_paymentintent_returnInvalid();
  }

  $title = CRM_Utils_Type::validate($params['description'], 'String');
  $confirm = TRUE;
  $currency = CRM_Utils_Type::validate($params['currency'], 'String', CRM_Core_Config::singleton()->defaultCurrency);
  $processorID = CRM_Utils_Type::validate((int)$params['id'], 'Positive');
  !empty($processorID) ?: _civicrm_api3_stripe_paymentintent_returnInvalid();
  $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]);
  ($paymentProcessor['class_name'] === 'Payment_Stripe') ?: _civicrm_api3_stripe_paymentintent_returnInvalid();
  $processor = new CRM_Core_Payment_Stripe('', $paymentProcessor);
  $processor->setAPIParams();

  if (empty($paymentIntentID) && empty($paymentMethodID)) {
    _civicrm_api3_stripe_paymentintent_returnInvalid();
  }

  if ($paymentIntentID) {
    // We already have a PaymentIntent, retrieve and attempt confirm.
    $intent = \Stripe\PaymentIntent::retrieve($paymentIntentID);
    if ($intent->status === 'requires_confirmation') {
      $intent->confirm();
    }
    if ($capture && $intent->status === 'requires_capture') {
      $intent->capture();
    }
  }
  else {
    // We don't yet have a PaymentIntent, create one using the
    // Payment Method ID and attempt to confirm it too.
    try {
      $intent = $processor->stripeClient->paymentIntents->create([
        'payment_method' => $paymentMethodID,
        'amount' => $processor->getAmount(['amount' => $amount, 'currency' => $currency]),
        'currency' => $currency,
        'confirmation_method' => 'manual',
        'capture_method' => 'manual',
        // authorize the amount but don't take from card yet
        'setup_future_usage' => 'off_session',
        // Setup the card to be saved and used later
        'confirm' => $confirm,
      ]);
    } catch (Exception $e) {
      // Save the "error" in the paymentIntent table in in case investigation is required.
      $stripePaymentintentParams = [
        'paymentintent_id' => 'null',
        'payment_processor_id' => $processorID,
        'status' => 'failed',
        'description' => "{$e->getRequestId()};{$e->getMessage()};{$title}",
        'referrer' => $_SERVER['HTTP_REFERER'],
      ];
      CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);

      if ($e instanceof \Stripe\Exception\CardException) {
        if (($e->getDeclineCode() === 'fraudulent') && class_exists('\Civi\Firewall\Event\FraudEvent')) {
          \Civi\Firewall\Event\FraudEvent::trigger(\CRM_Utils_System::ipAddress(), 'CRM_Stripe_AJAX::confirmPayment');
        }
        $message = $e->getMessage();
      }
      elseif ($e instanceof \Stripe\Exception\InvalidRequestException) {
        $message = 'Invalid request';
      }
      return civicrm_api3_create_error(['message' => $message]);
    }
  }

  // Save the generated paymentIntent in the CiviCRM database for later tracking
  $stripePaymentintentParams = [
    'paymentintent_id' => $intent->id,
    'payment_processor_id' => $processorID,
    'status' => $intent->status,
    'description' => "{$title}",
    'referrer' => $_SERVER['HTTP_REFERER'],
  ];
  CRM_Stripe_BAO_StripePaymentintent::create($stripePaymentintentParams);

  // generatePaymentResponse()
  if ($intent->status === 'requires_action' &&
    $intent->next_action->type === 'use_stripe_sdk') {
    // Tell the client to handle the action
    return civicrm_api3_create_success([
      'requires_action' => true,
      'payment_intent_client_secret' => $intent->client_secret,
    ]);
  }
  elseif (($intent->status === 'requires_capture') || ($intent->status === 'requires_confirmation')) {
    // paymentIntent = requires_capture / requires_confirmation
    // The payment intent has been confirmed, we just need to capture the payment
    // Handle post-payment fulfillment
    return civicrm_api3_create_success([
      'success' => true,
      'paymentIntent' => ['id' => $intent->id],
    ]);
  }
  elseif ($intent->status === 'succeeded') {
    return civicrm_api3_create_success([
      'success' => true,
      'paymentIntent' => ['id' => $intent->id],
    ]);
  }
  else {
    // Invalid status
    if (isset($intent->last_payment_error->message)) {
      $message = E::ts('Payment failed: %1', [1 => $intent->last_payment_error->message]);
    }
    else {
      $message = E::ts('Payment failed.');
    }
    return civicrm_api3_create_error($message);
  }
}

/**
 * Passed parameters were invalid
 */
function _civicrm_api3_stripe_paymentintent_returnInvalid() {
  http_response_code(400);
  exit(1);
}
