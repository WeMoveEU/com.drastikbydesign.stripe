# API
This extension comes with several APIs to help you troubleshoot problems. These can be run via /civicrm/api or via drush if you are using Drupal (drush cvapi Stripe.XXX).

The api commands are:

 * `Listevents`: Events are the notifications that Stripe sends to the Webhook. Listevents will list all notifications that have been sent. You can further restrict them with the following parameters:
   * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
   * `type` - Limit to the given Stripe events type. By default, show invoice.payment_succeeded. Change to 'all' to show all.
   * `output` - What information to show. Defaults to 'brief' which provides a summary. Alternatively use raw to get the raw JSON returned by Stripe.
   * `limit` - Limit number of results returned (100 is max, 10 is default).
   * `starting_after` - Only return results after this event id. This can be used for paging purposes - if you want to retreive more than 100 results.
 * `Populatelog`: If you are running a version of CiviCRM that supports the SystemLog - then this API call will populate your SystemLog with all of your past Stripe Events. You can safely re-run and not create duplicates. With a populated SystemLog - you can selectively replay events that may have caused errors the first time or otherwise not been properly recorded. Parameters:
   * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
 * `Ipn`: Replay a given Stripe Event. Parameters. This will always fetch the chosen Event from Stripe before replaying.
   * `id` - The id from the SystemLog of the event to replay.
   * `evtid` - The Event ID as provided by Stripe.
   * `ppid` - Use the given Payment Processor ID. By default, uses the saved, live Stripe payment processor and throws an error if there is more than one.
   * `noreceipt` - Set to 1 if you want to suppress the generation of receipts or set to 0 or leave out to send receipts normally.

### Additionally for upgrading:

* `StripeCustomer.updatecontactids` - Used to migrate civicrm_stripe_customer table to match on contact_id instead of email address.
* `StripeSubscription.updatetransactionids` - Used to migrate civicrm_stripe_subscriptions to use recurring contributions directly.
* `StripeSubscription.copytrxnidtoprocessorid` - Used to copy trxn_id to processor_id in civicrm_contribution_recur table so we can use cancelSubscription. Hopefully this won't be needed in future versions of CiviCRM if we can pass more sensible values to the cancelSubscription function.