<?php

namespace App\Exceptions;

use RuntimeException;

class ClassifierTimeoutException extends RuntimeException
{
    public function __construct(string $message = 'Classifier request timed out')
    {
        parent::__construct($message);
    }
}
