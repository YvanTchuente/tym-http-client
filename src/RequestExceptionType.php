<?php

declare(strict_types=1);

namespace Tym\Http\Client;

enum RequestExceptionType: int
{
    case INVALID_REQUEST = 1001;
    case RUNTIME_ERROR = 1002;
}
