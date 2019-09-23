<?php
/**
 * https://civicrm.org/licensing
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
    $paymentMethodID = CRM_Utils_Request::retrieveValue('payment_method_id', 'String');
    $paymentIntentID = CRM_Utils_Request::retrieveValue('payment_intent_id', 'String');
    $amount = CRM_Utils_Request::retrieveValue('amount', 'Money');
    if (empty($amount)) {
      CRM_Utils_JSON::output(['error' => ['message' => E::ts('No amount specified for payment!')]]);
    }
    $currency = CRM_Utils_Request::retrieveValue('currency', 'String', CRM_Core_Config::singleton()->defaultCurrency);
    $processorID = CRM_Utils_Request::retrieveValue('id', 'Integer', NULL, TRUE);
    $processor = new CRM_Core_Payment_Stripe('', civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $processorID]));
    $processor->setAPIParams();

    if ($paymentIntentID) {
      // We already have a PaymentIntent, retrieve and attempt confirm.
      $intent = \Stripe\PaymentIntent::retrieve($paymentIntentID);
      $intent->confirm();
    }
    else {
      // We don't yet have a PaymentIntent, create one using the
      // Payment Method ID and attempt to confirm it too.
      try {
        $intent = \Stripe\PaymentIntent::create([
          'payment_method' => $paymentMethodID,
          'amount' => $processor->getAmount(['amount' => $amount]),
          'currency' => $currency,
          'confirmation_method' => 'manual',
          'capture_method' => 'manual',
          // authorize the amount but don't take from card yet
          'setup_future_usage' => 'off_session',
          // Setup the card to be saved and used later
          'confirm' => TRUE,
        ]);
      } catch (Exception $e) {
        CRM_Utils_JSON::output(['error' => ['message' => $e->getMessage()]]);
      }
    }

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
    else {
      // Invalid status
      CRM_Utils_JSON::output(['error' => ['message' => 'Invalid PaymentIntent status']]);
    }
  }

}
