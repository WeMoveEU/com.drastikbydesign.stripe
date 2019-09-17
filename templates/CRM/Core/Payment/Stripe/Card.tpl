{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}
{* Manually create the CRM.vars.stripe here for drupal webform because \Civi::resources()->addVars() does not work in this context *}
{literal}
<script type="text/javascript">
  CRM.$(function($) {
    $(document).ready(function() {
      if (typeof CRM.vars.stripe === 'undefined') {
        var stripe = {{/literal}{foreach from=$stripeJSVars key=arrayKey item=arrayValue}{$arrayKey}:'{$arrayValue}',{/foreach}{literal}};
        CRM.vars.stripe = stripe;
      }
    });
  });
</script>
{/literal}

{* Add the components required for a Stripe card element *}
{crmScope extensionKey='com.drastikbydesign.stripe'}
<fieldset><legend>{ts}Credit Card Information{/ts}</legend></fieldset>
<div id="card-element"></div>
{* Area for Stripe to report errors *}
<div id="card-errors" role="alert" class="crm-error alert alert-danger"></div>
{/crmScope}
