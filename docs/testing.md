# TESTING

### PHPUnit
This extension comes with two PHP Unit tests:

 * Ipn - This unit test ensures that a recurring contribution is properly updated after the event is received from Stripe and that it is properly canceled when cancelled via Stripe.
 * Direct - This unit test ensures that a direct payment to Stripe is properly recorded in the database.

Tests can be run most easily via an installation made through CiviCRM Buildkit (https://github.com/civicrm/civicrm-buildkit) by changing into the extension directory and running:

    phpunit4 tests/phpunit/CRM/Stripe/IpnTest.php
    phpunit4 tests/phpunit/CRM/Stripe/DirectTest.php

### Katalon Tests
See the test/katalon folder for instructions on running full web-browser based automation tests.

Expects a drupal (demo) site installed at http://localhost:8001

1. Login: No expected result, just logs into a Drupal CMS.
1. Enable Stripe Extension: Two payment processors are created, can be done manually but processor labels must match or subsequent tests will fail.
1. Offline Contribution, default PP: A contribution is created for Arlyne Adams with default PP.
1. Offline Contribution, alternate PP: A contribution is created for Arlyne Adams with alternate PP.
1. Offline Membership, default PP: A membership/contribution is created for Arlyne Adams with default PP.
1. Offline Membership, alternate PP: A membership/contribution is created for Arlyne Adams with alternate PP.
1. Offline Event Registration, default PP: A participant record/contribution is created for Arlyne Adams with default PP.
1. Offline Event Registration, alternate PP: A participant record/contribution is created for Arlyne Adams with alternate PP.
1. Online Contribution Stripe Default Only: A new contribution record is created.
1. Online Contribution Page 2xStripe, Test proc, use Stripe Alt: A new contribution record is created. **FAIL:
Error Oops! Looks like there was an error. Payment Response: 
Type: invalid_request_error
Code: resource_missing
Message: No such token: Stripe Token**
1. Online Contribution Page Stripe Default, Pay Later: A new contribution record is created.
1. Test Webform: A new contribution is created. *Partial test only*

ONLINE contribution, event registration tests


### Manual Tests

1. Test webform submission with payment and user-select, single processor.
1. TODO: Are we testing offline contribution with single/multi-processor properly when stripe is/is not default with katalon tests?

1. Test online contribution page on Wordpress.
1. Test online contribution page on Joomla.
1. Test online event registration.
1. Test online event registration (cart checkout).

#### Drupal Webform Tests
TODO: Add these as Katalon tests.

1. Webform with single payment processor (Stripe) - Amount = 0.
1. Webform with single payment processor (Stripe) - Amount > 0.
1. Webform with multiple payment processor (Stripe selected) - Amount = 0.
1. Webform with multiple payment processor (Stripe selected) - Amount > 0.
1. Webform with multiple payment processor (Pay Later selected) - Amount = 0.
1. Webform with multiple payment processor (Pay Later selected) - Amount > 0.
1. Webform with multiple payment processor (Non-stripe processor selected) - Amount = 0.
1. Webform with multiple payment processor (Non-stripe processor selected) - Amount > 0.
