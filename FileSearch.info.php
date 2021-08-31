<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * FileSearch module information
 * 
 * Provides embedded rendering, indexing and search for PDF documentds.
 * 
 * Copyright 2019 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */

$info = array(
  'title' => 'FileSearch',
  'version' => '0.1.1',
  'summary' => 'The module provides functions to index and search files uploaded to filefields.',
  'href' => 'https://github.com/mtwebit/FileSearch',
  'singular' => true, // contains hooks
  'autoload' => true, // attaches to hooks
  'icon' => 'file-pdf', // fontawesome icon
);
