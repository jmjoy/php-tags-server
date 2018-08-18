<?php

namespace TagsServer;

use Swoole\Table;
use Swoole\Lock;

class Notify {

    protected const MASK = IN_MODIFY | IN_MOVED_TO | IN_MOVED_FROM | IN_CREATE | IN_DELETE;

    protected $notify;

    protected $readLock;

    protected $watchTable;

    protected $watchReverseTable;

    public function __construct() {
        $this->notify = inotify_init();
        $this->readLock = new Lock(SWOOLE_MUTEX);

        $this->watchTable = new Table(1024 * 1024);
        $this->watchTable->column('id', Table::TYPE_INT, 8);
        $this->watchTable->create();

        $this->watchReverseTable = new Table(1024 * 1024);
        $this->watchReverseTable->column('dir', Table::TYPE_STRING, 256);
        $this->watchReverseTable->create();
    }

    public function addWatch($dir, $mask = self::MASK) {
        $this->walkRecursiveDirs($dir, function($dir) use ($mask) {
            $id = inotify_add_watch($this->notify, $dir, $mask);
            $this->watchTable->set($dir, ['id' => $id]);
            $this->watchReverseTable->set($id, ['dir' => $dir]);
        });
    }

    public function rmWatch($dir, $mask = self::MASK) {
        $id =  $this->watchTable->get($dir, 'id');
        if ($id === false) {
            throw new NotifyWatchNotFoundException();
        }
        $this->watchTable->del($dir);
        $this->watchReverseTable->del($id);

        // TODO Unkonw why not work.
        // inotify_rm_watch($this->notify, $id);
    }

    public function read() {
        $this->readLock->lock();
        $events = inotify_read($this->notify);
        $this->readLock->unlock();

        $events = array_map(function($event) {
            $dir = $this->watchReverseTable->get($event['wd'], 'dir');
            $event['name'] = $dir . DIRECTORY_SEPARATOR . $event['name'];
            return $event;
        }, $events);

        return $events;
    }

    protected function walkRecursiveDirs($dir, $handleDir) {
        if (!is_dir($dir)) {
            return;
        }
        $handleDir($dir);
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $filepath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($filepath)) {
                $this->walkRecursiveDirs($filepath, $handleDir);
            }
        }
    }

}
