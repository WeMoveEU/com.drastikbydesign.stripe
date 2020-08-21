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
 * Class CRM_Stripe_AJAX
 */
class CRM_Stripe_AJAX {

  /**
   * Generate the paymentIntent for civicrm_stripe.js
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
   * Outputs an array as a JSON response see generatePaymentResponse
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function confirmPayment() {
    $_SERVER['REQUEST_METHOD'] === 'POST' ?: self::returnInvalid();
    (CRM_Utils_Request::retrieveValue('reset', 'String') === NULL) ?: self::returnInvalid();

    if (class_exists('\Civi\Firewall\Firewall')) {
      if (!\Civi\Firewall\Firewall::isCSRFTokenValid(CRM_Utils_Request::retrieveValue('csrfToken', 'String') ?? '')) {
        self::returnInvalid();
      }
    }
    $paymentMethodID = CRM_Utils_Request::retrieveValue('payment_method_id', 'String');
    $paymentIntentID = CRM_Utils_Request::retrieveValue('payment_intent_id', 'String');
    $capture = CRM_Utils_Request::retrieveValue('capture', 'Boolean', FALSE);
    $amount = CRM_Utils_Request::retrieveValue('amount', 'String');
    // $capture is normally true if we have already created the intent and just need to get extra
    //   authentication from the user (eg. on the confirmation page). So we don't need the amount
    //   in this case.
    if (empty($amount) && !$capture) {
      self::returnInvalid();
    }

    $title = CRM_Utils_Request::retrieveValue('description', 'String');
    $confirm = TRUE;
    $currency = CRM_Utils_Request::retrieveValue('currency', 'String', CRM_Core_Config::singleton()->defaultCurrency);
    $processorID = CRM_Utils_Request::retrieveValue('id', 'Positive');
    !empty($processorID) ?: self::returnInvalid();
    $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]);
    ($paymentProcessor['class_name'] === 'Payment_Stripe') ?: self::returnInvalid();
    $processor = new CRM_Core_Payment_Stripe('', $paymentProcessor);
    $processor->setAPIParams();

    if (empty($paymentIntentID) && empty($paymentMethodID)) {
      self::returnInvalid();
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
        $intent = \Stripe\PaymentIntent::create([
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
        if ($e instanceof \Stripe\Exception\CardException) {
          if (($e->getDeclineCode() === 'fraudulent') && class_exists('\Civi\Firewall\Event\FraudEvent')) {
            \Civi\Firewall\Event\FraudEvent::trigger(\CRM_Utils_System::ipAddress(), 'CRM_Stripe_AJAX::confirmPayment');
          }
        }
        // Save the "error" in the paymentIntent table in in case investigation is required.
        $intentParams = [
          'paymentintent_id' => 'null',
          'payment_processor_id' => $processorID,
          'status' => 'failed',
          'description' => "{$e->getMessage()};{$title}",
          'referrer' => $_SERVER['HTTP_REFERER'],
        ];
        CRM_Stripe_BAO_StripePaymentintent::create($intentParams);
        CRM_Utils_JSON::output(['error' => ['message' => $e->getMessage()]]);
      }
    }
    // Save the generated paymentIntent in the CiviCRM database for later tracking
    $intentParams = [
      'paymentintent_id' => $intent->id,
      'payment_processor_id' => $processorID,
      'status' => $intent->status,
      'description' => ";{$title}",
      'referrer' => $_SERVER['HTTP_REFERER'],
    ];
    CRM_Stripe_BAO_StripePaymentintent::create($intentParams);

    self::generatePaymentResponse($intent);
  }

  /**
   * Generate the json response for civicrm_stripe.js
   *
   * @param \Stripe\PaymentIntent $intent
   */
  private static function generatePaymentResponse($intent) {
    if ($intent->status === 'requires_action' &&
      $intent->next_action->type === 'use_stripe_sdk') {
      // Tell the client to handle the action
      CRM_Utils_JSON::output([
        'requires_action' => true,
        'payment_intent_client_secret' => $intent->client_secret,
      ]);
    }
    elseif (($intent->status === 'requires_capture') || ($intent->status === 'requires_confirmation')) {
      // paymentIntent = requires_capture / requires_confirmation
      // The payment intent has been confirmed, we just need to capture the payment
      // Handle post-payment fulfillment
      CRM_Utils_JSON::output([
        'success' => true,
        'paymentIntent' => ['id' => $intent->id],
      ]);
    }
    elseif ($intent->status === 'succeeded') {
      CRM_Utils_JSON::output([
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
      CRM_Utils_JSON::output(['error' => ['message' => $message]]);
    }
  }

  /**
   * Passed parameters were invalid
   */
  private static function returnInvalid() {
    http_response_code(400);
    exit(1);
  }

}
