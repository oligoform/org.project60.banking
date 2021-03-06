<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

    
require_once 'CRM/Core/Page.php';
require_once 'CRM/Banking/Helpers/OptionValue.php';
require_once 'CRM/Banking/Helpers/URLBuilder.php';

class CRM_Banking_Page_Payments extends CRM_Core_Page {
  function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('Bank Transactions'));

    // look up the payment states
    $payment_states = banking_helper_optiongroup_id_name_mapping('civicrm_banking.bank_tx_status');

    if (!isset($_REQUEST['status_ids'])) {
      $_REQUEST['status_ids'] = $payment_states['new']['id'];
    }

    if (isset($_REQUEST['show']) && $_REQUEST['show']=="payments") {
        // PAYMENT MODE REQUESTED
        $this->build_paymentPage($payment_states);
        $list_type = 'list';
    } else {
        // STATEMENT MODE REQUESTED
        $this->build_statementPage($payment_states);
        $list_type = 's_list';
    }

    // URLs
    global $base_url;
    $this->assign('base_url', $base_url);

    $this->assign('url_show_payments', banking_helper_buildURL('civicrm/banking/payments', array('show'=>'payments', $list_type=>"__selected__"), array('status_ids')));
    $this->assign('url_show_statements', banking_helper_buildURL('civicrm/banking/payments', array('show'=>'statements'), array('status_ids')));

    $this->assign('url_show_payments_new', banking_helper_buildURL('civicrm/banking/payments', $this->_pageParameters(array('status_ids'=>$payment_states['new']['id']))));
    $this->assign('url_show_payments_analysed', banking_helper_buildURL('civicrm/banking/payments', $this->_pageParameters(array('status_ids'=>$payment_states['suggestions']['id']))));
    $this->assign('url_show_payments_completed', banking_helper_buildURL('civicrm/banking/payments', $this->_pageParameters(array('status_ids'=>$payment_states['processed']['id'].",".$payment_states['ignored']['id']))));

    $this->assign('url_review_selected_payments', banking_helper_buildURL('civicrm/banking/review', array($list_type=>"__selected__")));
    $this->assign('url_export_selected_payments', banking_helper_buildURL('civicrm/banking/export', array($list_type=>"__selected__")));

    $this->assign('can_delete', CRM_Core_Permission::check('administer CiviCRM'));
    
    // status filter button styles
    if (isset($_REQUEST['status_ids']) && strlen($_REQUEST['status_ids'])>0) {
      if ($_REQUEST['status_ids']==$payment_states['new']['id']) {
        $this->assign('button_style_new', "color:green");
      } else if ($_REQUEST['status_ids']==$payment_states['suggestions']['id']) {
        $this->assign('button_style_analysed', "color:green");
      } else if ($_REQUEST['status_ids']==$payment_states['processed']['id'].",".$payment_states['ignored']['id']) {
        $this->assign('button_style_completed', "color:green");
      } else {
        $this->assign('button_style_custom', "color:green");
      }
    }

    parent::run();
  }

  /****************
   * STATEMENT MODE
   ****************/
  function build_statementPage($payment_states) {
    $target_ba_id = null;
    if (isset($_REQUEST['target_ba_id'])) {
      $target_ba_id = $_REQUEST['target_ba_id'];
    }

    $statements_new = array();
    $statements_analysed = array();
    $statements_completed = array();
    
    // collect an array of target accounts, serving to limit the display
    $target_accounts = array();
    
    // TODO: we NEED a tx_batch status field, see https://github.com/Project60/CiviBanking/issues/20
    $sql_query =    // this query joins the bank_account table to determine the target account
        "SELECT 
          btxb.id AS id, 
          ba.id AS ba_id, 
          reference, 
          btxb.sequence as sequence, 
          starting_date, 
          tx_count, 
          ba_id, 
          ba.data_parsed as data_parsed,
          sum(btx.amount) as total,
          btx.currency as currency
        FROM 
          civicrm_bank_tx_batch btxb
          LEFT JOIN civicrm_bank_tx btx ON btx.tx_batch_id = btxb.id 
          LEFT JOIN civicrm_bank_account ba ON ba.id = btx.ba_id "
          .
            ($target_ba_id ? ' WHERE ba_id = ' . $target_ba_id : '')
          . 
          "
        GROUP BY 
          id
        ORDER BY 
          starting_date DESC;";
    $stmt = CRM_Core_DAO::executeQuery($sql_query);
    while($stmt->fetch()) {
      // check the states
      $info = $this->investigate($stmt->id, $payment_states);

      // look up the target account
      $target_name = ts("Unnamed");
      $target_info = json_decode($stmt->data_parsed);
      if (isset($target_info->name)) {
        $target_name = $target_info->name;
      }

      // finally, create the data row
      $row = array(  
                    'id' => $stmt->id, 
                    'reference' => $stmt->reference, 
                    'sequence' => $stmt->sequence, 
                    'total' => $stmt->total, 
                    'currency' => $stmt->currency, 
                    'date' => strtotime($stmt->starting_date), 
                    'count' => $stmt->tx_count, 
                    'target' => $target_name,
                    'analysed' => $info['analysed'].'%',
                    'completed' => $info['completed'].'%',
                );

      // sort it
      if ($info['completed']==100) {
        array_push($statements_completed, $row);
      } else {
        if ($info['analysed']>0) {
          array_push($statements_analysed, $row);
        }
        if ($info['analysed']<100) {
          array_push($statements_new, $row);
        }
      }
      
      // collect the target BA
      $target_accounts[ $stmt->ba_id ] = $target_name;
      
    }

    if ($_REQUEST['status_ids']==$payment_states['new']['id']) {
      // 'NEW' mode will show all that have not been completely analysed
      $this->assign('rows', $statements_new);
      $this->assign('status_message', sprintf(ts("%d incomplete statments."), sizeof($statements_new)));

    } elseif ($_REQUEST['status_ids']==$payment_states['suggestions']['id']) {
      // 'ANALYSED' mode will show all that have been partially analysed, but not all completed
      $this->assign('rows', $statements_analysed);
      $this->assign('status_message', sprintf(ts("%d analysed statments."), sizeof($statements_analysed)));

    } else {
      // 'COMPLETE' mode will show all that have been entirely processed
      $this->assign('rows', $statements_completed);
      $this->assign('status_message', sprintf(ts("%d completed statments."), sizeof($statements_completed)));
    }
    
    $this->assign('target_accounts', $target_accounts);        
    $this->assign('target_ba_id', $target_ba_id);        
    $this->assign('show', 'statements');        
  }


  /****************
   * PAYMENT MODE
   ****************/
  function build_paymentPage($payment_states) {
    // read all transactions
    $btxs = $this->load_btx($payment_states);
    $payment_rows = array();
    foreach ($btxs as $entry) {
        $status = $payment_states[$entry['status_id']]['label'];
        $data_parsed = json_decode($entry['data_parsed'], true);

        if (empty($entry['ba_id'])) {
          $bank_account = array('description' => ts('Unknown'));
        } else {
          $ba_id = $entry['ba_id'];
          $params = array('version' => 3, 'id' => $ba_id);
          $bank_account = civicrm_api('BankingAccount', 'getsingle', $params);
        } 
        
        $contact = null;
        $attached_ba = null;
        $party = null;
        if (!empty($entry['party_ba_id'])) {
          $pba_id = $entry['party_ba_id'];
          $params = array('version' => 3, 'id' => $pba_id);
          $attached_ba = civicrm_api('BankingAccount', 'getsingle', $params);
        }
        
        $cid = isset($attached_ba['contact_id']) ? $attached_ba['contact_id'] : null;
        if ($cid) {
          $params = array('version' => 3, 'id' => $cid);
          $contact = civicrm_api('Contact', 'getsingle', $params);
        }

        if (isset($attached_ba['description'])) {
          $party = $attached_ba['description'];
        } else {
          if (isset($data_parsed['name'])) {
            $party = "<i>".$data_parsed['name']."</i>";
          } else {
            $party = "<i>".ts("not yet identified.")."</i>";
          }
        }
        
        array_push($payment_rows, 
            array(  
                    'id' => $entry['id'], 
                    'date' => $entry['value_date'], 
                    'sequence' => $entry['sequence'], 
                    'currency' => $entry['currency'], 
                    'amount' => (isset($entry['amount'])?$entry['amount']:"unknown"), 
                    'account_owner' => $bank_account['description'], 
                    'party' => $party,
                    'party_contact' => $contact,
                    'state' => $status,
                    'url_link' => CRM_Utils_System::url('civicrm/banking/review', 'id='.$entry['id']),
                    'payment_data_parsed' => $data_parsed,
                )
        );
    }

    $this->assign('rows', $payment_rows);
    $this->assign('show', 'payments');        
    if ($_REQUEST['status_ids']==$payment_states['new']['id']) {
      // 'NEW' mode will show all that have not been completely analysed
      $this->assign('status_message', sprintf(ts("%d new transactions."), count($payment_rows)));

    } elseif ($_REQUEST['status_ids']==$payment_states['suggestions']['id']) {
      // 'ANALYSED' mode will show all that have been partially analysed, but not all completed
      $this->assign('status_message', sprintf(ts("%d analysed transactions."), count($payment_rows)));

    } else {
      // 'COMPLETE' mode will show all that have been entirely processed
      $this->assign('status_message', sprintf(ts("%d completed transactions."), count($payment_rows)));
    }
  }


  /****************
   *    HELPERS
   ****************/

  /**
   * will take a comma separated list of statement IDs and create a list of the related payment ids in the same format
   */
  public static function getPaymentsForStatements($raw_statement_list) {
    $payments = array();
    $raw_statements = explode(",", $raw_statement_list);
    if (count($raw_statements)==0) {
      return '';
    }

    $statements = array();
    # make sure, that the statments are all integers (SQL injection)
    foreach ($raw_statements as $stmt_id) {
      array_push($statements, intval($stmt_id));
    }
    $statement_list = implode(",", $statements);

    $sql_query = "SELECT id FROM civicrm_bank_tx WHERE tx_batch_id IN ($statement_list);";
    $stmt_ids = CRM_Core_DAO::executeQuery($sql_query);
    while($stmt_ids->fetch()) {
      array_push($payments, $stmt_ids->id);
    }
    return implode(",", $payments);
  }

  /**
   * will iterate through all transactions in the given statements and
   * return an array with some further information:
   *   'analysed'      => percentage of analysed statements
   *   'completed'      => percentage of completed statements
   *   'target_account' => the target account
   */
  function investigate($stmt_id, $payment_states) {
    // go over all transactions to find out rates and data
    $stmt_id = intval($stmt_id);
    $count = 0;

    $sql_query = "SELECT status_id, COUNT(status_id) AS count FROM civicrm_bank_tx WHERE tx_batch_id=$stmt_id GROUP BY status_id;";
    $stats = CRM_Core_DAO::executeQuery($sql_query);
    // this creates a table: | status_id | count |
    
    $status2count = array();
    while ($stats->fetch()) {
      $status2count[$stats->status_id] = $stats->count;
      $count += $stats->count;
    }

    if ($count) {
      // count the individual values
      $analysed_state_id = $payment_states['suggestions']['id'];
      $analysed_count = 0;
      if (isset($status2count[$analysed_state_id])) {
        $analysed_count = $status2count[$analysed_state_id];
      }
      $completed_state_id = $payment_states['processed']['id'];
      $completed_count = 0;
      if (isset($status2count[$completed_state_id])) {
        $completed_count = $status2count[$completed_state_id];
      }
      $ignored_state_id = $payment_states['ignored']['id'];
      if (isset($status2count[$ignored_state_id])) {
        $completed_count += $status2count[$ignored_state_id];
      }

      return array(
        'analysed'       => floor(($analysed_count+$completed_count) / $count * 100.0),
        'completed'      => floor($completed_count / $count * 100.0),
        'target_account' => "Unknown"
        );
    } else {
      return array(
        'analysed'       => 0,
        'completed'      => 0,
        'target_account' => "Unknown"
        );
    }
  }


   /**
   * load BTXs according to the 'status_ids' and 'batch_ids' values in $_REQUEST
   *
   * @return array of (later: up to $page_size) BTX objects (as arrays)
   */
  function load_btx($payment_states) {  // TODO: later add: $page_nr=0, $page_size=50) {
    // set defaults
    $status_ids = array($payment_states['new']['id']);
    $batch_ids = array(NULL);

    if (isset($_REQUEST['status_ids']))
        $status_ids = explode(',', $_REQUEST['status_ids']);
    if (isset($_REQUEST['s_list']))
        $batch_ids = explode(',', $_REQUEST['s_list']);

    // run the queries
    $results = array();
    foreach ($status_ids as $status_id) {
        foreach ($batch_ids as $batch_id) {
            //$results = array_merge($results, $this->_findBTX($status_id, $batch_id));
            $results += $this->_findBTX($status_id, $batch_id);
        }
    }

    return $results;
  }

  function _findBTX($status_id, $batch_id) {
    $params = array('version' => 3,'option.limit'=>999);
    if ($status_id!=NULL) $params['status_id'] = $status_id;
    if ($batch_id!=NULL) $params['tx_batch_id'] = $batch_id;
    $result = civicrm_api('BankingTransaction', 'get', $params);
    if (isset($result['is_error']) && $result['is_error']) {
      CRM_Core_Error::error(sprintf(ts("Error while querying BTX with parameters '%s'!"), implode(',', $params)));
      return array();
    } elseif (count($result['values'])>=999) {
      CRM_Core_Session::setStatus(sprintf(ts('Internal limit of 1000 transactions hit. Please use smaller statements.')), ts('Processing incomplete'), 'alert');
      return $result['values'];
    } else {
      return $result['values'];
    }
  }

  /**
   * creates an array of all properties defining the current page's state
   * 
   * if $override is given, it will be taken into the array regardless
   */
  function _pageParameters($override=array()) {
    $params = array();
    if (isset($_REQUEST['status_ids']))
        $params['status_ids'] = $_REQUEST['status_ids'];
    if (isset($_REQUEST['tx_batch_id']))
        $params['tx_batch_id'] = $_REQUEST['tx_batch_id'];
    if (isset($_REQUEST['s_list']))
        $params['s_list'] = $_REQUEST['s_list'];
    if (isset($_REQUEST['show']))
        $params['show'] = $_REQUEST['show'];

    foreach ($override as $key => $value) {
        $params[$key] = $value;
    }
    return $params;
  }
}
