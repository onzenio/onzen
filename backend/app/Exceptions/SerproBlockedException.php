<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A SERPRO call was blocked before any external traffic.
 *
 * Fail-closed by design: missing or invalid credentials, unresolved vault
 * refs, refused OAuth exchanges and gated transport all surface as this
 * exception with a factual, sanitized message (a reason code, never a
 * secret). Callers record the block instead of retrying.
 */
class SerproBlockedException extends RuntimeException {}
