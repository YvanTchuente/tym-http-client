<?php

declare(strict_types=1);

namespace Tym\Http\Client;

enum NetworkExceptionType: int
{
    case NETWORK_ERROR = 1001;
    case HOST_NOT_FOUND = 1002;
    case TIME_OUT = 1003;
}
