# Webform User Registration

This module adds a webform handler that allows to register user after he/she completes the form. The handler settings 
allow to map webform fields with the fields in user profile, to have the fields that will be not updated in the profile,
prefill the webform with the fields from profile, send the letter of registration on webform complete and log the user in
immediately after form submit.

# Make it work

This module provides only webform handler and its functionality is not applied to all webforms by default. To make it 
work you need to navigate to "Emails/Handlers" tab on your webform edit screen and click on "Add handler" button, 
select "Register user" handler from the list and "Save". Only after this your webform will support 
javascript validation.
