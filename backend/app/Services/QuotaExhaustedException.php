<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class QuotaExhaustedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Volume mensal de consultas esgotado. Troque de Plan para ampliar o volume e continuar monitorando.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
