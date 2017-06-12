<?php

namespace Draw\DrawBundle\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Client;

class RequestHelper
{
    public $testCase;

    /**
     * @var Client
     */
    public $client;

    public $uri;

    public $method = 'GET';

    public $isJson;

    public $body;

    public $maximumSqlQuery;

    public $queryFilters = [];

    public $assertions = [
        "statusCode" => null,
        "responseContentType" => null,
        "against" => null,
    ];

    public $hooks = [
        'preRequest' => [],
        'preAssertion' => []
    ];

    public $contentFilters = [];

    public $servers = [];

    public function __construct(TestCase $testCase, Client $client)
    {
        $this->client = $client;
        $this->testCase = $testCase;
        $this->expectingStatusCode(200);

        $this->assertions['against'] = function () {
            $this->filterContent($this->client->getResponse()->getContent());
        };
    }

    /**
     * @param TestCase $testCase
     * @param Client $client
     * @return static
     */
    public static function factory(TestCase $testCase, Client $client)
    {
        return new static($testCase, $client);
    }

    public function addPreAssertionCallback($callback)
    {
        $this->hooks['preAssertion'][] = $callback;

        return $this;
    }

    public function addPreRequestCallback($callback)
    {
        $this->hooks['preRequest'][] = $callback;

        return $this;
    }

    public function get($uri = null)
    {
        return $this->setMethod('GET', $uri);
    }

    public function post($uri = null)
    {
        return $this->setMethod('POST', $uri);
    }

    public function delete($uri = null)
    {
        return $this->setMethod('DELETE', $uri);
    }

    public function put($uri = null)
    {
        return $this->setMethod('PUT', $uri);
    }

    public function on($uri)
    {
        return $this->setUri($uri);
    }

    /**
     * @param $propertyPath
     * @return PropertyHelper
     */
    public function propertyHelper($propertyPath)
    {
        return new PropertyHelper($this, $propertyPath);
    }

    /**
     * @param string $message
     *
     * @return LogHelper
     */
    public function logHelper($message)
    {
        return new LogHelper($this, $message);
    }

    public function expectContentType($contentType)
    {
        $this->assertions["responseContentType"] = function () use ($contentType) {
            $response = $this->client->getResponse();
            $this->testCase->assertTrue(
                $response->headers->contains('Content-Type', $contentType),
                $response->headers
            );
        };

        return $this;
    }

    public function expectingNoContent()
    {
        $this->assertions["responseContentType"] = function () {
            $response = $this->client->getResponse();
            $this->testCase->assertFalse($response->headers->has('Content-Type'));
        };

        $this->assertions["against"] = function () {
            $response = $this->client->getResponse();
            $this->testCase->assertEmpty($response->getContent());
        };

        return $this;
    }

    public function expectingException($statusCode)
    {
        $this->expectingStatusCode($statusCode);
        $this->contentFilters[] = function ($content) {
            $content = json_decode($content);
            unset($content->detail);

            return json_encode($content);
        };

        return $this;
    }

    public function setUri($uri)
    {
        $this->uri = $uri;

        return $this;
    }

    public function setMethod($method, $uri = null)
    {
        $this->method = $method;
        if ($uri) {
            $this->setUri($uri);
        }

        return $this;
    }

    public function asJson()
    {
        $this->isJson = true;
        $this->expectContentType('application/json');

        return $this;
    }

    public function withBody($body)
    {
        $this->body = $body;

        return $this;
    }

    public function expectingStatusCode($statusCode)
    {
        $this->assertions['statusCode'] = function () use ($statusCode) {
            $this->testCase->assertSame(
                $statusCode,
                $this->client->getResponse()->getStatusCode(),
                $this->client->getResponse()->getContent()
            );
        };

        return $this;
    }

    public function validateAgainstFile($file = null)
    {
        if (is_null($file)) {
            list($class, $method) = $this->getCallingClassAndMethod();
            $class = new \ReflectionClass($class);
            $className = str_replace($class->getNamespaceName() . '\\', '', $class->getName());
            $dir = dirname($class->getFileName()) . '/fixtures/out';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $file = $dir . '/' . $className . '-' . $method . '.json';
        }

        $this->assertions['against'] = function () use ($file) {
           $content = $this->filterContent($this->client->getResponse()->getContent());

            if (!file_exists($file)) {
                file_put_contents($file, json_encode(json_decode($content), JSON_PRETTY_PRINT));
            }

            $this->testCase->assertJsonStringEqualsJsonString(
                file_get_contents($file),
                $content
            );
        };

        return $this;
    }

    private function filterContent($content)
    {
        foreach ($this->contentFilters as $filter) {
            $content = call_user_func($filter, $content);
        }

        return $content;
    }

    public function validateJsonAfterFilter($string, $notAllowedKeys = [])
    {
        $this->assertions['against'] = function () use ($string, $notAllowedKeys) {
            $content = $this->filterContent($this->client->getResponse()->getContent());
            $this->testCase->assertJson($content);

            $json = json_decode($content, true);
            $json = array_filter($json, function ($key) use ($notAllowedKeys) {
                //if key is in the array, it should be filtered.
                return in_array($key, $notAllowedKeys) === false;
            },ARRAY_FILTER_USE_KEY);

            $content = json_encode($json);
            $this->testCase->assertJsonStringEqualsJsonString(
                $string,
                $content
            );
        };

        return $this;
    }

    public function validateAgainstString($string)
    {
        $this->assertions['against'] = function () use ($string) {
            $content = $this->filterContent($this->client->getResponse()->getContent());

            $this->testCase->assertJsonStringEqualsJsonString(
                $string,
                $content
            );
        };

        return $this;
    }

    public function asserting($callBack)
    {
        $this->assertions[] = $callBack;

        return $this;
    }

    public function execute()
    {
        $server = $this->servers;
        $body = $this->body;
        if ($this->isJson) {
            $server['HTTP_ACCEPT'] = 'application/json';
            $server['CONTENT_TYPE'] = 'application/json';
            if (!is_null($this->body)) {
                $body = json_encode($body);
            }
        }

        foreach($this->hooks['preRequest'] as $callback) {
            call_user_func($callback, $this);
        }

        $crawler = $this->client->request($this->method, $this->uri, array(), array(), $server, $body);

        foreach($this->hooks['preAssertion'] as $callback) {
            call_user_func($callback, $this, $crawler);
        }

        foreach (array_filter($this->assertions) as $callback) {
            call_user_func($callback, $crawler);
        }

        return $crawler;
    }

    /**
     * @param $name
     * @param $value
     * @return $this
     */
    public function setServerParameter($name, $value)
    {
        $this->servers[$name] = $value;

        return $this;
    }

    public function executeAndDecodeJson()
    {
        $this->execute();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @param $amount
     */
    public function maximumSqlQuery($amount, array $filters = [])
    {
        if(!$this->maximumSqlQuery) {
//            $this->addPreRequestCallback(function() {
//                $this->client->getKernel()->boot();
//                $this->client->enableProfiler();
//            });
        }

        $this->queryFilters = $filters;
        $this->maximumSqlQuery = $amount;
//        $this->asserting(function() {
//            $queries = $this->client->getProfile()->getCollector('db')->getQueries()['default'];
//
//            //We remove the query "COMMIT" and "START TRANSACTION"
//            $queries = array_filter($queries, function($query) {
//                return !is_null($query['types']);
//            });
//
//            //we apply extra custom filters.
//            if (!empty($this->queryFilters)) {
//                foreach ($this->queryFilters as $filter) {
//                    $queries = array_filter($queries, $filter);
//                }
//            }
//
//            $this->testCase->assertLessThanOrEqual($this->maximumSqlQuery, count($queries), json_encode($queries, JSON_PRETTY_PRINT));
//        });

        return $this;
    }

    private function getCallingClassAndMethod()
    {

        //get the trace
        $trace = debug_backtrace();

        // Get the class that is asking for who awoke it
        $class = $trace[1]['class'];

        // +1 to i cos we have to account for calling this function
        for ($i = 1; $i < count($trace); $i++) {
            if (isset($trace[$i])) // is it set?
            {
                if ($class != $trace[$i]['class']) // is it a different class
                {
                    return array($trace[$i]['class'], $trace[$i]['function']);
                }
            }
        }
    }
}