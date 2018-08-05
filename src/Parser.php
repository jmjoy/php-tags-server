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

    public function addFile($file) {
        $tokens = token_get_all(file_get_contents($file));
        var_dump($tokens);
    }

    public function rmFile() {
        
    }

}
