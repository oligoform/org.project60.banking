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


require_once 'CRM/Banking/Helpers/OptionValue.php';

/**
 * This matcher tries to reconcile the payments with existing contributions. 
 * There are two modes:
 *   default      - matches e.g. to pending contributions and changes the status to completed
 *   cancellation - matches negative amounts to completed contributions and changes the status to cancelled
 */
class CRM_Banking_PluginImpl_Matcher_ExistingContribution extends CRM_Banking_PluginModel_Matcher {

  /**
   * class constructor
   */ 
  function __construct($config_name) {
    parent::__construct($config_name);

    // read config, set defaults
    $config = $this->_plugin_config;
    if (!isset($config->threshold)) $config->threshold = 0.5;
    if (!isset($config->mode)) $config->mode = "default";     // other mode is "cancellation"
    if (!isset($config->accepted_contribution_states)) $config->accepted_contribution_states = array("Completed", "Pending");
    if (!isset($config->lookup_contact_by_name)) $config->lookup_contact_by_name = array('soft_cap_probability' => 0.8, 'soft_cap_min' => 5, 'hard_cap_probability' => 0.4);    
    if (!isset($config->received_date_minimum)) $config->received_date_minimum = "-100 days";
    if (!isset($config->received_date_maximum)) $config->received_date_maximum = "+1 days";
    if (!isset($config->date_penalty)) $config->date_penalty = 1.0;
    if (!isset($config->payment_instrument_penalty)) $config->payment_instrument_penalty = 0.0;
    if (!isset($config->amount_relative_minimum)) $config->amount_relative_minimum = 1.0;
    if (!isset($config->amount_relative_maximum)) $config->amount_relative_maximum = 1.0;
    if (!isset($config->amount_absolute_minimum)) $config->amount_absolute_minimum = 0;
    if (!isset($config->amount_absolute_maximum)) $config->amount_absolute_maximum = 1;
    if (!isset($config->amount_penalty)) $config->amount_penalty = 1.0;
    if (!isset($config->currency_penalty)) $config->currency_penalty = 0.5;
  }


  /**
   * Will rate a contribution on whether it would match the bank payment
   *
   * @return array(contribution_id => score), where score is from [0..1]
   */
  public function rateContribution($contribution, $context) {
    $config = $this->_plugin_config;
    $parsed_data = $context->btx->getDataParsed();

    $target_amount = $context->btx->amount;
    if ($config->mode=="cancellation") {
      if ($target_amount > 0) return -1;
      $target_amount = -$target_amount;
    } else {
      if ($target_amount < 0) return -1;
    }
    $contribution_amount = $contribution['total_amount'];
    $target_date = strtotime($context->btx->value_date);
    $contribution_date = strtotime($contribution['receive_date']);

    // check for amount limits
    $amount_delta = $contribution_amount - $target_amount;
    if (   ($contribution_amount < ($target_amount * $config->amount_relative_minimum))
        && ($amount_delta < $config->amount_absolute_minimum)) return -1;
    if (   ($contribution_amount > ($target_amount * $config->amount_relative_maximum))
        && ($amount_delta > $config->amount_absolute_maximum)) return -1;

    // check for date limits
    if ($contribution_date < strtotime($config->received_date_minimum, $target_date)) return -1;
    if ($contribution_date > strtotime($config->received_date_maximum, $target_date)) return -1;

    // calculate the penalties
    $date_delta = abs($contribution_date - $target_date);
    $date_range = max(1, strtotime($config->received_date_maximum) - strtotime($config->received_date_minimum));
    $amount_range_rel = $contribution_amount * ($config->amount_relative_maximum - $config->amount_relative_minimum);
    $amount_range_abs = $config->amount_absolute_maximum - $config->amount_absolute_minimum;
    $amount_range = max($amount_range_rel, $amount_range_abs);

    // payment_instrument match?
    $payment_instrument_penalty = 0.0;
    if (    $config->payment_instrument_penalty 
        &&  isset($contribution['payment_instrument_id'])
        &&  isset($parsed_data['payment_instrument']) ) {
      $contribution_payment_instrument_id = banking_helper_optionvalue_by_groupname_and_name('payment_instrument', $parsed_data['payment_instrument']);
      if ($contribution_payment_instrument_id != $contribution['payment_instrument_id']) {
        $payment_instrument_penalty = $config->payment_instrument_penalty;
      }
    }


    $penalty = 0.0;
    if ($date_range) $penalty += $config->date_penalty * ($date_delta / $date_range);
    if ($amount_range) $penalty += $config->amount_penalty * (abs($amount_delta) / $amount_range);
    if ($context->btx->currency != $contribution['currency'])
      $penalty += $config->currency_penalty;
    $penalty =+ $payment_instrument_penalty;

    return max(0, 1.0 - $penalty);
  }

  /**
   * Will get a the set of contributions of a given contact
   * 
   * caution: will only the contributions of the last year
   *
   * @return an array with contributions
   */
  public function getPotentialContributionsForContact($contact_id, CRM_Banking_Matcher_Context $context) {
    // check in cache
    $contributions = $context->getCachedEntry("_contributions_${contact_id}");
    if ($contributions != NULL) return $contributions;

    $contributions = array();
    $sql = "SELECT * FROM civicrm_contribution WHERE contact_id=${contact_id} AND is_test = 0 AND receive_date > (NOW() - INTERVAL 1 YEAR);";
    $contribution = CRM_Contribute_DAO_Contribution::executeQuery($sql);
    while ($contribution->fetch()) {
      array_push($contributions, $contribution->toArray());
    }

    // cache result and return
    $context->setCachedEntry("_contributions_${contact_id}", $contributions);
    return $contributions;
  }

  /**
   * read the IDs of the accepted contribution status from the configuration
   *
   * @return an array with contribution status IDs
   */
  protected function getAcceptedContributionStatusIDs() {
    $accepted_status_ids = array();
    foreach ($this->_plugin_config->accepted_contribution_states as $status_name) {
      $status_id = banking_helper_optionvalue_by_groupname_and_name('contribution_status', $status_name);
      if ($status_id) {
        array_push($accepted_status_ids, $status_id);
      }
    }
    return $accepted_status_ids;    
  }


  /** 
   * Generate a set of suggestions for the given bank transaction
   * 
   * @return array(match structures)
   */
  public function match(CRM_Banking_BAO_BankTransaction $btx, CRM_Banking_Matcher_Context $context) {
    $config = $this->_plugin_config;
    $threshold   = $this->getThreshold();
    $penalty     = $this->getPenalty($btx);
    $data_parsed = $btx->getDataParsed();

    // resolve accepted states
    $accepted_status_ids = $this->getAcceptedContributionStatusIDs();

    // find contacts    
    $contacts_found = $context->findContacts($threshold, $data_parsed['name'], $config->lookup_contact_by_name);

    // with the identified contacts, look up contributions
    $contributions = array();
    $contribution2contact = array();

    foreach ($contacts_found as $contact_id => $contact_probabiliy) {
      if ($contact_probabiliy < $threshold) continue;

      $potential_contributions = $this->getPotentialContributionsForContact($contact_id, $context);
      foreach ($potential_contributions as $contribution) {
        // check for expected status
        if (!in_array($contribution['contribution_status_id'], $accepted_status_ids)) continue;

        $contribution_probability = $this->rateContribution($contribution, $context);

        // apply penalty
        $contribution_probability -= $penalty;

        if ($contribution_probability > $threshold) {
          $contributions[$contribution['id']] = $contribution_probability;
          $contribution2contact[$contribution['id']] = $contact_id;
        }        
      }
    }

    // transform all of the contributions found into suggestions
    foreach ($contributions as $contribution_id => $contribution_probability) {
      $contact_id = $contribution2contact[$contribution_id];
      $suggestion = new CRM_Banking_Matcher_Suggestion($this, $btx);
      if ($contacts_found[$contact_id]>=1.0) {
        $suggestion->addEvidence(1.0, ts("Contact was positively identified."));
      } else {
        $suggestion->addEvidence($contacts_found[$contact_id], ts("Contact was likely identified."));
      }
      
      if ($contribution_probability>=1.0) {
        $suggestion->setTitle(ts("Matching contribution found"));
        if ($config->mode != "cancellation") {
          $suggestion->addEvidence(1.0, ts("A pending contribution matching the transaction was found."));
        } else {
          $suggestion->addEvidence(1.0, ts("This transaction is the <b>cancellation</b> of the below contribution."));
        }
      } else {
        $suggestion->setTitle(ts("Possible matching contribution found"));
        if ($config->mode != "cancellation") {
          $suggestion->addEvidence($contacts_found[$contact_id], ts("A pending contribution partially matching the transaction was found."));
        } else {
          $suggestion->addEvidence($contacts_found[$contact_id], ts("This transaction could be the <b>cancellation</b> of the below contribution."));
        }
      }

      $suggestion->setId("existing-$contribution_id");
      $suggestion->setParameter('contribution_id', $contribution_id);
      $suggestion->setParameter('contact_id', $contact_id);

      // set probability manually, I think the automatic calculation provided by ->addEvidence might not be what we need here
      $suggestion->setProbability($contribution_probability*$contacts_found[$contact_id]);
      $btx->addSuggestion($suggestion);
    }

    // that's it...
    return empty($this->_suggestions) ? null : $this->_suggestions;
  }

  /**
   * Handle the different actions, should probably be handles at base class level ...
   * 
   * @param type $match
   * @param type $btx
   */
  public function execute($suggestion, $btx) {
    $contribution_id = $suggestion->getParameter('contribution_id');
    $query = array('version' => 3, 'id' => $contribution_id);
    $query = array_merge($query, $this->getPropagationSet($btx, 'contribution'));   // add propagated values

    // double check contribution (see https://github.com/Project60/CiviBanking/issues/61)
    $contribution = civicrm_api('Contribution', 'getsingle', array('id' => $contribution_id, 'version' => 3));
    if (!empty($contribution['is_error'])) {
      CRM_Core_Session::setStatus(ts('Contribution has disappeared.').' '.ts('Error was:').' '.$contribution['error_message'], ts('Execution Failure'), 'alert');
      return false;
    }
    $accepted_status_ids = $this->getAcceptedContributionStatusIDs();
    if (!in_array($contribution['contribution_status_id'], $accepted_status_ids)) {
      CRM_Core_Session::setStatus(ts('Contribution status has been modified.'), ts('Execution Failure'), 'alert');
      return false;
    }

    // depending on mode...
    if ($this->_plugin_config->mode != "cancellation") {
      $query['contribution_status_id'] = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'Completed');
      $query['receive_date'] = date('YmdHis', strtotime($btx->booking_date));
    } else {
      $query['contribution_status_id'] = banking_helper_optionvalue_by_groupname_and_name('contribution_status', 'Cancelled');
      $query['cancel_date'] = date('YmdHis', strtotime($btx->booking_date));
      //$query['cancel_reason'] = date('YmdHis', strtotime($btx->booking_date));
    }
    
    $result = civicrm_api('Contribution', 'create', $query);
    if (isset($result['is_error']) && $result['is_error']) {
      CRM_Core_Session::setStatus(ts("Couldn't modify contribution."), ts('Error'), 'error');
    } else {
      // everything seems fine, save the account
      if (!empty($result['values'][$contribution_id]['contact_id'])) {
        $this->storeAccountWithContact($btx, $result['values'][$contribution_id]['contact_id']);
      } elseif (!empty($result['values'][0]['contact_id'])) {
        $this->storeAccountWithContact($btx, $result['values'][0]['contact_id']);
      }
    }

    $newStatus = banking_helper_optionvalueid_by_groupname_and_name('civicrm_banking.bank_tx_status', 'Processed');
    $btx->setStatus($newStatus);
    parent::execute($suggestion, $btx);
    return true;
  }

  /**
   * If the user has modified the input fields provided by the "visualize" html code,
   * the new values will be passed here BEFORE execution
   *
   * CAUTION: there might be more parameters than provided. Only process the ones that
   *  'belong' to your suggestion.
   */
  public function update_parameters(CRM_Banking_Matcher_Suggestion $match, $parameters) {
    // NOTHING to do...
  }

    /** 
   * Generate html code to visualize the given match. The visualization may also provide interactive form elements.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_match( CRM_Banking_Matcher_Suggestion $match, $btx) {
    $smarty = CRM_Core_Smarty::singleton();

    // load the data
    $contribution_id = $match->getParameter('contribution_id');
    $smarty->assign('contribution_id', $contribution_id);

    $contribution = civicrm_api('Contribution', 'getsingle', array('id' => $contribution_id, 'version' => 3));
    if (empty($contribution['is_error'])) {
      $smarty->assign('contribution', $contribution);      

      $contact = civicrm_api('Contact', 'getsingle', array('id' => $contribution['contact_id'], 'version' => 3));
      if (empty($contact['is_error'])) {
        $smarty->assign('contact', $contact);        
      } else {
        $smarty->assign('error', $contact['error_message']);
      }
    } else {
      $smarty->assign('error', $contribution['error_message']);
    }

    $smarty->assign('reasons', $match->getEvidence());

    // assign to smarty and compile HTML
    return $smarty->fetch('CRM/Banking/PluginImpl/Matcher/ExistingContribution.suggestion.tpl');
  }

  /** 
   * Generate html code to visualize the executed match.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_execution_info( CRM_Banking_Matcher_Suggestion $match, $btx) {
    // just assign to smarty and compile HTML
    $smarty = CRM_Core_Smarty::singleton();
    $smarty->assign('contribution_id',  $match->getParameter('contribution_id'));
    $smarty->assign('contact_id',       $match->getParameter('contact_id'));
    return $smarty->fetch('CRM/Banking/PluginImpl/Matcher/ExistingContribution.execution.tpl');
  }
}

