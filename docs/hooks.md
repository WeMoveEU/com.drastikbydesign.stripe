# Hooks

In release 5.2 we introduce a framework for hooks, in a similar way to the org.civicrm.smartdebit extension.

In order to support other extensions that manipulate amounts etc we need to add additional hooks and call existing ones in more places.

Currently only a single hook is implemented, and is only called in one place. 

#### hook_civicrm_smartdebit_updateRecurringContribution(&$recurContributionParams)
This hook allows modifying recurring contribution parameters during update.

* @param array $recurContributionParams Recurring contribution params (ContributionRecur.create API parameters).
