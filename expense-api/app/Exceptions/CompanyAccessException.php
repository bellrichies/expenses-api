<?php

namespace App\Exceptions;

use Exception;

class CompanyAccessException extends Exception
{
    public function __construct()
    {
        parent::__construct('You do not have access to this company\'s data');
    }
}
