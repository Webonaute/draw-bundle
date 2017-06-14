<?php

namespace Draw\DrawBundle\Test;

use Monolog\Handler\TestHandler;

class LogHelper
{
    /**
     * @var RequestHelper
     */
    public $requestHelper;

    public $level;

    public $message;

    public $count;

    public $channel;

    public $contextAsserts = [];

    public function __construct(RequestHelper $requestHelper, $message)
    {
        $this->requestHelper = $requestHelper;
        $this->message = $message;
    }

    /**
     * @param $propertyPath
     * @return LogContextHelper
     */
    public function contextHelper($propertyPath)
    {
        return new LogContextHelper($this, $propertyPath);
    }

    public function channel($channel)
    {
        $this->channel = $channel;

        return $this;
    }

    public function level($level)
    {
        $this->level = $level;

        return $this;
    }

    public function count($count)
    {
        $this->count = $count;

        return $this;
    }

    public function attach()
    {
        $this->requestHelper->asserting(array($this, 'assert'));

        return $this->requestHelper;
    }

    public function assert()
    {
        $logger = $this->getDebugLogger();

        $records = $logger->getRecords();

        $records = array_filter($records, function($record) {
           return $record['message'] == $this->message;
        });

        $testCase = $this->requestHelper->testCase;

        if(!is_null($this->count)) {
            $testCase->assertCount($this->count, $records);
        } else {
            $testCase->assertNotEmpty($records);
        }

        foreach($records as $record) {
            if($this->level) {
                $testCase->assertSame($this->level, $record['level']);
            }

            if($this->channel) {
                $testCase->assertSame($this->channel, $record['channel']);
            }

            foreach($this->contextAsserts as $callback) {
                call_user_func($callback, $record['context']);
            }
        }
    }

    /**
     * @return TestHandler
     */
    private function getDebugLogger()
    {
        $handlers = $logs = $this->requestHelper->client->getContainer()->get('logger')->getHandlers();
        foreach ($handlers as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler;
            }
        }
    }
}