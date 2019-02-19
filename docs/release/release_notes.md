## Release 5.2
*This release introduces a number of new features, standardises the behaviour of recurring contributions/memberships to match standard CiviCRM functionality and does a major cleanup of the backend code to improve stability and allow for new features.*

### Highlights:
* Support Cancel Subscription from CiviCRM and from Stripe.

### Breaking changes:
* The extension now uses the standard CiviCRM Contribution.completetransaction and Contribution.repeattransaction API to handle creation/update of recurring contributions. This means that automatic membership renewal etc. is handled in the standard CiviCRM way instead of using custom code in the Stripe extension. The behaviour *should* be the same but some edge-cases may be fixed while others may appear. Any bugs in this area will now need to be fixed in CiviCRM core - if you want to help with that see https://github.com/civicrm/civicrm-core/pull/11556.
* When recurring contributions were updated by Stripe, they were marked cancelled and a new one created in CiviCRM. This was non-standard behaviour and causes issues with CiviCRM core functionality for membership renewal etc. This has now been changed so only one recurring contribution per subscription will ever exist, which will be updated as necessary during it's lifecycle.
* Different payment amounts are now supported for each contribution in a recurring contribution. Previously they were explicitly rejected by the extension.

### Changes:
* Add http response codes for webhook (invalid parameters now returns 400 Bad Request).
* Major refactor of webhook / events handling (fixes multiple issues, now tested and working on Joomla / Wordpress / Drupal 7).
* Update to latest version of stripe-php library.
* Handle "Customer Deleted" from Stripe.
* Drop use of civicrm_stripe_plans table and just query Stripe each time. This prevents errors when they get out of sync

### Upgrading
**Please upgrade to 5.0 if you are on ANY older version. Then upgrade directly to 5.2. You do not need to install 5.1 first.**

Make sure you run the extension upgrades immediately after updating the code.  There are two MAJOR upgrade steps:
1. Migrate civicrm_stripe_customers table to match on contact_id instead of email address. This can be re-run if necessary using StripeCustomer.updatecontactids API.
2. Migrate data from civicrm_stripe_subscriptions to use the recurring contribution (trxn_id = Stripe subscription ID). This can be re-run if necessary using StripeSubscription.updatetransactionids API.


## Release 5.1
*This was a limited release to selected clients in order to test new functionality.  **Production sites should upgrade to 5.2 directly from 5.0**.*

### Changes:
* Use contact_id as reference in civicrm_stripe_customers and don't require an email address for payment.
* Drop old webhook code / endpoint at https://{yoursitename.org}/civicrm/stripe/webhook. You **MUST** update your webhooks to use the standard CiviCRM endpoint at https://{yoursitename.org}/civicrm/payment/ipn/XX (see [Webhooks and Recurring Payments](/recur.md) for details). 


## Release 5.0
*This is the first release with a new maintainer (mattwire https://www.mjwconsult.co.uk) and repository move to https://lab.civicrm.org/extensions/stripe.*

**If upgrading from ANY version before 5.0 you should upgrade to this version first. It should be a safe upgrade for all sites on previous versions unless you are running a customised version of the extension.**

### Highlights:
* Fix all known "Stripe.js token was not passed".
* Tested support for Drupal 7 / Wordpress / Joomla for contributions/event payments.
* Improvements to recurring payments (though you will want to upgrade to 5.2 if using recurring payments as recurring payments has had a major rewrite for 5.2).