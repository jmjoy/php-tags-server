<?php

namespace TagsServer;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Swoole\Coroutine as Co;

class ServerCommand extends Command {

    protected $dir;

    protected $output;

    protected $tagTable;

    protected $fileQueue;

    protected $outputLock;

    protected function configure() {
        $this->setName('server:run')
            ->setDescription('Run the tags server.')
            ->addArgument('dir', InputArgument::REQUIRED, 'The base directory of source codes.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, "Host of server.", "127.0.0.1")
            ->addOption('port', null, InputOption::VALUE_REQUIRED, "Port of server.", "65000");
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->init($input, $output);
        $this->startServer($input->getOption('host'), $input->getOption('port'));
    }

    protected function startServer($host, $port) {
        $http = new \swoole_http_server($host, $port);
        $http->on('workerStart', function () {
            $this->handleFile();
            $this->pushAllFiles();
            // $this->notifyFiles();
        });
        $http->on('request', function ($request, $response) {
            $response->end("<h1>Hello Swoole. #".rand(1000, 9999)."</h1>");
        });
        $http->start();
    }

    protected function init(InputInterface $input, OutputInterface $output) {
        $this->input = $input;
        $this->output = $output;

        $this->dir = $input->getArgument('dir');
        if (!is_dir($this->dir)) {
            throw new InvalidArgumentException('<dir> isn\'t a directory.');
        }
        chdir($this->dir);

        $this->tagTable = new \swoole_table(1024 * 1024);
        $this->fileQueue = new Co\Channel(1024 * 1024);
        $this->outputLock = new \swoole_lock(\SWOOLE_MUTEX);
    }

    protected function pushAllFiles() {
        Co::create(function() {
            $iterator = new \RecursiveDirectoryIterator($this->dir);
            $iterator = new \RecursiveIteratorIterator($iterator);
            $iterator = new \RegexIterator($iterator, '/^.+\.php$/i', \RegexIterator::GET_MATCH);
            foreach ($iterator as $match) {
                if (is_file($match[0])) {
                    $file = realpath($match[0]);
                    $this->fileQueue->push(['ADD', $file]);
                }
            }
        });
    }

    protected function notifyFiles() {
        //创建一个inotify句柄
        $notify = inotify_init();

        //监听文件，仅监听修改操作，如果想要监听所有事件可以使用IN_ALL_EVENTS
        // inotify_add_watch($notify, $this->dir, IN_MODIFY | IN_MOVED_TO | IN_MOVED_FROM | IN_CREATE | IN_DELETE);
        inotify_add_watch($notify, $this->dir, IN_ALL_EVENTS);

        swoole_event_add($notify, function($notify) {
            $events = inotify_read($notify);
            if ($events) {
                foreach ($events as $event) {
                    $this->fileQueue->push(['ADD', 'TEST']);
                    $this->writeln("inotify Event :".var_export($event, 1)."\n");
                }
            }
        });
    }

    protected function handleFile() {
        Co::create(function() {
            while (true) {
                list($op, $file) = $this->fileQueue->pop();
                $this->writeln($op . " " . $file);
            }
        });
    }

    protected function writeln($messages, $options = 0) {
        $this->outputLock->lock();
        $this->output->writeln($messages, $options);
        $this->outputLock->unlock();
    }

}

