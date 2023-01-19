<?php

declare(strict_types=1);

namespace Tym\Http\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Client\RequestExceptionInterface;

class RequestException extends \LogicException implements RequestExceptionInterface
{
    private const DEFAULT_ERROR = "Invalid Request: ";
    private const RUNTIME_ERROR = "Runtime Error: ";

    private RequestInterface $request;

    private RequestExceptionType $type;

    public function __construct(
        string $message,
        RequestExceptionType $type = RequestExceptionType::INVALID_REQUEST,
        RequestInterface $request = null,
        ?\Throwable $previous = null
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
            RequestExceptionType::INVALID_REQUEST => self::DEFAULT_ERROR,
            RequestExceptionType::RUNTIME_ERROR => self::RUNTIME_ERROR,
        };
        $message = $header . $message;
        return $message;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
