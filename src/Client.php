<?php

declare(strict_types=1);

namespace Tym\Http\Client;

use Psr\Http\Message\UriInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tym\Http\Message\Compression\Compressor;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * HTTP client.
 * 
 * @author Yvan Tchuente <yvantchuente@gmail.com>
 */
class Client implements ClientInterface
{
    /**
     * HTTP methods.
     * 
     * @var array
     */
    const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'TRACE', 'CONNECT', 'OPTIONS'];

    /**
     * Default cURL options for all requests.
     * 
     * @var array
     */
    private const DEFAULT_CURL_OPTIONS = [
        CURLOPT_HEADER => true,
        CURLOPT_AUTOREFERER => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
    ];

    /**
     * The list of available configuration options.
     *
     * @var string[]
     */
    private array $options = [
        'timeout',
        'http_auth',
        'max_redirects',
        'default_protocol',
        'connect_timeout',
        'enable_compression',
        'enable_decompression'
    ];

    /**
     * A look-up table of configuration options by their value types.
     *
     * @var string[][]
     */
    private array $optionTypeList = [
        'integer' => ['timeout', 'max_redirects', 'connect_timeout'],
        'string' => ['http_auth', 'default_protocol'],
        'boolean' => ['enable_compression', 'enable_decompression'],
    ];

    private \CurlHandle|null $curlHandle = null;

    /**
     * The configuration settings.
     * 
     * The name / value list of configuration options.
     *
     * @var string[]
     */
    private array $config = [];

    /** 
     * The stream factory instance.
     */
    private StreamFactoryInterface $streamFactory;

    /** 
     * The request factory instance.
     * 
     */
    private RequestFactoryInterface $requestFactory;

    /** 
     * The response factory instance.
     */
    private ResponseFactoryInterface $responseFactory;

    /** 
     * The HTTP message compressor instance.
     */
    private ?Compressor $compressor;

    /**
     * This constructor accepts an associative array of client configuration settings.
     * 
     * Valid configuration settings include:
     * 
     * - enable_compression: (boolean) Whether or not to gzip compress the body of every HTTP request to send.
     * 
     * - enable_decompression: (boolean) Whether or not to gzip decompress the body of every HTTP response received.
     *                         If decompression is enabled, it will only decompress if the Content-Encoding header
     *                         value of the HTTP response is 'gzip'
     * 
     * - timeout: (int) The maximum amount of time (in seconds) to wait after which the request times out.
     * 
     * - connect_timeout: (int) The maximum amount of time (in seconds) to wait while trying to connect
     *                          to the host. Use 0 to wait indefinitely.
     * 
     * - max_redirects: (int) The maximum amount of HTTP redirections to follow.
     * 
     * - http_auth: (string) The HTTP authentication method to use. Possible values for these include:
     *                       basic, digest, ntlm, gssnegotiate and any (The client will poll the server for what method to use).
     * 
     * - default_protocol: (string) Default protocol to use if the request's URI is missing a scheme. Either http or https.
     * 
     * @param array $configuration The name / value list client configuration options.
     * 
     * @throws \Exception if an error occurs.
     */
    public function __construct(
        StreamFactoryInterface $streamFactory,
        RequestFactoryInterface $requestFactory,
        ResponseFactoryInterface $responseFactory,
        Compressor $compressor = null,
        array $configuration = []
    ) {
        $this->streamFactory = $streamFactory;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->compressor = $compressor;
        if ($configuration) {
            $this->setConfiguration($configuration);
        }
    }

    public function __destruct()
    {
        if (isset($this->curlHandle)) {
            curl_close($this->curlHandle);
        }
        $this->curlHandle = null;
    }

    /**
     * Sets a client configuration option.

     * @param string $name The option name.
     * @param mixed $value The option value.
     * 
     * @return static
     * 
     * @throws \Exception if an error occurs.
     */
    public function setOption(string $name, $value)
    {
        if (!$name) {
            throw new \DomainException("Invalid name: empty string passed.");
        }
        if (empty($value)) {
            throw new \DomainException("Invalid option value.");
        }
        if (!in_array($name, $this->options, true)) {
            throw new \InvalidArgumentException("Unknown client configuration option.");
        }
        if (!in_array($type = gettype($value), $this->optionTypeList[$type])) {
            throw new \InvalidArgumentException("Incorrect value type for the '$name' option");
        }

        // If the option has multiple possible values.
        switch (true) {
            case ($name == 'default_protocol' && !preg_match('/https?/i', $value)): // fall-through
            case ($name == 'http_atuth' && !preg_match('/basic|digest|ntlm|gssnegotiate|any/i', $value)):
                throw new \InvalidArgumentException(sprintf("Invalid value for the setting: %s", $name));
                break;
        }
        $this->config[$name] = $value;
        return $this;
    }

    /**
     * Sets the client's configuration.
     *
     * @param array $configuration The name / value list of configuration options.
     */
    public function setConfiguration(array $options)
    {
        if (!$options) {
            throw new \DomainException('Empty configuration');
        }
        foreach ($options as $name => $value) {
            $this->setOption($name, $value);
        }
        return $this;
    }

    /**
     * Set the HTTP message compressor that the client uses.
     */
    public function setCompressor(Compressor $compressor)
    {
        $this->compressor = $compressor;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Extract data from the request
        $uri = $request->getUri();
        $method = strtoupper($request->getMethod());
        $requestTarget = $request->getRequestTarget();
        $url = (string) $uri; // Get the effective URL

        // Initialize the cURL transfer options.
        $options = $this->convertSettingsToRequestOptions();

        // Process the request
        if (!in_array($method, self::METHODS)) {
            throw (new RequestException("Invalid request method"))->setRequest($request);
        }
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_REQUEST_TARGET] = $requestTarget;
        $options[CURLOPT_HTTP_VERSION] = $this->getVersion($request);
        $includeBody = true;

        // Initialize the cURL session
        $this->curlHandle = curl_init($url);

        switch (true) {
            case (preg_match('/HEAD/', $method)):
                $options[CURLOPT_NOBODY] = true;
                $includeBody = false;
                break;

            case (preg_match('/POST|PUT/', $method)):
                /**
                 * If the client is configured to compress all request body data
                 * and the request object is missing a Content-Encoding header
                 * field. Then the request body data is gzip compressed and appropriate
                 * headers are added to the request to ensure remains internally
                 * consistent.
                 */
                $this->validateRequest($request);
                $body = $request->getBody();
                if ($this->config['enable_compression'] && !$request->hasHeader('Content-Encoding')) {
                    if (!$body->isWritable()) {
                        throw new RequestException("Request body is not writable", RequestExceptionType::RUNTIME_ERROR, $request);
                    }
                    if (is_null($this->compressor)) {
                        throw new \RuntimeException("The client was not configured with an HTTP message compressor");
                    }
                    // Compress the request
                    $request = $this->compressor->compress($request);
                }

            case (preg_match('/POST/', $method)):
                $body = $request->getBody();
                $options[CURLOPT_POSTFIELDS] = (string) $body;
                break;

            case (preg_match('/PUT/', $method)):
                $this->validateRequest($request);
                $body = $request->getBody();
                $size = $body->getSize();
                $uri = $body->getMetadata('uri');

                if (preg_match('/php:\/{2}\w+/', $uri)) {
                    // The body is a PHP I/O stream
                    $file = tmpfile();
                    $contents = (string) $body;
                    fwrite($file, $contents);
                    rewind($file);
                } else {
                    $file = $body->detach();
                    if (is_null($file)) {
                        throw new RequestException(
                            "Request body is missing",
                            RequestExceptionType::RUNTIME_ERROR,
                            $request
                        );
                    }
                }

                $options[CURLOPT_PUT] = true;
                $options[CURLOPT_INFILE] = $file;
                $options[CURLOPT_INFILESIZE] = $size;
                break;
        }

        $header_fields = $this->toHeaderFieldList($request->getHeaders());
        if ($header_fields) {
            $options[CURLOPT_HTTPHEADER] = $header_fields;
        }
        $options += self::DEFAULT_CURL_OPTIONS;
        curl_setopt_array($this->curlHandle, $options);

        // Send the request
        $result = curl_exec($this->curlHandle);

        // Retrieve the transfer metadata
        $transfer_info = curl_getinfo($this->curlHandle);

        // Check if an error occurred
        $errorNo = curl_errno($this->curlHandle);
        if ($errorNo > 0) {
            $error = curl_error($this->curlHandle);
            $body = $request->getBody();

            switch ($errorNo) {
                case CURLE_COULDNT_RESOLVE_HOST:
                    $type = NetworkExceptionType::HOST_NOT_FOUND;
                    break;
                case CURLE_OPERATION_TIMEDOUT:
                    $request = $this->sentRequest($transfer_info, $method, $uri)->withBody($body);
                    $type = NetworkExceptionType::TIME_OUT;
                    break;
                case CURLE_UNSUPPORTED_PROTOCOL:
                    $error = "Unsupported protocol";
                    $type = NetworkExceptionType::NETWORK_ERROR;
                    break;
                case CURLE_URL_MALFORMAT:
                    $error = "The URL was not properly formatted.";
                    $type = NetworkExceptionType::NETWORK_ERROR;
                    break;
                case CURLE_COULDNT_CONNECT:
                case CURLE_COULDNT_RESOLVE_HOST:
                case CURLE_COULDNT_RESOLVE_PROXY:
                    $type = NetworkExceptionType::NETWORK_ERROR;
                    break;
                default:
                    $request = $this->sentRequest($transfer_info, $method, $uri)->withBody($body);
                    $type = NetworkExceptionType::NETWORK_ERROR;
                    break;
            }

            throw new NetworkException($error, $type, $request);
        }

        $code = $transfer_info['http_code'];
        $reasonPhrase = $this->getReasonPhrase($result, $code);
        $response_headers_size = $transfer_info['header_size'];
        $response_headers = $this->getResponseHeaders($result, $response_headers_size);
        $response = $this->responseFactory->createResponse($code, $reasonPhrase);
        $this->setHeaders($response, $response_headers);

        if (!$includeBody) {
            return $response;
        }

        $body = $this->streamFactory->createStream($result);
        $response = $response->withBody($body);
        if ($this->config['enable_decompression'] && ($response->getHeaderLine('Content-Encoding') === 'gzip')) {
            $response = $this->compressor->decompress($response);
        }

        return $response;
    }

    /**
     * Translates the configuration settings into cURL transfer options.
     *
     * @return array
     */
    private function convertSettingsToRequestOptions()
    {
        $options = [];
        foreach ($this->config as $name => $value) {
            switch ($name) {
                case 'timeout':
                    $options[CURLOPT_TIMEOUT] = $value;
                    break;
                case 'connect_timeout':
                    $options[CURLOPT_CONNECTTIMEOUT] = $value;
                    break;
                case 'max_redirects':
                    $options[CURLOPT_MAXREDIRS] = $value;
                    break;
                case 'http_auth':
                    switch ($value) {
                        case 'basic':
                            $value = CURLAUTH_BASIC;
                            break;
                        case 'digest':
                            $value = CURLAUTH_DIGEST;
                            break;
                        case 'ntlm':
                            $value = CURLAUTH_NTLM;
                            break;
                        case 'gssnegotiate':
                            $value = CURLAUTH_GSSNEGOTIATE;
                            break;
                        case 'any':
                            $value = CURLAUTH_ANY;
                            break;
                    }
                    $options[CURLOPT_HTTPAUTH] = $value;
                    break;
                case 'default_protocol':
                    $options[CURLOPT_DEFAULT_PROTOCOL] = $value;
                    break;
            }
        }

        return $options;
    }

    /**
     * Convert a given request's HTTP protocol version to its cURL equivalent.
     *
     * @return int One of CURL_HTTP_VERSION_XXX constants.
     */
    private function getVersion(RequestInterface $request)
    {
        $scheme = $request->getUri()->getScheme();
        $version = $request->getProtocolVersion();
        switch (true) {
            case (preg_match('/1(\.0)?^$/', $version)):
                $version = CURL_HTTP_VERSION_1_0;
                break;
            case (preg_match('/^1(\.1)?$/', $version)):
                $version = CURL_HTTP_VERSION_1_1;
                break;
            case (preg_match('/^2(\.0)?$/', $version)):
                $version = CURL_HTTP_VERSION_2_0;
                break;
            case (preg_match('/^2(\.0)?$/', $version) && preg_match('/^https$/i', $scheme)):
                $version = CURL_HTTP_VERSION_2TLS;
                break;
            default:
                $version = CURL_HTTP_VERSION_NONE;
                break;
        }
        return $version;
    }

    /**
     * Appends headers to a PSR-7 HTTP message.
     *
     * @param array $headers Header values.
     */
    private function setHeaders(MessageInterface $message, array $headers)
    {
        foreach ($headers as $name => $values) {
            $message = $message->withHeader($name, $values);
        }
    }

    /**
     * @throws RequestException
     */
    private function validateRequest(RequestInterface $request)
    {
        $body = $request->getBody();
        if (!$request->hasHeader('Content-Type')) {
            throw (new RequestException("The 'Content-Type' header is missing"))->setRequest($request);
        }
        if (!$request->hasHeader('Content-Length')) {
            throw (new RequestException("The 'Content-Length' header is missing"))->setRequest($request);
        }
        $size = $body->getSize();
        if (!$size) {
            throw (new RequestException("The request body size is undetermined"))->setRequest($request);
        }
        $contentLength = (int) $request->getHeaderLine('Content-Length');
        if ($size !== $contentLength) {
            throw (new RequestException("The size of the stream does not match the 'Content-Length' header value"))->setRequest($request);
        }
        if (!$body->isReadable()) {
            throw new RequestException("The request body is not readable", RequestExceptionType::RUNTIME_ERROR, $request);
        }
    }

    /**
     * Retrieves the request that was sent.
     *
     * @return RequestInterface
     */
    private function sentRequest(array $transfer_info, string $method, UriInterface|string $uri)
    {
        $header_values = $this->toHeaderValueList($transfer_info['request_header']);
        $request = $this->requestFactory->createRequest($method, $uri);
        $this->setHeaders($request, $header_values);
        return $request;
    }

    /**
     * Retrieves the reason phrase from the status-line of the response
     * 
     * @param string $result The raw result of the transfer
     * @param int $code The HTTP status code of the response
     * 
     * @return string
     */
    private function getReasonPhrase(string &$result, int $code)
    {
        $lines = preg_split("/\r\n/", $result);
        $status_lines = array_filter($lines, function ($value) {
            return (bool) preg_match('/HTTP\/\d\.\d \d{3} \w+/', $value);
        });
        $statusLine = '';
        foreach ($status_lines as $status_line) {
            if (preg_match('/' . $code . '/', $status_line)) {
                $statusLine = $status_line;
                break;
            }
        }
        $reasonPhrase = substr($statusLine, 13);
        return $reasonPhrase;
    }

    /**
     * Retrieves the header values from a given response.
     * 
     * @param string $result The response .
     * 
     * @return string[][]
     */
    private function getResponseHeaders(string &$response, int $headers_size)
    {
        $headers = substr($response, 0, $headers_size);
        $headers = $this->toHeaderValueList($headers);
        $response = substr($response, $headers_size);
        return $headers;
    }

    /**
     * Gets the fields from a given array of header values.
     * 
     * @param string[][] $headers
     * 
     * @return string[] The list of header fields.
     */
    private function toHeaderFieldList(array $header_values)
    {
        $header_fields = [];
        foreach ($header_values as $name => $value) {
            $value = implode(",", $value);
            $header = $name . ": " . $value;
            $header_fields[] = $header;
        }
        return $header_fields;
    }

    /**
     * Converts a given list of header fields into a list of header values.
     * 
     * @return string[][]
     */
    private function toHeaderValueList(string $header_fields)
    {
        $headers = [];
        $lines = preg_split("/\r\n/", $header_fields);
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/(\S+): (.*)/', $line, $matches)) {
                $headers[$matches[1]] = (preg_match('/((.+),|;)+/', $matches[2]) && !preg_match('/Date|Expires/i', $matches[1])) ? preg_split('/,|;/', $matches[2]) : preg_split('/\n/', $matches[2]);
            }
        }
        return $headers;
    }
}
