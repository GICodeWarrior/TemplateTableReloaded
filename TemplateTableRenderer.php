<?php
/**
 * TemplateTableRenderer
 *
 * Copyright 2006 CS "Kainaw" Wagner
 * Copyright 2015 Rusty Burchfield
 *
 * Licensed under GPLv2 or later (see COPYING)
 */

class TemplateTableRenderer {
  private $templateTitle;
  private $redirects;

  private $headers;
  private $dynamicHeaders;
  private $categories;
  private $excludeCategories;
  private $rowLimit;
  private $caption;
  private $hideArticle;
  private $headerFormatter;
  private $cellFormatter;
  private $attributes;

  private $parserOptions;

  private $pages;
  private $templateData;

  public static function execute($input, $args, $parserOptions) {
    global $wgTemplateTableParseDepth;

    if ($wgTemplateTableParseDepth != 0) {
      return '<!-- Skipping ttable expansion to prevent recursion. -->';
    }
    $wgTemplateTableParseDepth += 1;

    $renderer = new TemplateTableRenderer();
    $errors = $renderer->parseArgs($input, $args, $parserOptions);

    if (!empty($errors)) {
      $output = '<div style="color: red;">ttable error(s):' . "\n";
      foreach ($errors as $error) {
        $output .= '* ' . $error . "\n";
      }
      $output .= '</div>';

      $wgTemplateTableParseDepth -= 1;
      return $output;
    }

    $renderer->fetchPages();
    $renderer->parsePages();
    $result = $renderer->render();

    $wgTemplateTableParseDepth -= 1;
    return $result;
  }

  private function parseArgs($input, $args, $parserOptions) {
    global $wgTemplateTableDefaultRowLimit, $wgTemplateTableMaxRowLimit,
      $wgTemplateTableDefaultClasses;

    $errors = array();

    $templateName = trim($input);
    if (empty($templateName)) {
      $errors[] = 'No template specified.';
    } else {
      $this->templateTitle = Title::newFromText($templateName, NS_TEMPLATE);

      if (is_null($this->templateTitle)) {
        $errors[] = 'Template "' . $templateName . '" not a valid title.';
      } elseif (!$this->templateTitle->inNamespace(NS_TEMPLATE)) {
        $errors[] = 'Template "' . $templateName . '" not in Template namespace.';
      } else {
        $dbr = wfGetDB(DB_SLAVE);
        $redirects = $dbr->select(
          array('page', 'redirect'),
          array('page_title', 'page_namespace'),
          array(
            'rd_namespace' => NS_TEMPLATE,
            'rd_title' => $this->templateTitle->getDBkey()
          ),
          __METHOD__,
          array(),
          array('redirect' => array('INNER JOIN', array('page_id=rd_from')))
        );

        $this->redirects = array();
        foreach ($redirects as $redirect) {
          $this->redirects[] = Title::makeTitle(
            $redirect->page_namespace,
            $redirect->page_title
          );
        }
      }
    }

    $this->headers = array();
    $this->dynamicHeaders = true;
    if (isset($args['headers'])) {
      $this->dynamicHeaders = false;
      if (trim($args['headers'])) {
        foreach (explode('|', $args['headers']) as $header) {
          $this->headers[] = trim($header);
        }
      }
    }

    $this->rowLimit = $wgTemplateTableDefaultRowLimit;
    if (isset($args['limit'])) {
      $this->rowLimit = $wgTemplateTableMaxRowLimit;
      $limit = intval($args['limit']);
      if ($limit < $wgTemplateTableMaxRowLimit) {
        $this->rowLimit = $limit;
      }
    }

    $this->categories = array();
    $this->excludeCategories = array();
    if (isset($args['categories']) && trim($args['categories'])) {
      $categories = explode('|', $args['categories']);

      foreach ($categories as $category) {
        $category = trim($category);
        $exclude = substr($category, 0, 1) == '!';
        if ($exclude) {
          $category = substr($category, 1);
        }
        $title = Title::newFromText(trim($category), NS_CATEGORY);

        if (is_null($title)) {
          $errors[] = 'Category "' . $category . '" not a valid title.';
        } elseif (!$title->inNamespace(NS_CATEGORY)) {
          $errors[] = 'Category "' . $category . '" not in Category namespace.';
        } else {
          if ($exclude) {
            $this->excludeCategories[] = $title->getDBkey();
          } else {
            $this->categories[] = $title->getDBkey();
          }
        }
      }
    }

    $this->caption = isset($args['caption']) ? $args['caption'] : '';
    $this->hideArticle = isset($args['hidearticle']);

    if ($this->hideArticle && !$this->dynamicHeaders && empty($this->headers)) {
      $errors[] = 'All columns hidden (eg. hidearticle and headers="").';
    }

    $this->headerFormatter = null;
    if (isset($args['headerformatter'])) {
      $headerTemplate = trim($args['headerformatter']);
      $this->headerFormatter = Title::newFromText($headerTemplate, NS_TEMPLATE);
      if (is_null($this->headerFormatter)) {
        $errors[] = 'Header formatter "' . $headerTemplate . '" not a valid title.';
      } elseif (!$this->headerFormatter->inNamespace(NS_TEMPLATE)) {
        $errors[] = 'Header formatter "' . $headerTemplate . '" not in Template namespace.';
      }
    }

    $this->cellFormatter = null;
    if (isset($args['cellformatter'])) {
      $cellTemplate = trim($args['cellformatter']);
      $this->cellFormatter = Title::newFromText($cellTemplate, NS_TEMPLATE);
      if (is_null($this->cellFormatter)) {
        $errors[] = 'Cell formatter "' . $cellTemplate . '" not a valid title.';
      } elseif (!$this->cellFormatter->inNamespace(NS_TEMPLATE)) {
        $errors[] = 'Cell formatter "' . $cellTemplate . '" not in Template namespace.';
      }
    }

    $this->attributes = Sanitizer::validateTagAttributes($args, 'table');
    if (!isset($this->attributes['class'])) {
      $this->attributes['class'] = $wgTemplateTableDefaultClasses;
    }
    $this->attributes['class'] .= ' ttable';

    $this->parserOptions = $parserOptions;

    return $errors;
  }

  private function fetchPages() {
    $tables = array('templatelinks', 'page', 'revision', 'text');
    $fields = array('page_title', 'page_namespace', 'old_text');
    $filter = array(
      'tl_namespace' => NS_TEMPLATE,
      'tl_title' => $this->templateTitle->getDBkey()
    );
    $options = array(
      'LIMIT' => $this->rowLimit,
      'ORDER BY' => 'page_title'
    );
    $joins = array(
      'page' => array('INNER JOIN', array('tl_from=page_id')),
      'revision' => array('INNER JOIN', array('rev_id=page_latest')),
      'text' => array('INNER JOIN', array('old_id=rev_text_id'))
    );

    $categoryID = 1;
    foreach ($this->categories as $category) {
      $table = "cl$categoryID";
      $tables[$table] = 'categorylinks';
      $filter[$table . '.cl_to'] = $category;
      $joins[$table] = array('INNER JOIN', array($table . '.cl_from=page_id'));
      $categoryID += 1;
    }

    foreach ($this->excludeCategories as $category) {
      $table = "cl$categoryID";
      $tables[$table] = 'categorylinks';
      $filter[$table . '.cl_to'] = null;
      $joins[$table] = array(
        'LEFT JOIN',
        array($table . '.cl_from=page_id', $table . '.cl_to' => $category)
      );
      $categoryID += 1;
    }

    $dbr = wfGetDB(DB_SLAVE);
    $this->pages = $dbr->select(
      $tables,
      $fields,
      $filter,
      __METHOD__,
      $options,
      $joins
    );
  }

  private function parsePages() {
    $templateParser = new TemplateTableParser();
    $this->templateData = array();

    foreach ($this->pages as $page) {
      $pageTitle = Title::makeTitle($page->page_namespace, $page->page_title);

      $templateParser->clearCallData();
      $templateParser->preprocess($page->old_text, $pageTitle, $this->parserOptions);
      $callData = $templateParser->getCallData();

      foreach ($callData as $call) {
        $localFrame = $call['frame'];
        $pieceName = trim($localFrame->expand($call['piece']['title']));
        $pieceTitle = Title::newFromText($pieceName, NS_TEMPLATE);

        if (is_null($pieceTitle)) {
          continue;
        }
        if (!$pieceTitle->equals($this->templateTitle)) {
          $isRedirect = false;
          foreach ($this->redirects as $redirect) {
            if ($pieceTitle->equals($redirect)) {
              $isRedirect = true;
              break;
            }
          }
          if (!$isRedirect) {
            continue;
          }
        }

        $item = array();
        $parts = $call['piece']['parts'];
        $partsLength = $parts->getLength();
        for ($i = 0; $i < $partsLength; $i++) {
          $bits = $parts->item($i)->splitArg();
          $name = strval($bits['index']);
          if ($name === '') {
            $name = trim($localFrame->expand($bits['name'], PPFrame::STRIP_COMMENTS));
          }

          $value = trim($localFrame->expand($bits['value']));
          $item[$name] = $value;

          if ($this->dynamicHeaders && !in_array($name, $this->headers)) {
            $this->headers[] = $name;
          }
        }

        $this->templateData[] = array('title' => $pageTitle, 'item' => $item);
      }
    }
  }

  private function render() {
    $output = '';

    if (!empty($this->templateData) && (!empty($this->headers) || !$this->hideArticle)) {
      $output .= '<table';
      foreach ($this->attributes as $name => $value) {
        $output .= ' ' . $name . '="' . $value . '"';
      }
      $output .= '>' . "\n";

      if (!empty($this->caption)) {
        $output .= '<caption>' . $this->caption . '</caption>' . "\n";
      }

      $output .= '<tr>';

      if (!$this->hideArticle) {
        $output .= '<th>';
        $output .= $this->format($this->headerFormatter, 'Article');
        $output .= '</th>' . "\n";
      }

      foreach ($this->headers as $header) {
        $output .= '<th>';
        $output .= $this->format($this->headerFormatter, $header);
        $output .= '</th>' . "\n";
      }

      $output .= "</tr>\n";

      foreach ($this->templateData as $row) {
        $title = $row['title'];
        $item = $row['item'];

        $output .= '<tr>';

        if (!$this->hideArticle) {
          $output .= '<td>';
          $articleLink = '[[' . $title->getFullText() . '|';
          $articleLink .= $title->getText() . ']]';
          $output .= $this->format($this->cellFormatter, 'Article', $articleLink);
          $output .= '</td>' . "\n";
        }

        foreach ($this->headers as $header) {
          $cellValue = '';
          if (isset($item[$header])) {
            $cellValue = $item[$header];
          }
          $output .= '<td>';
          $output .= $this->format($this->cellFormatter, $header, $cellValue);
          $output .= '</td>' . "\n";
        }
        $output .= "</tr>\n";
      }
      $output .= '</table>';

      if (count($this->templateData) == $this->rowLimit) {
        $output .= "\n" . '<small>Table output limited to ' . $this->rowLimit;
        $output .= ' results.</small>' . "\n";
      }
    } else {
      $output .= 'Template "' . $this->templateTitle->getText() . '" has no data.';
    }

    return $output;
  }

  private function format($title, $name, $value=null) {
    if (is_null($title)) {
      if (is_null($value)) {
        $result = $name;
      } else {
        $result = $value;
      }
    } else {
      if (is_null($value)) {
        $result = '{{' . $title->getFullText() . '|' . $name . '}}';
      } else {
        $result = '{{' . $title->getFullText() . '|' . $name . '|2=' . $value . '}}';
      }
    }

    $result = trim($result);
    if (empty($result)) {
      $result = '&nbsp;';
    }

    return $result;
  }
}
