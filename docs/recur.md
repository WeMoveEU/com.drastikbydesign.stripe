# Recurring Contributions and Webhooks
## Overview
If you are using recurring contributions with Stripe you **MUST** setup a webhook and check that it is working. Otherwise contributions will never be marked "Completed".

If you are not using recurring contributions the webhook is not required.

## Details
Stripe notifies CiviCRM every time a recurring contribution is processed / updated / cancelled.

In order to take advantage of this feature, you must configure Stripe with the right "Webhook Endpoint".

To do this, log into Stripe Dashboard and from the menu on the left, choose Developers > Webhooks and click Add Endpoint.

At this point, you need to figure out the "URL to be called" value. To do this, you need to check what ID is assigned to the Stripe payment processor in CiviCRM.

To determine the ID, go back to CiviCRM and click `Administer -> System Settings -> Payment Processor`

Click Edit next to the payment processor you are setting up.

Then, check the Address bar. You should see something like the following:

https://example.com/civicrm/admin/paymentProcessor?action=update&id=3&reset=1

The end of the address contains id=3. That means that this Payment Processor id is 3.

Therefore the call back address for your site will be:

    civicrm/payment/ipn/3

See below for the full address to add to the endpoint (replace NN with your actual ID number):

* For Drupal:  https://example.com/civicrm/payment/ipn/NN
* For Joomla:  https://example.com/?option=com_civicrm&task=civicrm/payment/ipn/NN
* For Wordpress:  https://example.com/?page=CiviCRM&q=civicrm/payment/ipn/NN

Typically, you only need to configure the end point to send live transactions and you want it to send all events.

Also, make sure you set the API version for the end point to 2018-11-08.

## Cancelling Recurring Contributions
You can cancel a recurring contribution from the Stripe.com dashboard or from within CiviCRM.

#### In Stripe
1. Go to Customers and then to the specific customer.
1. Inside the customer you will see a Subscriptions section.
1. Click Cancel on the subscription you want to cancel.
1. Stripe.com will cancel the subscription, send a webhook to your site and the recurring contribution will be marked as "Cancelled" in CiviCRM.

#### In CiviCRM
1. Click the "Cancel" link next to the recurring contribution.
1. Select the option to "Send cancellation request to Stripe ?" and click Cancel.
1. Stripe.com will cancel the subscription, send a webhook to your site and the recurring contribution will be marked as "Cancelled" in CiviCRM.
