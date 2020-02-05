# Events (jQuery)

Most of the functionality to take card details, validate the form etc. happens on the javascript/jquery side before the form is submitted.

If you are customising the frontend form you may need to respond to events triggered by the Stripe extension.

## Available Events

### crmBillingFormNotValid
This event is triggered when the form has been submitted but fails because of a validation error on the form.
It is most useful for re-enabling form elements that were disabled during submission.

Example code:
```javascript
    $form.on('crmBillingFormNotValid', e => {
      console.log("resetting submit button as form not submitted");
      $customSubmitButton.prop('disabled', false).text($customSubmitButton.data('text'));
    });
```

### crmBillingFormReloadComplete
This event is triggered when the form has completed reloading and is ready for use (Stripe element visible etc.).
It is useful for clearing any "loading" indicators and unfreezing form elements.