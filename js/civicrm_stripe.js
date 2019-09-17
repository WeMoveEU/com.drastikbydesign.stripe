/**
 * JS Integration between CiviCRM & Stripe.
 */
CRM.$(function($) {
  debugging("civicrm_stripe loaded, dom-ready function firing.");

  if (window.civicrmStripeHandleReload) {
    // Call existing instance of this, instead of making new one.

    debugging("calling existing civicrmStripeHandleReload.");
    window.civicrmStripeHandleReload();
    return;
  }

  // On initial load...
  var stripe = null;
  var card = null;
  var form;
  var submitButtons;
  var stripeLoading = false;
  var paymentProcessorID = null;

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  /**
   * This function boots the UI.
   */
  window.civicrmStripeHandleReload = function() {
    debugging('civicrmStripeHandleReload');
    // Load Stripe onto the form.
    var cardElement = document.getElementById('card-element');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        debugging('checkAndLoad from document.ready');
        checkAndLoad();
      }
    }
    else {
      notStripe();
      triggerEvent('crmBillingFormReloadComplete');
    }
  };
  // On initial run we need to call this now.
  window.civicrmStripeHandleReload();

  function successHandler(type, object) {
    debugging(type + ': success - submitting form');

    // Insert the token ID into the form so it gets submitted to the server
    var hiddenInput = document.createElement('input');
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', type);
    hiddenInput.setAttribute('value', object.id);
    form.appendChild(hiddenInput);

    // The "name" parameter on a set of checkboxes where at least one must be checked must be the same or validation will require all of them!
    // (But we have to reset this back before we submit otherwise the submission has no data (that's a Civi issue I think).
    $('div#priceset input[type="checkbox"]').each(function() {
      CRM.$(this).attr('name', CRM.$(this).attr('name') + '[' + CRM.$(this).attr('id').split('_').pop() + ']');
      CRM.$(this).removeAttr('required');
      CRM.$(this).removeClass('required');
      CRM.$(this).removeAttr('aria-required');
    });

    // Submit the form
    form.submit();
  }

  function nonStripeSubmit() {
    // Disable the submit button to prevent repeated clicks
    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].setAttribute('disabled', true);
    }
    return form.submit();
  }

  /**
   * Display a stripe element error
   *
   * @param {string} errorMessage - the stripe error object
   * @param {boolean} notify - whether to popup a notification as well as display on the form.
   */
  function displayError(errorMessage, notify) {
    // Display error.message in your UI.
    debugging('error: ' + errorMessage);
    // Inform the user if there was an error
    var errorElement = document.getElementById('card-errors');
    errorElement.style.display = 'block';
    errorElement.textContent = errorMessage;
    form.dataset.submitted = 'false';
    if (typeof submitButtons !== 'undefined') {
      for (i = 0; i < submitButtons.length; ++i) {
        submitButtons[i].removeAttribute('disabled');
      }
    }
    triggerEvent('crmBillingFormNotValid');
    if (notify) {
      swalFire({
        icon: 'error',
        text: errorMessage,
        title: '',
      }, '#card-element', true);
    }
  }

  function getPaymentElements() {
    return {
      card: $('div#card-element'),
    };
  }

  /**
   * Hide any visible payment elements
   */
  function hidePaymentElements() {
    getPaymentElements().card.hide();
  }

  /**
   * Destroy any payment elements we have already created
   */
  function destroyPaymentElements() {
    if (card !== null) {
      debugging("destroying card element");
      card.destroy();
      card = null;
    }
  }

  /**
   * Check that payment elements are valid
   * @returns {boolean}
   */
  function checkPaymentElementsAreValid() {
    var elements = getPaymentElements();
    if ((elements.card.length !== 0) && (elements.card.children().length === 0)) {
      debugging('card element is empty');
      return false;
    }
    return true;
  }

  function handleCardPayment() {
    debugging('handle card payment');
    stripe.createPaymentMethod('card', card).then(function (result) {
      if (result.error) {
        // Show error in payment form
        displayError(result.error.message, true);
      }
      else {
        // For recur, additional participants we do NOT know the final amount so must create a paymentMethod and only create the paymentIntent
        //   once the form is finally submitted.
        // We should never get here with amount=0 as we should be doing a "nonStripeSubmit()" instead. This may become needed when we save cards
        if (getIsRecur() || isEventAdditionalParticipants() || (getTotalAmount() === 0.0)) {
          // Submit the form, if we need to do 3dsecure etc. we do it at the end (thankyou page) once subscription etc has been created
          successHandler('paymentMethodID', result.paymentMethod);
        }
        else {
          // Send paymentMethod.id to server
          debugging('Waiting for pre-auth');
          swalFire({
            title: 'Please wait',
            text: ' while we pre-authorize your card...',
            allowOutsideClick: false,
            onBeforeOpen: () => {
              Swal.showLoading();
            },
          }, '', false);
          var url = CRM.url('civicrm/stripe/confirm-payment');
          $.post(url, {
            payment_method_id: result.paymentMethod.id,
            amount: getTotalAmount().toFixed(2),
            currency: CRM.vars.stripe.currency,
            id: CRM.vars.stripe.id,
            description: document.title,
            csrfToken: CRM.vars.stripe.csrfToken,
          })
            .done(function (result) {
              // Handle server response (see Step 3)
              handleServerResponse(result);
            })
            .always(function() {
              swalClose();
            });
        }
      }
    });
  }

  function handleServerResponse(result) {
    debugging('handleServerResponse');
    if (result.error) {
      // Show error from server on payment form
      displayError(result.error.message, true);
    } else if (result.requires_action) {
      // Use Stripe.js to handle required card action
      handleAction(result);
    } else {
      // All good, we can submit the form
      successHandler('paymentIntentID', result.paymentIntent);
    }
  }

  function handleAction(response) {
    stripe.handleCardAction(response.payment_intent_client_secret)
      .then(function(result) {
        if (result.error) {
          // Show error in payment form
          displayError(result.error.message, true);
        } else {
          // The card action has been handled
          // The PaymentIntent can be confirmed again on the server
          successHandler('paymentIntentID', result.paymentIntent);
        }
      });
  }

  function notStripe() {
    debugging("New payment processor is not Stripe, clearing CRM.vars.stripe");
    destroyPaymentElements();
    delete(CRM.vars.stripe);
  }

  function checkAndLoad() {
    if (typeof CRM.vars.stripe === 'undefined') {
      debugging('CRM.vars.stripe not defined! Not a Stripe processor?');
      return;
    }

    // Get the form containing payment details
    form = getBillingForm();
    if (typeof form.length === 'undefined' || form.length === 0) {
      debugging('No billing form!');
      return;
    }

    if (typeof Stripe === 'undefined') {
      if (stripeLoading) {
        return;
      }
      stripeLoading = true;
      debugging('Stripe.js is not loaded!');

      $.ajax({
        url: 'https://js.stripe.com/v3',
        dataType: 'script',
        cache: true,
        timeout: 5000,
        crossDomain: true,
      })
        .done(function(data) {
          stripeLoading = false;
          debugging("Script loaded and executed.");
          loadStripeBillingBlock();
          triggerEvent('crmBillingFormReloadComplete');
        })
        .fail(function() {
          stripeLoading = false;
          debugging('Failed to load Stripe.js');
          triggerEventCrmBillingFormReloadFailed();
        });
    }
    else {
      loadStripeBillingBlock();
      if (checkPaymentElementsAreValid()) {
        triggerEvent('crmBillingFormReloadComplete');
      }
      else {
        debugging('Failed to load payment elements');
        triggerEventCrmBillingFormReloadFailed();
      }
    }
  }

  function loadStripeBillingBlock() {
    debugging('loadStripeBillingBlock');

    var oldPaymentProcessorID = paymentProcessorID;
    paymentProcessorID = getPaymentProcessorSelectorValue();
    debugging('payment processor old: ' + oldPaymentProcessorID + ' new: ' + paymentProcessorID + ' id: ' + CRM.vars.stripe.id);
    if ((paymentProcessorID !== null) && (paymentProcessorID !== parseInt(CRM.vars.stripe.id))) {
      debugging('not stripe');
      return notStripe();
    }

    debugging('New Stripe ID: ' + CRM.vars.stripe.id + ' pubKey: ' + CRM.vars.stripe.publishableKey);
    stripe = Stripe(CRM.vars.stripe.publishableKey);

    var elements = stripe.elements({ locale: CRM.vars.stripe.locale });

    var style = {
      base: {
        fontSize: '15px',
        color: '#333',
      },
    };

    var elementsCreateParams = {style: style, value: {}};

    var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID);
    if (postCodeElement) {
      var postCode = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID).value;
      debugging('existing postcode: ' + postCode);
      elementsCreateParams.value.postalCode = postCode;
    }

    // Cleanup any classes leftover from previous switching payment processors
    getPaymentElements().card.removeClass();
    // Create an instance of the card Element.
    card = elements.create('card', elementsCreateParams);
    card.mount('#card-element');
    debugging("created new card element", card);

    if (postCodeElement) {
      // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the stripe card-element.
      if (document.getElementById('billing_postal_code-5').value) {
        document.getElementById('billing_postal_code-5')
          .setAttribute('disabled', true);
      }
      else {
        document.getElementsByClassName('billing_postal_code-' + CRM.vars.stripe.billingAddressID + '-section')[0].setAttribute('hidden', true);
      }
    }

    card.addEventListener('change', function (event) {
      cardElementChanged(event);
    });

    setBillingFieldsRequiredForJQueryValidate();
    submitButtons = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    form.dataset.submitdontprocess = 'false';

    // Find submit buttons which should not submit payment
    var nonPaymentSubmitButtons = form.querySelectorAll('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous'), i;
    for (i = 0; i < nonPaymentSubmitButtons.length; ++i) {
      nonPaymentSubmitButtons[i].addEventListener('click', submitDontProcess());
    }

    function submitDontProcess() {
      debugging('adding submitdontprocess');
      form.dataset.submitdontprocess = 'true';
    }

    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].addEventListener('click', submitButtonClick);
    }

    function submitButtonClick(event) {
      // Take over the click function of the form.
      if (typeof CRM.vars.stripe === 'undefined') {
        // Submit the form
        return nonStripeSubmit();
      }
      debugging('clearing submitdontprocess');
      form.dataset.submitdontprocess = 'false';

      // Run through our own submit, that executes Stripe submission if
      // appropriate for this submit.
      return submit(event);
    }

    // Remove the onclick attribute added by CiviCRM.
    for (i = 0; i < submitButtons.length; ++i) {
      submitButtons[i].removeAttribute('onclick');
    }

    addSupportForCiviDiscount();

    // For CiviCRM Webforms.
    if (getIsDrupalWebform()) {
      // We need the action field for back/submit to work and redirect properly after submission

      $('[type=submit]').click(function() {
        addDrupalWebformActionElement(this.value);
      });
      // If enter pressed, use our submit function
      form.addEventListener('keydown', function (event) {
        if (event.code === 'Enter') {
          addDrupalWebformActionElement(this.value);
          submit(event);
        }
      });

      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');

      if (form.dataset.submitted === 'true') {
        return;
      }
      form.dataset.submitted = 'true';

      if (($(form).valid() === false) || $(form).data('crmBillingFormValid') === false) {
        debugging('Form not valid');
        $('div#card-errors').hide();
        swalFire({
          icon: 'error',
          text: ts('Please check and fill in all required fields!'),
          title: '',
        }, '#crm-container', true);
        triggerEvent('crmBillingFormNotValid');
        form.dataset.submitted = 'false';
        return false;
      }

      var cardError = CRM.$('#card-errors').text();
      if (CRM.$('#card-element.StripeElement--empty').length && (getTotalAmount() !== 0.0)) {
        debugging('card details not entered!');
        if (!cardError) {
          cardError = ts('Please enter your card details');
        }
        swalFire({
          icon: 'warning',
          text: '',
          title: cardError,
        }, '#card-element', true);
        triggerEvent('crmBillingFormNotValid');
        form.dataset.submitted = 'false';
        return false;
      }

      if (CRM.$('#card-element.StripeElement--invalid').length) {
        if (!cardError) {
          cardError = ts('Please check your card details!');
        }
        debugging('card details not valid!');
        swalFire({
          icon: 'error',
          text: '',
          title: cardError,
        }, '#card-element', true);
        triggerEvent('crmBillingFormNotValid');
        form.dataset.submitted = 'false';
        return false;
      }

      if (!validateReCaptcha()) {
        return false;
      }

      if (typeof CRM.vars.stripe === 'undefined') {
        debugging('Submitting - not a stripe processor');
        return true;
      }

      var stripeProcessorId = parseInt(CRM.vars.stripe.id);
      var chosenProcessorId = null;

      // Handle multiple payment options and Stripe not being chosen.
      // @fixme this needs refactoring as some is not relevant anymore (with stripe 6.0)
      if (getIsDrupalWebform()) {
        // this element may or may not exist on the webform, but we are dealing with a single (stripe) processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = stripeProcessorId;
        } else {
          chosenProcessorId = parseInt(form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((form.querySelector(".crm-section.payment_processor-section") !== null) ||
          (form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          stripeProcessorId = CRM.vars.stripe.id;
          if (form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = parseInt(form.querySelector('input[name="payment_processor_id"]:checked').value);
          }
        }
      }

      // If any of these are true, we are not using the stripe processor:
      // - Is the selected processor ID pay later (0)
      // - Is the Stripe processor ID defined?
      // - Is selected processor ID and stripe ID undefined? If we only have stripe ID, then there is only one (stripe) processor on the page
      if ((chosenProcessorId === 0) || (stripeProcessorId === null) ||
        ((chosenProcessorId === null) && (stripeProcessorId === null))) {
        debugging('Not a Stripe transaction, or pay-later');
        return nonStripeSubmit();
      }
      else {
        debugging('Stripe is the selected payprocessor');
      }

      // Don't handle submits generated by non-stripe processors
      if (typeof CRM.vars.stripe.publishableKey === 'undefined') {
        debugging('submit missing stripe-pub-key element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if (form.dataset.submitdontprocess === 'true') {
        debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (getIsDrupalWebform()) {
        // If we have selected Stripe but amount is 0 we don't submit via Stripe
        if ($('#billing-payment-block').is(':hidden')) {
          debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() === '0' || $processorFields.filter(':checked').val() === 0) {
            debugging('no payment processor selected');
            return true;
          }
        }
      }

      var totalFee = getTotalAmount();
      if (totalFee === 0.0) {
        debugging("Total amount is 0");
        return nonStripeSubmit();
      }

      // Disable the submit button to prevent repeated clicks
      for (i = 0; i < submitButtons.length; ++i) {
        submitButtons[i].setAttribute('disabled', true);
      }

      // Create a token when the form is submitted.
      handleCardPayment();

      return true;
    }
  }

  /**
   * Validate a reCaptcha if it exists on the form.
   * Ideally we would use grecaptcha.getResponse() but the reCaptcha is already render()ed by CiviCRM
   *   so we don't have clientID and can't be sure we are checking the reCaptcha that is on our form.
   *
   * @returns {boolean}
   */
  function validateReCaptcha() {
    if (typeof grecaptcha === 'undefined') {
      // No reCaptcha library loaded
      debugging('reCaptcha library not loaded');
      return true;
    }
    if ($(form).find('[name=g-recaptcha-response]').length === 0) {
      // no reCaptcha on form - we check this first because there could be reCaptcha on another form on the same page that we don't want to validate
      debugging('no reCaptcha on form');
      return true;
    }
    if ($(form).find('[name=g-recaptcha-response]').val().length > 0) {
      // We can't use grecaptcha.getResponse because there might be multiple reCaptchas on the page and we might not be the first one.
      debugging('recaptcha is valid');
      return true;
    }
    debugging('recaptcha active and not valid');
    $('div#card-errors').hide();
    swalFire({
      icon: 'warning',
      text: '',
      title: ts('Please complete the reCaptcha'),
    }, '.recaptcha-section', true);
    triggerEvent('crmBillingFormNotValid');
    form.dataset.submitted = 'false';
    return false;
  }

  function getIsDrupalWebform() {
    // form class for drupal webform: webform-client-form (drupal 7); webform-submission-form (drupal 8)
    if (form !== null) {
      return form.classList.contains('webform-client-form') || form.classList.contains('webform-submission-form');
    }
    return false;
  }

  function getBillingForm() {
    // If we have a stripe billing form on the page
    var billingFormID = $('div#card-element').closest('form').prop('id');
    if ((typeof billingFormID === 'undefined') || (!billingFormID.length)) {
      // If we have multiple payment processors to select and stripe is not currently loaded
      billingFormID = $('input[name=hidden_processor]').closest('form').prop('id');
    }
    // We have to use document.getElementById here so we have the right elementtype for appendChild()
    return document.getElementById(billingFormID);
  }

  function getBillingSubmit() {
    var submit = null;
    if (getIsDrupalWebform()) {
      submit = form.querySelectorAll('[type="submit"].webform-submit');
      if (!submit) {
        // drupal 8 webform
        submit = form.querySelectorAll('[type="submit"].webform-button--submit');
      }
    }
    else {
      submit = form.querySelectorAll('[type="submit"].validate');
    }
    return submit;
  }

  /**
   * Get the total amount on the form
   * @returns {number}
   */
  function getTotalAmount() {
    var totalFee = 0.0;
    if (isEventAdditionalParticipants()) {
      totalFee = null;
    }
    else if (CRM.payment && typeof CRM.payment.getTotalAmount == 'function') {
      return CRM.payment.getTotalAmount(form.id);
    }
    else if (document.getElementById('totalTaxAmount') !== null) {
      totalFee = parseFloat(calculateTaxAmount());
      debugging('Calculated amount using internal calculateTaxAmount()');
    }
    else if (typeof calculateTotalFee == 'function') {
      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When Stripe is the ONLY payment processor configured on the page.
      totalFee = parseFloat(calculateTotalFee());
    }
    else if (getIsDrupalWebform()) {
      // This is how webform civicrm calculates the amount in webform_civicrm_payment.js
      $('.line-item:visible', '#wf-crm-billing-items').each(function() {
        totalFee += parseFloat($(this).data('amount'));
      });
    }
    else if (document.getElementById('total_amount')) {
      // The input#total_amount field exists on backend contribution forms
      totalFee = parseFloat(document.getElementById('total_amount').value);
    }
    debugging('getTotalAmount: ' + totalFee);
    return totalFee;
  }

  /**
   * This is calculated in CRM/Contribute/Form/Contribution.tpl and is used to calculate the total
   *   amount with tax on backend submit contribution forms.
   *   The only way we can get the amount is by parsing the text field and extracting the final bit after the space.
   *   eg. "Amount including Tax: $ 4.50" gives us 4.50.
   *   The PHP side is responsible for converting money formats (we just parse to cents and remove any ,. chars).
   *
   * @returns {string|prototype.value|number}
   */
  function calculateTaxAmount() {
    var totalTaxAmount = 0;
    if (document.getElementById('totalTaxAmount') === null) {
      return totalTaxAmount;
    }

    // If tax and invoicing is disabled totalTaxAmount div exists but is empty
    if (document.getElementById('totalTaxAmount').textContent.length === 0) {
      totalTaxAmount = document.getElementById('total_amount').value;
    }
    else {
      // Otherwise totalTaxAmount div contains a textual amount including currency symbol
      totalTaxAmount = document.getElementById('totalTaxAmount').textContent.split(' ').pop();
    }
    return totalTaxAmount;
  }

  /**
   * Are we creating a recurring contribution?
   * @returns {boolean}
   */
  function getIsRecur() {
    var isRecur = false;
    // Auto-renew contributions for CiviCRM Webforms.
    if (getIsDrupalWebform()) {
      if (($('input[data-civicrm-field-key$="contribution_installments"]').length !== 0 && $('input[data-civicrm-field-key$="contribution_installments"]').val() > 1) ||
          ($('input[data-civicrm-field-key$="contribution_frequency_interval"]').length !== 0 && $('input[data-civicrm-field-key$="contribution_frequency_interval"]').val() > 0)
      ) {
        isRecur = true;
      }
    }
    // Auto-renew contributions
    if (document.getElementById('is_recur') !== null) {
      if (document.getElementById('is_recur').type == 'hidden') {
        isRecur = (document.getElementById('is_recur').value == 1);
      }
      else {
        isRecur = Boolean(document.getElementById('is_recur').checked);
      }
    }
    // Auto-renew memberships
    // This gets messy quickly!
    // input[name="auto_renew"] : set to 1 when there is a force-renew membership with no priceset.
    else if ($('input[name="auto_renew"]').length !== 0) {
      if ($('input[name="auto_renew"]').prop('checked')) {
        isRecur = true;
      }
      else if ($('input[name="auto_renew"]').attr('type') == 'hidden') {
        // If the auto_renew field exists as a hidden field, then we force a
        // recurring contribution (the value isn't useful since it depends on
        // the locale - e.g.  "Please renew my membership")
        isRecur = true;
      }
      else {
        isRecur = Boolean($('input[name="auto_renew"]').checked);
      }
    }
    debugging('isRecur is ' + isRecur);
    return isRecur;
  }

  function cardElementChanged(event) {
    if (event.empty) {
      $('div#card-errors').hide();
    }
    else if (event.error) {
      displayError(event.error.message, false);
    }
    else if (event.complete) {
      $('div#card-errors').hide();
      var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars.stripe.billingAddressID);
      if (postCodeElement) {
        postCodeElement.value = event.value.postalCode;
      }
    }
  }

  function addSupportForCiviDiscount() {
    // Add a keypress handler to set flag if enter is pressed
    cividiscountElements = form.querySelectorAll('input#discountcode');
    var cividiscountHandleKeydown = function(event) {
        if (event.code === 'Enter') {
          event.preventDefault();
          debugging('adding submitdontprocess');
          form.dataset.submitdontprocess = 'true';
        }
    };

    for (i = 0; i < cividiscountElements.length; ++i) {
      cividiscountElements[i].addEventListener('keydown', cividiscountHandleKeydown);
    }
  }

  function setBillingFieldsRequiredForJQueryValidate() {
    // Work around https://github.com/civicrm/civicrm-core/compare/master...mattwire:stripe_147
    // The main billing fields do not get set to required so don't get checked by jquery validateform.
    // This also applies to any radio button in billing/profiles so we flag every element with a crm-marker
    // See also https://github.com/civicrm/civicrm-core/pull/16488 for a core fix
    $('div.label span.crm-marker').each(function() {
      $(this).closest('div').next('div').find('input').addClass('required');
    });
    // The "name" parameter on a set of checkboxes where at least one must be checked must be the same or validation will require all of them!
    // (But we have to reset this back before we submit otherwise the submission has no data (that's a Civi issue I think).
    $('div#priceset input[type="checkbox"]').each(function() {
      $(this).attr('name', $(this).attr('name').split('[').shift());
    });

    // @todo remove once min version is 5.28 (https://github.com/civicrm/civicrm-core/pull/17672)
    var is_for_organization = $('#is_for_organization');
    if (is_for_organization.length) {
      setValidateOnBehalfOfBlock();
      is_for_organization.on('change', function() {
        setValidateOnBehalfOfBlock();
      });
    }
    function setValidateOnBehalfOfBlock() {
      if (is_for_organization.is(':checked')) {
        $('#onBehalfOfOrg select.crm-select2').removeClass('crm-no-validate');
      }
      else {
        $('#onBehalfOfOrg select.crm-select2').addClass('crm-no-validate');
      }
    }

    var validator = $(form).validate();
    validator.settings.errorClass = 'crm-inline-error alert-danger';
    validator.settings.ignore = '.select2-offscreen, [readonly], :hidden:not(.crm-select2), .crm-no-validate';
    validator.settings.ignoreTitle = true;

    // Default email validator accepts test@example but on test@example.org is valid (https://jqueryvalidation.org/jQuery.validator.methods/)
    $.validator.methods.email = function( value, element ) {
      // Regex from https://html.spec.whatwg.org/multipage/input.html#valid-e-mail-address
      return this.optional(element) || /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(value);
    };
  }

  /**
   * @returns {boolean}
   */
  function isEventAdditionalParticipants() {
    if ((document.getElementById('additional_participants') !== null) &&
      (document.getElementById('additional_participants').value.length !== 0)) {
      debugging('We don\'t know the final price - registering additional participants');
      return true;
    }
    return false;
  }

  function addDrupalWebformActionElement(submitAction) {
    var hiddenInput = null;
    if (document.getElementById('action') !== null) {
      hiddenInput = document.getElementById('action');
    }
    else {
      hiddenInput = document.createElement('input');
    }
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', 'op');
    hiddenInput.setAttribute('id', 'action');
    hiddenInput.setAttribute('value', submitAction);
    form.appendChild(hiddenInput);
  }

  /**
   * Get the selected payment processor on the form
   * @returns {null|number}
   */
  function getPaymentProcessorSelectorValue() {
    if ((typeof form === 'undefined') || (!form)) {
      form = getBillingForm();
      if (!form) {
        return null;
      }
    }
    // Frontend radio selector
    var paymentProcessorSelected = form.querySelector('input[name="payment_processor_id"]:checked');
    if (paymentProcessorSelected !== null) {
      return parseInt(paymentProcessorSelected.value);
    }
    else {
      // Backend select dropdown
      paymentProcessorSelected = form.querySelector('select[name="payment_processor_id"]');
      if (paymentProcessorSelected !== null) {
        return parseInt(paymentProcessorSelected.value);
      }
    }
    return null;
  }

  /**
   * Output debug information
   * @param {string} errorCode
   */
  function debugging(errorCode) {
    // Uncomment the following to debug unexpected returns.
    if ((typeof(CRM.vars.stripe) === 'undefined') || (Boolean(CRM.vars.stripe.jsDebug) === true)) {
      console.log(new Date().toISOString() + ' civicrm_stripe.js: ' + errorCode);
    }
  }

  /**
   * Trigger a jQuery event
   * @param {string} event
   */
  function triggerEvent(event) {
    debugging('Firing Event: ' + event);
    $(form).trigger(event);
  }

  /**
   * Trigger the crmBillingFormReloadFailed event and notify the user
   */
  function triggerEventCrmBillingFormReloadFailed() {
    triggerEvent('crmBillingFormReloadFailed');
    hidePaymentElements();
    displayError('Could not load payment element - Is there a problem with your network connection?', true);
  }

  /**
   * Wrapper around Swal.fire()
   * @param {array} parameters
   * @param {string} scrollToElement
   * @param {boolean} fallBackToAlert
   */
  function swalFire(parameters, scrollToElement, fallBackToAlert) {
    if (typeof Swal === 'function') {
      if (scrollToElement.length > 0) {
        parameters.onAfterClose = function() { window.scrollTo($(scrollToElement).position()); };
      }
      Swal.fire(parameters);
    }
    else if (fallBackToAlert) {
      window.alert(parameters.title + ' ' + parameters.text);
    }
  }

  /**
   * Wrapper around Swal.close()
   */
  function swalClose() {
    if (typeof Swal === 'function') {
      Swal.close();
    }
  }

});
