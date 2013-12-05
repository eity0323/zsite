<?php
$config->book->imgMaxWidth = 950;

$config->book->createBook = new stdclass();
$config->book->createBook->requiredFields = 'title, alias';

$config->book->create = new stdclass();
$config->book->create->requiredFields = 'title';

$config->book->edit = new stdclass();
$config->book->edit->requiredFields = 'title';

$config->book->editor = new stdclass();
$config->book->editor->edit   = array('id' => 'content', 'tools' => 'simpleTools');
