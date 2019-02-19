<?php

/**
 * Stripe Subscription API
 *
 */

/**
 * StripeSubscription.Get API specification
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_stripe_subscription_get_spec(&$spec) {
  $spec['subscription_id']['title'] = ts("Stripe Subscription ID");
  $spec['subscription_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['customer_id']['title'] = ts("Stripe Customer ID");
  $spec['customer_id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['contribution_recur_id']['title'] = ts("Contribution Recur ID");
  $spec['contribution_recur_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['is_live']['title'] = ts("Is live processor");
  $spec['is_live']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['processor_id']['title'] = ts("Payment Processor ID");
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['end_time_id']['title'] = ts("End Time");
  $spec['end_time_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * @deprecated This StripeSubscription.get is deprecated as of 5.2 as we now using recurring contribution instead of civicrm_stripe_subscriptions
 *
 * StripeSubscription.Get API
 *  This api will get entries from the civicrm_stripe_subscriptions table
 *
 * @param array $params
 * @see civicrm_api3_create_success
 *
 * @return array
 */
function civicrm_api3_stripe_subscription_get($params) {
  foreach ($params as $key => $value) {
    $index = 1;
    switch ($key) {
      case 'subscription_id':
      case 'customer_id':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'String'];
        $index++;
        break;

      case 'contribution_recur_id':
      case 'processor_id':
      case 'end_time':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'Integer'];
        $index++;
        break;

      case 'is_live':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'Boolean'];
        $index++;
        break;
    }
  }


  $query = "SELECT * FROM civicrm_stripe_subscriptions ";
  if (count($where)) {
    $whereClause = implode(' AND ', $where);
    $query .= "WHERE {$whereClause}";
  }
  $dao = CRM_Core_DAO::executeQuery($query, $whereParam);

  while ($dao->fetch()) {
    $result = [
      'subscription_id' => $dao->subscription_id,
      'customer_id' => $dao->customer_id,
      'contribution_recur_id' => $dao->contribution_recur_id,
      'is_live' => $dao->is_live,
      'processor_id' => $dao->processor_id,
      'end_time' => $dao->end_time,
    ];
    $results[] = $result;
  }
  return civicrm_api3_create_success($results);
}

function civicrm_api3_stripe_subscription_updatetransactionids() {
  if (!CRM_Core_DAO::checkTableExists('civicrm_stripe_subscriptions')) {
    throw new CiviCRM_API3_Exception('Table civicrm_stripe_subscriptions is not used in Stripe >=5.2 and does not exist on your install. This API will be removed in a future release.');
  }

  $sql = "SELECT subscription_id, contribution_recur_id FROM civicrm_stripe_subscriptions";
  $dao = CRM_Core_DAO::executeQuery($sql);
  $counts = [
    'success' => 0,
    'failed' => 0
  ];
  while ($dao->fetch()) {
    if (!empty($dao->subscription_id) && !empty($dao->contribution_recur_id)) {
      try {
        civicrm_api3('ContributionRecur', 'create', ['id' => $dao->contribution_recur_id, 'trxn_id' => $dao->subscription_id]);
        $counts['success']++;
      }
      catch (Exception $e) {
        Civi::log()->debug('Error updating trxn_id for recur: ' . $dao->contribution_recur_id . ' trxn_id: ' . $dao->subscription_id);
        $counts['failed']++;
      }
    }
  }
  return civicrm_api3_create_success($counts);
}

/**
 * API function (used in 5021 upgrader) to copy trxn_id to processor_id in civicrm_contribution_recur table
 * processor_id (named subscriptionId) is the only value available to cancelSubscription in 5.9 (and earlier).
 * It is not ideal as processor_id is not guaranteed to be unique in the CiviCRM database (trxn_id is unique).
 *
 * @return array
 */
function civicrm_api3_stripe_subscription_copytrxnidtoprocessorid() {
  $sql = "SELECT cr.trxn_id, cr.processor_id, cr.payment_processor_id, cpp.class_name FROM civicrm_contribution_recur cr
LEFT JOIN civicrm_payment_processor AS cpp ON cr.payment_processor_id = cpp.id
WHERE cpp.class_name = 'Payment_Stripe'";
  $dao = CRM_Core_DAO::executeQuery($sql);
  $counts = [
    'updated' => 0,
  ];
  while ($dao->fetch()) {
    if (!empty($dao->trxn_id) && empty($dao->processor_id)) {
      $updateSQL = "UPDATE civicrm_contribution_recur
SET processor_id=%1
WHERE trxn_id=%1;";
      $updateParams = [1 => [$dao->trxn_id, 'String']];
      CRM_Core_DAO::executeQuery($updateSQL, $updateParams);
      $counts['updated']++;
    }
  }
  return civicrm_api3_create_success($counts);
}
