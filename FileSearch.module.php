<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * FileSearch module
 * 
 * Provides indexing and search for documents uploaded to filefields.
 * 
 * Copyright 2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class FileSearch extends WireData implements Module {
  private $redirectUrl = ''; // used for temporaly redirect on reindex all
  private $engine = false; // search engine object or false if none loaded

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   * 
   */
  public function ___install() {
  }


  /**
   * Called only when this module is uninstalled
   * 
   */
  public function ___uninstall() {
  }


  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
    $this->assetsURL = $this->config->urls->siteModules . 'FileSearch/assets/';
    // check config
    if (!$this->file_field || !$this->pageindex_field
        || !$this->pdfseparate || !$this->pdftotext ) {
      $this->error('The module configuration is invalid.');
      return;
    }

    if (!is_executable($this->pdftotext) || !is_executable($this->pdftotext)) {
      $this->error("{$this->pdftotext} executable is missing.");
      return;
    }

    if (!is_executable($this->pdfseparate) || !is_executable($this->pdfseparate)) {
      $this->error("{$this->pdfseparate} executable is missing.");
      return;
    }

    if (!file_exists(dirname(__FILE__).'/engines/'.$this->search_engine.'.php')) {
      $this->error("ERROR: search engine '{$this->search_engine}' is not supported.");
      return false;
    }

    // Perform actions selected on the module's setting page
    if ($this->action) {
      switch ($this->action) {
        case 'indexmissing':
        case 'indexall':
          $this->indexAll();
          break;
        default:
          $this->message('ERROR: invalid action specified: '.$this->action);
      }
      // clear the action
      wire('modules')->saveConfig('FileSearch', 'action', '');
    }

    // TODO disabled atm
    if (false && $this->indexmissing && $this->indexAll()) {
      // clear the index all checkbox if indexAll() is successful
      wire('modules')->saveConfig('FileSearch', 'indexmissing', 0);
    }

    // Installing hooks
    
    // Conditional hook to detect file changes
    // Note: PW < 3.0.62 has a bug and needs manual fix for conditional hooks:
    // https://github.com/processwire/processwire-issues/issues/261
    // hook after page save to process changes of file fields
    // $this->addHookAfter('Page::changed('.$this->file_field.')', $this, 'handleFileChange');

    // TODO use PW selectors to perform queries
    // Hook to alter selectors before PW executes the query
    // $this->addHookBefore('PageFinder::getQuery', $this, 'handleSelectors');
  }



/***********************************************************************
 * HOOKS
 **********************************************************************/

  /**
   * Hook that creates a task to process the sources
   * Note: it is called several times when the change occurs.
   */
  public function handleFileChange(HookEvent $event) {
    // return when we could not detect a real change
    if (! $event->arguments(1) instanceOf Pagefiles) return;
    $pFile = $event->arguments(1);
    $fPage = $event->object;

    $this->message('File has changed on "'.$fPage->title.'"', Notice::debug);

    // create the text index now on the given page
    // TODO remove OLD search index?
    // $this->removeIndex($fPage, $pFile);
    $this->indexFiles($fPage, $pFile);
  }


  /**
   * Hook that creates a task to process the sources
   * Note: it is called several times when the change occurs.
   */
  public function handleSelectors(HookEvent $event) {
    $selectors = $event->arguments(0);
    // TODO remove file text search selectors
    $event->arguments(0, $selectors);
  }



/***********************************************************************
 * INDEXING
 **********************************************************************/

  /**
   * Index files on pages.
   * 
   * @param $fPage ProcessWire Page ID or object or array of these
   * @param $pFile file to process (if not present then process all files)
   * @returns false on error, true on success
   */
  public function indexFiles($pages, $pFile = NULL) {
    if (is_array($pages)) {
      foreach ($pages as $p) {
        if (!$this->indexFiles($p)) return false;
      }
      return true;
    }

    if (is_numeric($pages)) {
      $fPage = $this->pages->get($pages);
    } else {
      $fPage = $pages;
    }

    if (!$fPage instanceof Page) {
      $this->error('ERROR: Invalid page specified.');
      return false;
    }

    if ($pFile==NULL) {
      foreach ($fPage->{$this->file_field} as $pFile) {
        if (!$this->indexFiles($fPage, $pFile)) return false;
      }
      return true;
    }

    $this->message("Processing file {$pFile->filename}.", Notice::debug);
    $this->log->save('search', "Processing file {$pFile->filename}.");
    if (!$this->engine) {
      require_once dirname(__FILE__).'/engines/'.$this->search_engine.'.php';
      $this->engine = new $this->search_engine($this);
    }

// TODO This does not work well with page level indexing
    if ($this->engine->isIndexed($fPage, $pFile)) {
      $this->message("{$pFile->name} is already indexed. Skipping...");
      return true;
    }

    // if page index is not requested, index the entire document
    if (!$this->indexpages) {
      $options = array('fields' => array('pw_author_id' => $fPage->author_ref->id));
      return $this->engine->index($fPage, $pFile, $options);
    }

    // otherwise index each page separately

    // if the file is small or Tasker is not availbale, we try do it now
    if ($pFile->filesize() < 8388608 || !$this->modules->isInstalled('Tasker')) {
      $taskData = array('filename' => $pFile->filename);
      return $this->indexFilePages($fPage, $taskData);
    }

    $tasker = $this->modules->get('Tasker');

    // Create the task
    $taskData = array('filename' => $pFile->filename);
    $indexTask = $tasker->createTask(__CLASS__, 'indexFilePages', $fPage, 'Indexing pages in file '.$pFile->name, $taskData);
    if ($indexTask == NULL) return false; // tasker failed to add a task
    $tasker->activateTask($indexTask);
    return true;
  }
    

  /**
   * Index all page of a file separately.
   * 
   * @param $fPage ProcessWire Page object
   * @param $pFile file to process (if not present then process all files)
   * @returns false on error, true on success
   */
  public function indexFilePages($fPage, &$taskData, $params = array()) {
    $newItems = array();
    $workDir = $this->config->paths->tmp.'index_'.$fPage->id.'/';
    mkdir($workDir, 0755, true);
    
    $filename = $taskData['filename'];
    $basename = basename($filename);

    // extract PDF pages
    $command = $this->pdfseparate . ' ' . $filename . ' ' . $workDir . 'page-%d.pdf 2>&1';
    $this->message("Extracting PDF pages using {$command}.", Notice::debug);
    exec($command, $exec_output, $exec_status);
    if ($exec_status != 0) {
      $this->error("ERROR: Could not extract pages from '{$basename}'.");
      foreach ($exec_output as $exec_line) $this->error($exec_line);
      exec('/bin/rm -rf '.$workDir);
      return false;
    }
    unset($exec_output);

    if (!$this->engine) {
      require_once dirname(__FILE__).'/engines/'.$this->search_engine.'.php';
      $this->engine = new $this->search_engine($this);
    }

    if (!isset($fPage->author_ref) || !isset($fPage->author_ref->id)) {
      $this->error("ERROR: Missing author_ref @ document '{$fPage->title}'[{$fPage->id}].");
      return false;
    }
    $options = array(
        'fields' => array('pw_author_id' => $fPage->author_ref->id),
        'filename_prefix' => $basename.'_'
      );

    $pagefiles = scandir($workDir);
    // sort the files using page numbers
    natsort($pagefiles);
    foreach($pagefiles as $pagefile) {
      if ($pagefile == '.' || $pagefile == '..') continue;
      list ($pageNum) = sscanf($pagefile, "page-%d.pdf");
      $this->message("Indexing page {$pageNum} of file {$basename}.", Notice::debug);
      $options['fields']['page_num'] = $pageNum;
      if (!$this->engine->index($fPage, $workDir.$pagefile, $options)) {
        exec('/bin/rm -rf '.$workDir);
        return false;
      }
    }
    exec('/bin/rm -rf '.$workDir);
    $taskData['task_done'] = 1;
    return true;
  }

  /**
   * Index all files
   *
   * @returns false on error, true if all files have been (re)indexed
   */
  public function indexAll() {
    $fPages = $this->pages->find($this->file_field.'.count>0');
    $numPages = $fPages->count();

    // perform the indexing now if we have only a few pages or Tasker is not available
    if ($numPages < 3 || !$this->modules->isInstalled('Tasker')) { // TODO configurable limit
      return $this->indexFiles($fPages);
    }

    $tasker = $this->modules->get('Tasker');

    $this->message("Found {$numPages} page(s) to index. Using Tasker to perform the job.");

    // Create the task
    $taskData = array('reindex' => $this->action);  // store the requested action (reindex or indexmissing)
    $taskData['pageIDs'] = $this->pages->findIDs($this->file_field.'.count>0');
    $taskData['max_records'] = count($taskData['pageIDs']);
    $indexTask = $tasker->createTask(__CLASS__, 'indexAllTask', $this->pages->get('/'), 'Reindexing all files', $taskData);
    if ($indexTask == NULL) return false; // tasker failed to create the task

    $tasker->activateTask($indexTask);

    // if TaskedAdmin is installed and autostart is enabled, redirect to its admin page for immediate task execution
    if ($this->modules->isInstalled('TaskerAdmin')
        && $this->modules->get('TaskerAdmin')->autoStart) {
      $this->redirectUrl = $this->modules->get('TaskerAdmin')->adminUrl.'?id='.$indexTask->id.'&cmd=run';
      // add a temporary hook to redirect to TaskerAdmin's monitoring page after saving the current page
      $this->pages->addHookBefore('ProcessPageEdit::processSaveRedirect', $this, 'runTasks');
    }
    return true;
  }

  /**
   * Index all files
   *
   * @param $page PW page object (not used, only for Tasker compatibility)
   * @param $taskData task data
   * @param $params runtime params
   * @returns false on error
   */
  public function indexAllTask($page, &$taskData = array(), $params = NULL) {
    $tasker = $this->modules->get('Tasker');
    $task = $params['task'];

    // the starting point
    $offset = $taskData['records_processed'];

    // milestone step size
    if ($this->indexpages) $mileStep = 1;
    else $mileStep = 3;

    // set the next milestone
    $taskData['milestone'] = $taskData['records_processed'] + $mileStep;

    $this->log->save('search', "Running indexing task {$task->title} from offset {$offset} with timelimit {$params['timeout']}.");
    $this->message('Indexing '.count($taskData['pageIDs']).' pages starting at offset '.$offset);

    $taskData['task_done'] = 0;

    foreach ($taskData['pageIDs'] as $pageID) {
      if ($offset) {   // skip already processed records
        $offset--;  // needs to be here to be executed
        continue;
      }

      if (!$tasker->allowedToExecute($task, $params)) {
        break; // the foreach loop
      }

      if (!$this->indexFiles($pageID)) {
        // TODO stop the task?
        // return false;
      }

      $taskData['records_processed']++;

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData)) {
        // set the next milestone
        $taskData['milestone'] = $taskData['records_processed'] + $mileStep;
      }

    }

    if ($taskData['records_processed'] == $taskData['max_records']) {
      $taskData['task_done'] = 1;
    }
    return true;
  }



/***********************************************************************
 * SEARCH FUNCTIONS
 **********************************************************************/

  /**
   * Search files on selectable pages using a text selector
   * 
   * @param $pageSelector - PW selector to find Pages
   * @param $textSelector - a selector operator and a selector value to match pdf_pageindex_field repeater items
   * @return false on error, or array of (PageID => search result array)
   */
  public function findFiles($pageSelector, $textSelector, $options = array()) {
    $this->log->save('search', "File search Pages={$pageSelector} Text={$textSelector}");
    $this->message("Searching for '{$textSelector}' on pages selected by '{$pageSelector}'.", Notice::debug);
    if ($textSelector == '') {
      // TODO this is not really gooooood
      if (isset($options['start'])) $start = $options['start']; else $start=0;
      if (isset($options['rows'])) $limit = $options['rows']; else $limit=10;
      $numFound = $this->pages->count($pageSelector);
      $result = array_flip($this->pages->findIDs($pageSelector.", start={$start}, limit={$limit}"));
      $explain = array('response' => array('numFound' => $numFound));
      $result[0] = $explain;
      return $result;
    }

    // assemble the query parameters
    $query = array('text' => $textSelector);

    $pageIDs = $this->pages->findIDs($pageSelector);
    if (!count($pageIDs)) return false;

    // if there are too many pages filter by pages after the text search to avoid too long urls
    if (count($pageIDs)<10) $query['filter'] = 'pw_page_id:'.implode(' OR pw_page_id:', $pageIDs);

    if (count($options)) foreach ($options as $key => $option) $query[$key] = $option;

    if (!isset($query['fields'])) $query['fields'] = 'name,id,pw_page_id';

    $this->log->save('search', 'Query: ' . var_export($query, true));

    if (!$this->engine) {
      require_once dirname(__FILE__).'/engines/'.$this->search_engine.'.php';
      $this->engine = new $this->search_engine($this);
    }

    $result = $this->engine->query($query);
    if (!$result) return false;

    $pageIDs[] = 0; // this is a special index for storing the original result object
    $result = array_filter(
      $result,
      function ($key) use ($pageIDs) { return in_array($key, $pageIDs); },
      ARRAY_FILTER_USE_KEY
    );

    $this->log->save('search', 'Result: ' . var_export($result, true));

    return $result;
  }


}
