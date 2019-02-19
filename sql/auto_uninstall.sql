/* Remove Stripe tables on uninstall. */
DROP TABLE civicrm_stripe_customers;
DROP TABLE IF EXISTS civicrm_stripe_plans;
DROP TABLE IF EXISTS civicrm_stripe_subscriptions;