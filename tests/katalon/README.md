# Katalon Test Suite

The CiviCRM Stripe Katalon test suite automatically runs through a series of standard credit card operations using the [Katalon Firefox extension](https://addons.mozilla.org/mn/firefox/addon/katalon-automation-record/).

To test using Katalon, follow these steps:

 * Install the [Katalon Firefox extension](https://addons.mozilla.org/mn/firefox/addon/katalon-automation-record/).
 * Install CiviCRM/Drupal using [CiviCRM Buildkit](https://docs.civicrm.org/dev/en/latest/tools/buildkit/) or otherwise run a Drupal CiviCRM installation via http://localhost:8001.
 * Add com.drastikbydesign.stripe in the sites/files/civicrm/ext folder.
 * Install and enable the webform and webform_civicrm modules (ensure webform_civicrm is patched for stripe).
 * Open Katalon in your Firefox Browser
 * Click the + symbol next to "Test Suites"
 * Navigate to this folder and select the file: civicrm-stripe-test-suite.html
 * Play each test (in order).


