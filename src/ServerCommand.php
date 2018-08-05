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

    protected const NOTIFY_MASK = IN_MODIFY | IN_MOVED_TO | IN_MOVED_FROM | IN_CREATE | IN_DELETE;

    protected $dir;

    protected $output;

    protected $tagTable;

    protected $fileQueue;

    protected $outputLock;

    protected $notify;

    protected $notifyLock;

    protected $notifyTable;

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
            // $this->pushAllFiles();
            $this->notifyFiles();
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
        $this->dir = realpath($this->dir);
        chdir($this->dir);

        $this->tagTable = new \swoole_table(1024 * 1024);
        $this->fileQueue = new Co\Channel(1024 * 1024);
        $this->outputLock = new \swoole_lock(\SWOOLE_MUTEX);
        $this->notifyLock = new \swoole_lock(\SWOOLE_MUTEX);

        $this->notify = inotify_init();
        inotify_add_watch($this->notify, $this->dir, static::NOTIFY_MASK);
    }

    protected function pushAllFiles() {
        Co::create(function() {
            $iterator = new \RecursiveDirectoryIterator($this->dir);
            $iterator = new \RecursiveIteratorIterator($iterator);
            $iterator = new \RegexIterator($iterator, '/^.+\.php$/i', \RegexIterator::GET_MATCH);
            foreach ($iterator as $match) {
                if (is_file($match[0])) {
                    $file = $this->expandFilePath($match[0]);
                    $this->fileQueue->push(['ADD', $file]);
                }
            }
        });
    }

    protected function notifyFiles() {
        Co::create(function() {
            while (true) {
                $this->notifyLock->lock();
                $events = inotify_read($this->notify);
                $this->notifyLock->unlock();

                if ($events) {
                    foreach ($events as $event) {
                        $this->writeln("inotify Event :".var_export($event, 1)."\n");
                        if (empty($event['mask']) || empty($event['name'])) {
                            continue;
                        }

                        $op = null;
                        switch ($event['mask']) {
                            case IN_CREATE | IN_ISDIR:
                            case IN_MOVED_TO | IN_ISDIR:
                                $op = "MKDIR";
                                break;
                            case IN_DELETE | IN_ISDIR:
                            case IN_MOVED_FROM | IN_ISDIR:
                                $op = "RMDIR";
                                break;
                            case IN_MODIFY:
                                $op = "MOD";
                                break;
                            case IN_MOVED_TO:
                            case IN_CREATE:
                                $op = "ADD";
                                break;
                            case IN_MOVED_FROM:
                            case IN_DELETE:
                                $op = "DEL";
                                break;
                            default:
                                continue;
                        }

                        $this->fileQueue->push([$op, $this->expandFilePath($event['name'])]);
                    }
                }
            }
        });
    }

    protected function handleFile() {
        Co::create(function() {
            while (true) {
                list($op, $file) = $this->fileQueue->pop();
                if ($op && $file) {
                    switch ($op) {
                        case 'MKDIR':
                            inotify_add_watch($this->notify, $file, static::NOTIFY_MASK);
                            break;
                        case 'RMDIR':
                            // TODO set descriptioner
                            inotify_rm_watch($this->notify, $file);
                            break;
                        default:
                    }
                    $this->writeln($op . " " . $file);
                }
            }
        });
    }

    protected function writeln($messages, $options = 0) {
        $this->outputLock->lock();
        $this->output->writeln($messages, $options);
        $this->outputLock->unlock();
    }

    protected function expandFilePath($filename) {
        return $this->dir . DIRECTORY_SEPARATOR . $filename;
    }

    // protected function inotifyAddWatch($dir) {
    //     $this->notifyTable->push($dir, $);
    // }

}
