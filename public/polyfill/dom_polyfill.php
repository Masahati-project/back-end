<?php
class _AI extends ArrayIterator { public function __construct() { parent::__construct([]); } };

class DOMDocument {
    public $documentElement;
    public function __construct() { $this->documentElement = null; }
    public function loadHTML($s, $e = null) { return true; }
    public function saveHTML() { return '<html></html>'; }
    public function getElementsByTagName($t) { return new _AI; }
}
