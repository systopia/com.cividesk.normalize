# Cividesk Normalize

## Description

This [CiviCRM](https://www.civicrm.org) extension normalizes your data before it is saved to the database. It therefore works from the back-end, with public form submissions such as profiles, contribution, event registration and membership creation/renewal pages as well as with data imports.

It can normalize first, middle, last and company names, phone numbers, and addresses including zip codes.

It is configurable so you have the choice between multiple options for normalizing (ie. all caps, first cap then lower case, etc), and recognizes special cases, local names and abbreviations for a number of countries/languages.

## Configuration

Settings are accessed from the 'Administer / Customize Data and Screens / Cividesk Normalize' menu.

## API Functions
### Contact.Normalize

### Contact.Normalizerange
This accepts the following parameters:
* from_contact_id: First contact Id
* to_contact_id: Last contact Id
* batch_size: Batch size (min 1)
* dry_run: Don't actually make any changes
* log_file: If specified, changes will be logged as CSV to this file (saved in ConfigAndLog dir)

Log file format is csv:
```
contactid,phone,field1,old,new,field2,old,new,field3...
contactid,address,...
```

### Notes
When you run the normalize from the UI, the log file is fixed "normalize_contacts.csv" and will be overwritten each run.