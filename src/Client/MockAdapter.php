<?php
namespace GuzzleHttp\Ring\Client;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future;

/**
 * Ring adapter that returns a canned response or evaluated function result.
 *
 * This class is useful for implementing mock responses while still accounting
 * for things like the "then" request option.
 */
class MockAdapter
{
    /** @var callable|array|Future */
    private $result;

    /**
     * Provide an array or Future to always return the same value. Provide a
     * callable that accepts a request object and returns an array or Future
     * to dynamically create a response.
     *
     * @param array|Future|callable $result Result to evaluate and return.
     */
    public function __construct($result)
    {
        $this->result = $result;
    }

    public function __invoke(array $request)
    {
        $response = is_callable($this->result)
            ? call_user_func($this->result, $request)
            : $this->result;

        if (isset($request['then'])) {
            // Create a new future that will call "then" when deref'd
            if ($response instanceof Future) {
                $response = new Future(function () use ($request, $response) {
                    return $this->callThen($request, Core::deref($response));
                });
            } else {
                $response = $this->callThen($request, $response);
            }
        } elseif (is_array($response)) {
            return $this->addMissing($response);
        }

        return $response;
    }

    private function callThen(array $request, $response)
    {
        $then = $request['then'];
        $response = $then($response) ?: $response;

        return $this->addMissing($response);
    }

    private function addMissing(array $response)
    {
        return $response + [
            'status'        => null,
            'body'          => null,
            'headers'       => [],
            'reason'        => null,
            'effective_url' => null
        ];
    }
}
