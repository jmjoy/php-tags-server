<?php

namespace TagsServer;

use Swoole\Table;
use Swoole\Lock;

class Parser {

    protected $tagTable;

    protected $fileTable;

    public function __construct() {
        $this->tagTable = new Table(1024 * 1024);
        $this->file = new Table(1024 * 1024);
    }

    public function addFile() {
        
    }

    public function rmFile() {
        
    }

}
