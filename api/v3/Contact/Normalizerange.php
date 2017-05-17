<?php

/**
 * Contact.NormalizeRange API: Normalizes a batch of contacts
 *
 * @param $params
 * @return array
 *
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contact_normalizerange($params) {
  $fromContactId = $params['from_contact_id'];
  $toContactId = $params['to_contact_id'];
  $batchSize = $params['batch_size'];
  if ($batchSize < 1) {
    $batchSize = 1;
  }
  if (!empty($params['log_file'])) {
    $logFile = $params['log_file'];
  }
  if (!empty($params['dry_run'])) {
    $dryRun = TRUE;
  }
  else {
    $dryRun = FALSE;
  }

  if ($fromContactId > $toContactId) {
    return civicrm_api3_create_error('To Contact Id can\'t be less than From Contact Id!');
  }

  $runner = CRM_Admin_Form_Setting_Normalize::getRunner( TRUE, $fromContactId, $toContactId, $batchSize, $dryRun, $logFile);
  if ($runner) {
    // Run Everything in the Queue via the Web.
    $result = $runner->runAll();

    if ($result && !isset($result['is_error'])) {
      return civicrm_api3_create_success();
    } else {
      $msg = '';
      if (isset($result)) {
        $msg .= $result['exception']->getMessage() . '; ';
      }
      if (isset($result['last_task_title'])) {
        $msg .= $result['last_task_title'] . '; ';
      }
      return civicrm_api3_create_error($msg);
    }
  }
  else {
    return civicrm_api3_create_error('Could not create runner!');
  }
}

function _civicrm_api3_contact_normalizerange_spec(&$params) {
  $params['from_contact_id']['api.required'] = 1;
  $params['from_contact_id']['title'] = 'First contact Id';
  $params['from_contact_id']['type'] = CRM_Utils_Type::T_INT;
  $params['to_contact_id']['api.required'] = 1;
  $params['to_contact_id']['title'] = 'Last contact Id';
  $params['to_contact_id']['type'] = CRM_Utils_Type::T_INT;
  $params['batch_size']['api.required'] = 1;
  $params['batch_size']['title'] = 'Batch size (min 1)';
  $params['batch_size']['type'] = CRM_Utils_Type::T_INT;
  $params['dry_run']['title'] = 'Dry Run';
  $params['dry_run']['description'] = 'Don\'t actually make any changes';
  $params['dry_run']['type'] = CRM_Utils_Type::T_BOOLEAN;
  $params['log_file']['title'] = 'Log File';
  $params['log_file']['description'] = 'If specified, changes will be logged as CSV to this file (saved in ConfigAndLog dir)';
  $params['log_file']['type'] = CRM_Utils_Type::T_STRING;
}
