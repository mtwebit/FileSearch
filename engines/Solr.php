<?php
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * FileSearch module - Solr search engine
 * 
 * Provides indexing and search services using Solr
 * 
 * Copyright 2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */


class Solr {
  protected $module;

  public function __construct(ProcessWire\FileSearch $module) {
    $this->module = $module;
  }

  /**
   * Create a literal ID for a file stored on a page
   * 
   * @param $fPage PW Page
   * @param $filename a base name of the file
   * @returns the literal ID string
   */
  private function getID($fPage, $filename) {
    return $fPage->id.'_'.$filename;
  }


  /**
   * Make a request to the engine
   * 
   * @param $command Solr URL request parameters
   * @param $pFile the file to process (a Pagefile or a string containing full pathname)
   * @returns false on error, cURL JSON result on success
   */
  private function request($command, $pFile = NULL) {
    // TODO For optimum performance when loading many documents, don’t call the commit command until you are done.
    $solr_options = 'commit=false&stored=true';
    $base_url = "http://{$this->module->solr_host}:{$this->module->solr_port}/{$this->module->solr_path}/";
    $ch = curl_init($base_url.$command);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // TODO better timeouts
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 100); //timeout in seconds
    if ($pFile) {
      if (is_string($pFile)) {
        $mime = mime_content_type($pFile);
        $cFile = new \CURLFile($pFile, $mime, basename($pFile));
      } else {
        $mime = mime_content_type($pFile->filename);
        $cFile = new \CURLFile($pFile->filename, $mime, $pFile->name);
      }
      if ($mime === false) {
        $this->module->error("ERROR: Solr request failed. Could not determine mime type for file '{$filename}'.");
        curl_close($ch);
        return false;
      }      curl_setopt($ch, CURLOPT_POSTFIELDS, array('myfile' => $cFile));
    }
    $this->module->message("Sending CURL request to ".curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), ProcessWire\Notice::debug);
    $result = curl_exec ($ch);
    if ($result === false) {
      $this->module->error("ERROR: request failed. ".curl_error($ch));
      curl_close($ch);
      return false;
    }

    $this->module->message('Solr returned ['.curl_getinfo($ch, CURLINFO_RESPONSE_CODE).'] '.strip_tags($result), ProcessWire\Notice::debug);

    if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) != '200') {
      $this->module->message("Solr failed to execute {$command}.");
      curl_close($ch);
      return false;
    }

    curl_close($ch);
    return $result;
  }

  /**
   * Index a file
   * 
   * @param $fPage ProcessWire Page object (the page contains the file to index)
   * @param $pFile the file to process (a Pagefile or a string containing full pathname)
   * @returns false on error, cURL JSON result on success
   */
  public function index($fPage, $pFile, $options = array()) {
    if (isset($options['filename_prefix'])) {   // typically used when each page is saved separately
      $filename = $options['filename_prefix'];
    } else $filename = '';
    if (is_string($pFile)) {
      $filename .= basename($pFile);
    } else {
      $filename .= $pFile->basename;
    }
    $literal_name = 'literal.name='.urlencode($filename);
    $fields = 'literal.pw_page_id='.$fPage->id.'&literal.id='.$this->getID($fPage, $filename);

    // process additional field data
    if (isset($options['fields'])) {
      foreach($options['fields'] as $field => $value) {
        $fields .= '&literal.'.$field.'='.$value;
      }
    }

    // For optimum performance when loading many documents, don’t call the commit command until you are done.
    // TODO who calls commit?
    $solr_options = 'commit=false&stored=true';

    // Execute the request
    if ($ret = $this->request("update/extract/?{$fields}&{$literal_name}&{$solr_options}", $pFile)) {
      $this->module->message($filename.' has been processed by Solr.');
    }
    return $ret;
  }


  /**
   * Checks whether a file has been indexed or not
   * 
   * @param $fPage ProcessWire Page object (the page contains the file to index)
   * @param $pFile the file to process (a Pagefile or a string containing full pathname)
   * @returns true if yes, false otherwise
   */
  public function isIndexed($fPage, $pFile) {
    $result = $this->query(array(
      'fields' => 'id,name,pw_page_id,pw_author_id',
      'text' => 'id:'.$this->getID($fPage, $pFile->basename).'*')); // * handles page-based naming
    if ($result === false) return false;
    // check the internal pw page id
    // $this->module->message("Checking indexing status {$fPage->title} / {$pFile->name}: ".var_export($result, true), ProcessWire\Notice::debug);
    if (!isset($result[$fPage->id][0]['pw_page_id'])
        || $result[$fPage->id][0]['pw_page_id'] != $fPage->id) return false;
    if (!isset($result[$fPage->id][0]['pw_author_id'])) return false;
    return ($result[0]['response']['numFound'] > 0);
  }


  /**
   * Perform a Solr query
   * 
   * @param $query array of query parameters
   * @returns false on error, array of (pw_page_id => array of (search_result(s))) otherwise
   * @returns the entire Solr response array at index 0 of the return value
   */
  public function query($query) {
    if (!is_array($query) || !isset($query['text']) || !strlen($query['text'])) {
      $this->module->error('ERROR: Solr query failed. Invalid query array.');
      return false;
    }
    $q = urlencode($query['text']);

  // From the Solr docs: Solr can sort query responses according to document scores
  // or the value of any field with a single value
  // https://lucene.apache.org/solr/guide/6_6/common-query-parameters.html#CommonQueryParameters-ThesortParameter
    if (isset($query['sort'])) { // return the most important fields
      $sort = '&sort='.urlencode($query['sort']);
    } else $sort = '';

  // From the Solr docs: The fq parameter defines a query that can be used to restrict
  // the superset of documents that can be returned, without influencing score.
  // https://lucene.apache.org/solr/guide/6_6/common-query-parameters.html#CommonQueryParameters-Thefq_FilterQuery_Parameter
  // This can also be used for field collapsing
  // https://lucene.apache.org/solr/guide/6_6/collapse-and-expand-results.html#CollapseandExpandResults-CollapsingQueryParser
    if (isset($query['filter'])) {
    // TODO use array + explode here
      $fq = '&fq='.urlencode($query['filter']);
    } else $fq = '';

  // From the Solr docs: The fl parameter limits the information included
  // in a query response to a specified list of fields.
  // https://lucene.apache.org/solr/guide/6_6/common-query-parameters.html#CommonQueryParameters-Thefl_FieldList_Parameter
    if (!isset($query['fields'])) { // return the most important fields
      $query['fields'] = 'id,name,pw_page_id,page_num';
    } else {
      // TODO use array + explode here
      // TODO ensure that the above fields are included
    }
    $fl = '&fl='.urlencode($query['fields']);

  // From the Solr docs: Solr allows fragments of documents that match
  // the user’s query to be included with the query response.
  // https://lucene.apache.org/solr/guide/6_6/highlighting.html
    if (isset($query['highlight'])) {
      if (is_string($query['highlight'])) {
        $hl = urlencode($query['highlight']);
      } else {
        $hl = $q;
      }
      $hl = '&hl=on&hl.fragsize=200&hightlightMultiTerm=true&hl.simple.post=<%2Fu>&hl.simple.pre=<u>&hl.fl=content&q=_text_:'.$hl;
    } else $hl = '';


    // pagination
    if (isset($query['start'])) {
      $start = $query['start'];
    } else $start = 0;
    if (isset($query['rows'])) {
      $rows = $query['rows'];
    } else $rows = 10;

    $result = $this->request("select?q={$q}{$fq}{$fl}{$hl}{$sort}&start={$start}&rows={$rows}");
    if ($result === false) return false;

    $this->module->log->save('solr', 'SOLR raw search result: ' . var_export($result, true));

    $result = json_decode($result, true); // TODO check

    // the result array
    $ret = array();
    // we store the original result at index 0
    $ret[] = $result;
    // build the result array
    foreach($result['response']['docs'] as $res) {
      if (!isset($res['pw_page_id'])) {
        $this->module->warning('WARNING: missing pw_page_id in document "'.$res['name'].'".');
        list($idguess, $nameguess) = sscanf($res['name'], '%d_%s');
        if (is_integer($idguess) && $idguess>0) $ret[$idguess] = $res;
      } else if (!isset($ret[$res['pw_page_id']])) { // found the first match for the PW page
        $ret[$res['pw_page_id']] = array($res);
      } else {  // add a new match to the page's result array
        $ret[$res['pw_page_id']][] = $res;
      }
    }

    $this->module->log->save('solr', 'SOLR parsed search result: ' . var_export($ret, true));

    return $ret;
  }
}
