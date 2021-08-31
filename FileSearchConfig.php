<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * FileSearch module - configuration
 * 
 * Provides indexing and search for documents.
 * 
 * Copyright 2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

class FileSearchConfig extends ModuleConfig {

  public function getDefaults() {
    return array(
      'file_field' => 'pdf_file',
      'pageindex_field' => 'pdf_page_texts',
      'pdfseparate' => '/usr/bin/pdfseparate',
      'pdftotext' => '/usr/bin/pdftotext',
      'action' => '',
      'reindex' => 0,
      'indexmissing' => 0,
      'indexpages' => 0,
      'search_engine' => 'Solr',
      'solr_host' => 'localhost',
      'solr_port' => 8983,
      'solr_path' => 'solr',
      // TODO add textfield for additional fields to index in solr
    );
  }

  public function getInputfields() {
    $inputfields = parent::getInputfields();
    
    $fieldset = $this->wire('modules')->get('InputfieldFieldset');
    $fieldset->label = __('Requirements');

    if (!$this->modules->isInstalled('Tasker')) {
      $f = $this->modules->get('InputfieldMarkup');
      $this->message('Tasker module is missing.', Notice::warning);
      $f->value = '<p>Tasker module is missing. It can help in processing large PDF documents.</p>';
      $f->columnWidth = 50;
      $fieldset->add($f);
    }

    $inputfields->add($fieldset);


/********************  Field name settings ****************************/
    $fieldset = $this->wire('modules')->get("InputfieldFieldset");
    $fieldset->label = __("Field setup");

    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'file_field');
    $f->label = 'Field that contains files to be indexed.';
    $f->description = __('E.g. use the FieldtypePDF module to create the field to store PDF files.');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 50;
    foreach ($this->wire('fields') as $field) {
      if (!$field->type instanceof FieldtypeFile) continue;
      $f->addOption($field->name, $field->label);
    }
    $fieldset->add($f);

    $inputfields->add($fieldset);

/********************  Search Engine settings ****************************/
    $fieldset = $this->wire('modules')->get("InputfieldFieldset");
    $fieldset->label = __("Search Engine configuration");

    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'search_engine');
    $f->label = 'Search engine.';
    $f->description = __('Internal or Solr (if supported).');
    $f->options = array();
    $f->required = true;
    $f->columnWidth = 50;
    $f->addOption('Internal', 'Internal');
    $f->addOption('Solr', 'Solr with cURL');
    $fieldset->add($f);

// TODO this does not work
//    if ($this->search_engine == 'solr') {
      $f = $this->modules->get('InputfieldText');
      $f->attr('name', 'solr_host');
      $f->label = __('Hostname');
      $f->description = __('Location of the Solr server.'.$this->config->search_engine);
      $f->required = true;
      $f->columnWidth = 50;
      $fieldset->add($f);

      $f = $this->modules->get('InputfieldText');
      $f->attr('name', 'solr_port');
      $f->label = __('Port');
      $f->description = __('Port number');
      $f->required = true;
      $f->columnWidth = 50;
      $fieldset->add($f);

      $f = $this->modules->get('InputfieldText');
      $f->attr('name', 'solr_path');
      $f->label = 'Solr Path';
      $f->description = __('Speficy the path to the solr core you would like to use.');
      $f->required = true;
      $f->columnWidth = 50;
      $fieldset->add($f);

//    } else {

      $f = $this->modules->get('InputfieldSelect');
      $f->attr('name', 'pageindex_field');
      $f->label = 'Field that contains the internal search index organized by file pages.';
      $f->description = __('Create a hidden repeater field beneath it that contains a page_number (Integer) and a page_text (Textarea).');
      $f->options = array();
      $f->required = true;
      $f->columnWidth = 50;
      foreach ($this->wire('fields') as $field) {
        if (!$field->type instanceof FieldtypeRepeater) continue;
        $f->addOption($field->name, $field->label);
        // TODO check repeater subfields
      }
      $fieldset->add($f);
//    }

    $inputfields->add($fieldset);

/********************  Executables ****************************/
    $fieldset = $this->wire('modules')->get("InputfieldFieldset");
    $fieldset->label = __("PDF conversion tools");

    $f = $this->modules->get('InputfieldText');
    $f->attr('name', 'pdfseparate');
    $f->label = __('PDFseparate executable.');
    $f->description = __('Location of the executable');
    $f->required = true;
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldText');
    $f->attr('name', 'pdftotext');
    $f->label = __('PDFtotext executable.');
    $f->description = __('Location of the executable');
    $f->required = true;
    $f->columnWidth = 50;
    $fieldset->add($f);
    $inputfields->add($fieldset);

/********************  Other options ****************************/
    $fieldset = $this->wire('modules')->get("InputfieldFieldset");
    $fieldset->label = __("Run-time options");


    $f = $this->modules->get('InputfieldSelect');
    $f->attr('name', 'action');
    $f->label = 'Perform a task on all files.';
    $f->description = __('Create missing indices, clear the index, reindex all files etc.');
    $f->options = array(
      'indexmissing' => 'Index new files',
      'indexall' => 'Reindex all files',
      'reset' => 'Remove the index'
    );
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'reindex');
    $f->label = __('Reindex all pages');
    $f->description = __('Clear existing page indices and process all PDF fields again.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'indexmissing');
    $f->label = __('Index all missing documents');
    $f->description = __('Create missing indices for PDF documents.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    $f = $this->modules->get('InputfieldCheckbox');
    $f->attr('name', 'indexpages');
    $f->label = __('Index all pages separately');
    $f->description = __('It makes it possible to return page numbers with search results.');
    $f->columnWidth = 50;
    $fieldset->add($f);

    // TODO conversion options

    $inputfields->add($fieldset);

    return $inputfields;
  }
}
