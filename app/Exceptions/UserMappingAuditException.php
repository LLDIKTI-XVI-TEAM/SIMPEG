<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class UserMappingAuditException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Audit mapping user gagal ditulis.', 0, $previous);
    }
}
