<?php

use CRM_Mjwcividbtools_ExtensionUtil as E;

/**
 * Form controller class
 */
class CRM_Mjwcividbtools_Form_ClearData extends CRM_Core_Form {

  /**
   * @var array $tables
   */
  private $tables;

  /**
   * @var array $ufContacts
   */
  private $ufContacts;

  public function buildQuickForm() {
    $this->tables = CRM_Mjwcividbtools_DbUtils::getTables();

    // Preserve contact IDs specified in civicrm_domain and civicrm_uf_match
    $dao = CRM_Core_DAO::executeQuery('SELECT contact_id from civicrm_domain');
    while ($dao->fetch()) {
      $this->ufContacts[$dao->contact_id] = $dao->contact_id;
    }
    $dao = CRM_Core_DAO::executeQuery('SELECT contact_id from civicrm_uf_match');
    while ($dao->fetch()) {
      $this->ufContacts[$dao->contact_id] = $dao->contact_id;
    }

    $this->add('text', 'contact_ids', 'Contact IDs to keep');
    foreach ($this->tables['tablesToTruncate'] as $tableName) {
      $this->add('checkbox', "tabletotruncate_{$tableName}", $tableName);
    }

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    $this->setTitle('Delete ALL data from your CiviCRM database');
  }

  public function setDefaultValues() {
    $defaults['contact_ids'] = implode(',', $this->ufContacts);
    foreach ($this->tables['tablesToTruncate'] as $tableName) {
      $defaults["tabletotruncate_{$tableName}"] = 1;
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    // Validation
    CRM_Utils_Type::validate($values['contact_ids'], 'CommaSeparatedIntegers', TRUE);

    // Action
    $logging = new CRM_Logging_Schema();
    $loggingEnabled = $logging->isEnabled();
    if ($loggingEnabled) {
      $logging->disableLogging();
      $logging->dropAllLogTables();
    }

    $queries[] = "SET FOREIGN_KEY_CHECKS = 0";
    foreach ($values as $tableName => $tableValue) {
      if (($tableName === 'contact_ids') && ($values['tabletotruncate_civicrm_contact'] == 1)) {
        $queries[] = "DELETE FROM civicrm_contact WHERE id NOT IN (" . $values['contact_ids'] . ")";
      }
      if ((substr($tableName, 0, 16) === 'tabletotruncate_') && ($tableValue == 1)) {
        $table = substr($tableName, 16);
        if ($table !== 'civicrm_contact') {
          $queries[] = "DELETE FROM {$table}";
          $queries[] = "ALTER TABLE {$table} AUTO_INCREMENT=1";
        }
      }
    }
    foreach ($this->tables['tablesToDrop'] as $dropTableName) {
      $queries[] = "DROP TABLE {$dropTableName}";
    }
    $queries[] = "SET FOREIGN_KEY_CHECKS = 1";
    foreach ($queries as $query) {
      CRM_Core_DAO::executeQuery($query);
    }
    $maxContact = (int) CRM_Core_DAO::singleValueQuery('select MAX(id) from civicrm_contact');
    CRM_Core_DAO::executeQuery('ALTER TABLE civicrm_contact AUTO_INCREMENT=' . ($maxContact + 1));

    CRM_Utils_Cache::singleton()->clear();
    if ($loggingEnabled) {
      $logging->enableLogging();
    }
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
