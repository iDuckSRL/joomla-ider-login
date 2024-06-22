<?php

namespace IDERConnect;

/**
 * OpenIDConnect Exception Class
 */
class OpenIDConnectClientException extends \Exception
{
    function __construct($message = "", $code = 0, Exception $previous = null)
    {
        IDERHelpers::logRotate($code . ' ' . $message, IDEROpenIDClient::$IDERLogFile);

        parent::__construct($message, $code, $previous);
    }
}
