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

/**
 * Stripe Customer API
 *
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * StripeCustomer.Get API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_get_spec(&$spec) {
  $spec['id']['title'] = ts("Stripe Customer ID");
  $spec['id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['id']['api.aliases'] = ['customer_id'];
  $spec['contact_id']['title'] = ts("CiviCRM Contact ID");
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['title'] = ts("Payment Processor ID");
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * StripeCustomer.Get API
 *  This api will get a customer from the civicrm_stripe_customers table
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_customer_get($params) {
  $index = 1;
  foreach ($params as $key => $value) {
    switch ($key) {
      case 'id':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'String'];
        $index++;
        break;

      case 'contact_id':
      case 'processor_id':
        $where[$index] = "{$key}=%{$index}";
        $whereParam[$index] = [$value, 'Integer'];
        $index++;
        break;

    }
  }

  $query = "SELECT * FROM civicrm_stripe_customers ";
  if (count($where)) {
    $whereClause = implode(' AND ', $where);
    $query .= "WHERE {$whereClause}";
  }
  $dao = CRM_Core_DAO::executeQuery($query, $whereParam);

  $results = [];
  while ($dao->fetch()) {
    $result = [
      'id' => $dao->id,
      'contact_id' => $dao->contact_id,
      'processor_id' => $dao->processor_id,
    ];
    $results[] = $result;
  }
  return civicrm_api3_create_success($results);
}

/**
 * StripeCustomer.delete API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_delete_spec(&$spec) {
  $spec['id']['title'] = ts("Stripe Customer ID");
  $spec['id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['id']['api.aliases'] = ['customer_id'];
  $spec['contact_id']['title'] = ts("CiviCRM Contact ID");
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['title'] = ts("Payment Processor ID");
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['api.required'] = TRUE;
}

/**
 * StripeCustomer.delete API
 *  This api will delete a stripe customer from CiviCRM
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_delete($params) {
  CRM_Stripe_Customer::delete($params);
  return civicrm_api3_create_success([]);
}

/**
 * StripeCustomer.create API specification
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_stripe_customer_create_spec(&$spec) {
  $spec['id']['title'] = ts("Stripe Customer ID");
  $spec['id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['id']['api.required'] = TRUE;
  $spec['id']['api.aliases'] = ['customer_id'];
  $spec['contact_id']['title'] = ts("CiviCRM Contact ID");
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['contact_id']['api.required'] = TRUE;
  $spec['processor_id']['title'] = ts("Payment Processor ID");
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
  $spec['processor_id']['api.required'] = TRUE;
}

/**
 * StripeCustomer.create API
 *  This api will add a stripe customer to CiviCRM
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_create($params) {
  CRM_Stripe_Customer::add($params);
  return civicrm_api3_create_success([]);
}

/**
 * Stripe.Customer.Updatecontactids API
 *  This api will update the civicrm_stripe_customers table and add contact IDs for all known email addresses
 *
 * @param array $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_stripe_customer_updatecontactids($params) {
  $dao = CRM_Core_DAO::executeQuery('SELECT email, id FROM civicrm_stripe_customers WHERE contact_id IS NULL');
  $counts = [
    'updated' => 0,
    'failed' => 0,
  ];
  while ($dao->fetch()) {
    $contactId = NULL;
    try {
      $contactId = civicrm_api3('Contact', 'getvalue', [
        'return' => "id",
        'email' => $dao->email,
      ]);
    }
    catch (Exception $e) {
      // Most common problem is duplicates.
      if(preg_match("/Expected one Contact but found/", $e->getMessage())) {
        // Still no luck. Now get desperate.
        $sql = "SELECT c.id
            FROM civicrm_contact c JOIN civicrm_email e ON c.id = e.contact_id
            JOIN civicrm_contribution cc ON c.id = cc.contact_id
            WHERE e.email = %0 AND c.is_deleted = 0 AND is_test = 0 AND
              trxn_id LIKE 'ch_%' AND contribution_status_id = 1
            ORDER BY receive_date DESC LIMIT 1";
        $dao_contribution = CRM_Core_DAO::executeQuery($sql, [0 => [$dao->email, 'String']]);
        $dao_contribution->fetch();
        if ($dao_contribution->id) {
          $contactId = $dao_contribution->id;
        }
      }
      if (empty($contactId)) {
        // Still no luck. Log it and move on.
        Civi::log()->debug('Stripe Upgrader: No contact ID found for stripe customer with email: ' . $dao->email);
        $counts['failed']++;
        continue;
      }
    }

    $sqlParams = [
      1 => [$contactId, 'Integer'],
      2 => [$dao->email, 'String'],
    ];
    $sql = 'UPDATE civicrm_stripe_customers SET contact_id=%1 WHERE email=%2';
    CRM_Core_DAO::executeQuery($sql, $sqlParams);
    $counts['updated']++;
  }

  return civicrm_api3_create_success($counts);
}

/**
 * @param array $spec
 */
function _civicrm_api3_stripe_customer_updatestripemetadata_spec(&$spec) {
  $spec['id']['title'] = E::ts("Stripe Customer ID");
  $spec['id']['description'] = E::ts('If set only this customer will be updated, otherwise we try and update ALL customers');
  $spec['id']['type'] = CRM_Utils_Type::T_STRING;
  $spec['id']['api.required'] = FALSE;
  $spec['id']['api.aliases'] = ['customer_id'];
  $spec['dryrun']['api.required'] = TRUE;
  $spec['dryrun']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $spec['processor_id']['api.required'] = FALSE;
  $spec['processor_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * This allows us to update the metadata held by stripe about our CiviCRM payments
 * Older versions of stripe extension did not set anything useful in stripe except email
 * Now we set a description including the name + metadata holding contact id.
 *
 * @param $params
 *
 * @return array
 * @throws \CiviCRM_API3_Exception
 * @throws \Civi\Payment\Exception\PaymentProcessorException
 */
function civicrm_api3_stripe_customer_updatestripemetadata($params) {
  if (!isset($params['dryrun'])) {
    throw new CiviCRM_API3_Exception('Missing required parameter dryrun');
  }
  // Check params
  if (empty($params['id'])) {
    // We're doing an update on all stripe customers
    if (!isset($params['processor_id'])) {
      throw new CiviCRM_API3_Exception('Missing required parameters processor_id when using without a customer id');
    }
    $customerIds = CRM_Stripe_Customer::getAll($params['processor_id'], $params['options']);
  }
  else {
    $customerIds = [$params['id']];
  }
  foreach ($customerIds as $customerId) {
    $customerParams = CRM_Stripe_Customer::getParamsForCustomerId($customerId);
    if (empty($customerParams['contact_id'])) {
      throw new CiviCRM_API3_Exception('Could not find contact ID for stripe customer: ' . $customerId);
    }

    $paymentProcessor = \Civi\Payment\System::singleton()->getById($customerParams['processor_id']);
    $paymentProcessor->setAPIParams();

    // Get the stripe customer from stripe
    try {
      $stripeCustomer = \Stripe\Customer::retrieve($customerId);
    } catch (Exception $e) {
      $err = CRM_Core_Payment_Stripe::parseStripeException('retrieve_customer', $e, FALSE);
      $errorMessage = $paymentProcessor->handleErrorNotification($err, NULL);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to retrieve Stripe Customer: ' . $errorMessage);
    }

    // Get the contact display name
    $contactDisplayName = civicrm_api3('Contact', 'getvalue', [
      'return' => 'display_name',
      'id' => $customerParams['contact_id'],
    ]);

    // Currently we set the description and metadata
    $stripeCustomerParams = [
      'description' => $contactDisplayName . ' (CiviCRM)',
      'metadata' => ['civicrm_contact_id' => $customerParams['contact_id']],
    ];

    // Update the stripe customer object at stripe
    if (!$params['dryrun']) {
      \Stripe\Customer::update($customerId, $stripeCustomerParams);
      $results[] = $stripeCustomerParams;
    }
    else {
      $results[] = $stripeCustomerParams;
    }
  }
  return civicrm_api3_create_success($results, $params);
}
