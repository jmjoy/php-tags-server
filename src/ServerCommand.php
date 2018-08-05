<?php

namespace TagsServer;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Swoole\Coroutine as Co;
use Swoole\Table;
use Swoole\Lock;

class ServerCommand extends Command {

    protected $dir;

    protected $output;

    protected $fileQueue;

    protected $outputLock;

    protected $notify;

    protected function configure() {
        $this->setName('server:run')
            ->setDescription('Run the tags server.')
            ->addArgument('dir', InputArgument::REQUIRED, 'The base directory of source codes.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, "Host of server.", "127.0.0.1")
            ->addOption('port', null, InputOption::VALUE_REQUIRED, "Port of server.", "65000");
    }

    protected function initialize(InputInterface $input, OutputInterface $output) {
        $this->input = $input;
        $this->output = $output;

        $this->dir = $input->getArgument('dir');
        if (!is_dir($this->dir)) {
            throw new InvalidArgumentException('<dir> isn\'t a directory.');
        }
        $this->dir = realpath($this->dir);
        chdir($this->dir);

        $this->fileQueue = new Co\Channel(1024 * 1024);
        $this->outputLock = new Lock(SWOOLE_MUTEX);

        $this->notify = new Notify();
        $this->notify->addWatch($this->dir);
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->startServer($input->getOption('host'), $input->getOption('port'));
    }

    protected function startServer($host, $port) {
        $http = new \Swoole\Http\Server($host, $port);
        $http->on('workerStart', function () {
            $this->handleFile();
            $this->pushAllFiles();
            $this->notifyFiles();
        });
        $http->on('request', function ($request, $response) {
            $response->end("<h1>Hello Swoole. #".rand(1000, 9999)."</h1>");
        });
        $http->start();
    }

    protected function pushAllFiles() {
        Co::create(function() {
            $iterator = new \RecursiveDirectoryIterator($this->dir);
            $iterator = new \RecursiveIteratorIterator($iterator);
            $iterator = new \RegexIterator($iterator, '/^.+\.php$/i', \RegexIterator::GET_MATCH);
            foreach ($iterator as $match) {
                $this->writeln($match);
                if (is_file($match[0])) {
                    $file = realpath($match[0]);
                    $this->fileQueue->push(['ADD', $file]);
                }
            }
        });
    }

    protected function notifyFiles() {
        Co::create(function() {
            while (true) {
                Co::sleep(0.1);

                $events = $this->notify->read();
                if (!$events) {
                    continue;
                }

                foreach ($events as $event) {
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
        });
    }

    protected function handleFile() {
        Co::create(function() {
            while (true) {
                list($op, $file) = $this->fileQueue->pop();
                if (!$op || !$file) {
                    continue;
                }

                switch ($op) {
                    case 'MKDIR':
                        $this->notify->addWatch($file);
                        break;
                    case 'RMDIR':
                        $this->notify->rmWatch($file);
                        break;
                    default:
                }

                $this->writeln($op . " " . $file);
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

}
