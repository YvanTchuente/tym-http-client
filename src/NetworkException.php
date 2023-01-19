<?php

declare(strict_types=1);

namespace Tym\Http\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Client\NetworkExceptionInterface;

class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    private const DEFAULT_ERROR = "Network Error: ";
    private const HOST_NOT_FOUND = "Host Not Found: ";
    private const TIME_OUT = "Time Out: ";

    private RequestInterface $request;

    private NetworkExceptionType $type;

    public function __construct(
        string $message,
        NetworkExceptionType $type = NetworkExceptionType::NETWORK_ERROR,
        RequestInterface $request = null,
        \Throwable|null $previous = null
    ) {
        $this->type = $type;
        $code = $this->type->value;
        $message = $this->formatMessage($message, $code);
        if (isset($request)) {
            $this->request = $request;
        }
        parent::__construct($message, $code, $previous);
    }

    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;
        return $this;
    }

    private function formatMessage(string $message, int $code)
    {
        $header = "[$code] ";
        $header .= match ($this->type) {
            NetworkExceptionType::HOST_NOT_FOUND => self::HOST_NOT_FOUND,
            NetworkExceptionType::TIME_OUT => self::TIME_OUT,
            NetworkExceptionType::NETWORK_ERROR =>  self::DEFAULT_ERROR
        };
        $message = $header . $message;
        return $message;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
