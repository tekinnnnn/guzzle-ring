<?php
namespace GuzzleHttp\Ring\Client;

use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\HandlerException;
use GuzzleHttp\Stream\LazyOpenStream;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Creates curl resources from a request
 */
class CurlFactory
{
    /**
     * Creates a cURL handle, header resource, and body resource based on a
     * transaction.
     *
     * @param array         $request Request hash
     * @param null|resource $handle  Optionally provide a curl handle to modify
     *
     * @return array Returns an array of the curl handle, headers array, and
     *               response body handle.
     * @throws \RuntimeException when an option cannot be applied
     */
    public function __invoke(array $request, $handle = null)
    {
        $headers = [];
        $options = $this->getDefaultOptions($request, $headers);
        $this->applyMethod($request, $options);

        if (isset($request['client'])) {
            $this->applyAdapterOptions($request, $options);
        }

        $this->applyHeaders($request, $options);
        unset($options['_headers']);

        // Add adapter options from the request's configuration options
        if (isset($request['client']['curl'])) {
            $options = $this->applyCustomCurlOptions(
                $request['client']['curl'],
                $options
            );
        }

        if (!$handle) {
            $handle = curl_init();
        }

        $body = $this->getOutputBody($request, $options);
        curl_setopt_array($handle, $options);

        return [$handle, &$headers, $body];
    }

    /**
     * Creates a response hash from a cURL result.
     *
     * @param array    $request  Request that was sent
     * @param array    $response Response hash to update
     * @param array    $headers  Headers received during transfer
     * @param resource $body     Body fopen response
     *
     * @return array
     */
    public static function createResponse(
        array $request,
        array $response,
        array $headers,
        $body
    ) {
        if (isset($response['transfer_stats']['url'])) {
            $response['effective_url'] = $response['transfer_stats']['url'];
        }

        if (is_resource($body)) {
            rewind($body);
        }
        $response['body'] = $body;

        if (isset($headers[0])) {
            $startLine = explode(' ', array_shift($headers), 3);
            $response['headers'] = Core::headersFromLines($headers);
            $response['status'] = isset($startLine[1]) ? (int) $startLine[1] : null;
            $response['reason'] = isset($startLine[2]) ? $startLine[2] : null;
        }

        return !empty($response['curl']['errno']) || !isset($startLine[1])
            ? self::createErrorResponse($request, $response)
            : $response;
    }

    private function getOutputBody(array $request, array &$options)
    {
        // Determine where the body of the response (if any) will be streamed.
        if (isset($options[CURLOPT_WRITEFUNCTION])) {
            return $request['client']['save_to'];
        }

        if (isset($options[CURLOPT_FILE])) {
            return $options[CURLOPT_FILE];
        }

        if ($request['http_method'] != 'HEAD') {
            // Create a default body if one was not provided
            return $options[CURLOPT_FILE] = fopen('php://temp', 'w+');
        }

        return null;
    }

    private static function createErrorResponse(
        array $request,
        array $response,
        $message = ''
    ) {
        if (!$message) {
            $message = sprintf('cURL error %s: %s',
                $response['curl']['errno'],
                isset($response['curl']['error'])
                    ? $response['curl']['error']
                    : 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html'
            );
        }

        return $response + [
            'status'  => null,
            'reason'  => null,
            'body'    => null,
            'headers' => [],
            'error'   => new HandlerException($message)
        ];
    }

    private function getDefaultOptions(array $request, array &$headers)
    {
        $url = Core::url($request);

        $options = [
            '_headers'             => $request['headers'],
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => 150,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$headers) {
                $length = strlen($h);
                if ($value = trim($h)) {
                    $headers[] = trim($h);
                }
                return $length;
            },
        ];

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $options;
    }

    private function applyMethod(array $request, array &$options)
    {
        $method = $request['http_method'];

        if ($method == 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset(
                $options[CURLOPT_WRITEFUNCTION],
                $options[CURLOPT_READFUNCTION],
                $options[CURLOPT_FILE],
                $options[CURLOPT_INFILE]
            );
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if (isset($request['body'])) {
                $this->applyBody($request, $options);
            }
        }
    }

    private function applyBody(array $request, array &$options)
    {
        $contentLength = Core::firstHeader($request, 'Content-Length');
        $size = $contentLength !== null ? (int) $contentLength : null;

        // Send the body as a string if the size is less than 1MB OR if the
        // [client][curl][body_as_string] request value is set.
        if (($size !== null && $size < 1000000) ||
            isset($request['client']['curl']['body_as_string']) ||
            is_string($request['body'])
        ) {
            $options[CURLOPT_POSTFIELDS] = Core::body($request);
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $options);
            $this->removeHeader('Transfer-Encoding', $options);
        } else {
            $options[CURLOPT_UPLOAD] = true;
            if ($size !== null) {
                // Let cURL handle setting the Content-Length header
                $options[CURLOPT_INFILESIZE] = $size;
                $this->removeHeader('Content-Length', $options);
            }
            $this->addStreamingBody($request, $options);
        }

        // If the Expect header is not present, prevent curl from adding it
        if (!Core::hasHeader($request, 'Expect')) {
            $options[CURLOPT_HTTPHEADER][] = 'Expect:';
        }

        // cURL sometimes adds a content-type by default. Prevent this.
        if (!Core::hasHeader($request, 'Content-Type')) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type:';
        }
    }

    private function addStreamingBody(array $request, array &$options)
    {
        $body = $request['body'];

        if ($body instanceof StreamInterface) {
            $options[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use ($body) {
                return (string) $body->read($length);
            };
            if (!isset($options[CURLOPT_INFILESIZE])) {
                if ($size = $body->getSize()) {
                    $options[CURLOPT_INFILESIZE] = $size;
                }
            }
        } elseif (is_resource($body)) {
            $options[CURLOPT_INFILE] = $body;
        } elseif ($body instanceof \Iterator) {
            $buf = '';
            $options[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use ($body, &$buf) {
                if ($body->valid()) {
                    $buf .= $body->current();
                    $body->next();
                }
                $result = (string) substr($buf, 0, $length);
                $buf = substr($buf, $length);
                return $result;
            };
        } else {
            throw new \InvalidArgumentException('Invalid request body provided');
        }
    }

    private function applyHeaders(array $request, array &$options)
    {
        foreach ($options['_headers'] as $name => $values) {
            foreach ($values as $value) {
                $options[CURLOPT_HTTPHEADER][] = "$name: $value";
            }
        }

        // Remove the Accept header if one was not set
        if (!Core::hasHeader($request, 'Accept')) {
            $options[CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }

    /**
     * Takes an array of curl options specified in the 'curl' option of a
     * request's configuration array and maps them to CURLOPT_* options.
     *
     * This method is only called when a  request has a 'curl' config setting.
     *
     * @param array $config  Configuration array of custom curl option
     * @param array $options Array of existing curl options
     *
     * @return array Returns a new array of curl options
     */
    private function applyCustomCurlOptions(array $config, array $options)
    {
        $curlOptions = [];
        foreach ($config as $key => $value) {
            if (is_int($key)) {
                $curlOptions[$key] = $value;
            }
        }

        return $curlOptions + $options;
    }

    /**
     * Remove a header from the options array.
     *
     * @param string $name    Case-insensitive header to remove
     * @param array  $options Array of options to modify
     */
    private function removeHeader($name, array &$options)
    {
        foreach (array_keys($options['_headers']) as $key) {
            if (!strcasecmp($key, $name)) {
                unset($options['_headers'][$key]);
                return;
            }
        }
    }

    /**
     * Applies an array of request client options to a the options array.
     *
     * This method uses a large switch rather than double-dispatch to save on
     * high overhead of calling functions in PHP.
     */
    private function applyAdapterOptions(array $request, array &$options)
    {
        foreach ($request['client'] as $key => $value) {
            switch ($key) {
            // Violating PSR-4 to provide more room.
            case 'verify':

                if ($value === false) {
                    unset($options[CURLOPT_CAINFO]);
                    $options[CURLOPT_SSL_VERIFYHOST] = 0;
                    $options[CURLOPT_SSL_VERIFYPEER] = false;
                    continue;
                }

                $options[CURLOPT_SSL_VERIFYHOST] = 2;
                $options[CURLOPT_SSL_VERIFYPEER] = true;

                if (is_string($value)) {
                    $options[CURLOPT_CAINFO] = $value;
                    if (!file_exists($value)) {
                        throw new \InvalidArgumentException(
                            "SSL CA bundle not found: $value"
                        );
                    }
                }
                break;

            case 'decode_content':

                if ($value === false) {
                    continue;
                }

                $accept = Core::firstHeader($request, 'Accept-Encoding');
                if ($accept) {
                    $options[CURLOPT_ENCODING] = $accept;
                } else {
                    $options[CURLOPT_ENCODING] = '';
                    // Don't let curl send the header over the wire
                    $options[CURLOPT_HTTPHEADER][] = 'Accept-Encoding:';
                }
                break;

            case 'save_to':

                if (is_string($value)) {
                    $value = new LazyOpenStream($value, 'w+');
                }

                if ($value instanceof StreamInterface) {
                    $options[CURLOPT_WRITEFUNCTION] =
                        function ($ch, $write) use ($value) {
                            return $value->write($write);
                        };
                } elseif (is_resource($value)) {
                    $options[CURLOPT_FILE] = $value;
                } else {
                    throw new \InvalidArgumentException('save_to must be a '
                        . 'GuzzleHttp\Stream\StreamInterface or resource');
                }
                break;

            case 'timeout':

                $options[CURLOPT_TIMEOUT_MS] = $value * 1000;
                break;

            case 'connect_timeout':

                $options[CURLOPT_CONNECTTIMEOUT_MS] = $value * 1000;
                break;

            case 'proxy':

                if (!is_array($value)) {
                    $options[CURLOPT_PROXY] = $value;
                } elseif (isset($request['scheme'])) {
                    $scheme = $request['scheme'];
                    if (isset($value[$scheme])) {
                        $options[CURLOPT_PROXY] = $value[$scheme];
                    }
                }
                break;

            case 'cert':

                if (is_array($value)) {
                    $options[CURLOPT_SSLCERTPASSWD] = $value[1];
                    $value = $value[0];
                }

                if (!file_exists($value)) {
                    throw new \InvalidArgumentException(
                        "SSL certificate not found: {$value}"
                    );
                }

                $options[CURLOPT_SSLCERT] = $value;
                break;

            case 'ssl_key':

                if (is_array($value)) {
                    $options[CURLOPT_SSLKEYPASSWD] = $value[1];
                    $value = $value[0];
                }

                if (!file_exists($value)) {
                    throw new \InvalidArgumentException(
                        "SSL private key not found: {$value}"
                    );
                }

                $options[CURLOPT_SSLKEY] = $value;
                break;

            case 'progress':

                if (!is_callable($value)) {
                    throw new \InvalidArgumentException(
                        'progress client option must be callable'
                    );
                }

                $options[CURLOPT_NOPROGRESS] = false;
                $options[CURLOPT_PROGRESSFUNCTION] =
                    function () use ($value) {
                        $args = func_get_args();
                        // PHP 5.5 pushed the handle onto the start of the args
                        if (is_resource($args[0])) {
                            array_shift($args);
                        }
                        call_user_func_array($value, $args);
                    };
                break;

            case 'debug':

                if ($value) {
                    $options[CURLOPT_STDERR] = is_resource($value)
                        ? $value
                        : STDOUT;
                    $options[CURLOPT_VERBOSE] = true;
                }
                break;
            }
        }
    }
}
