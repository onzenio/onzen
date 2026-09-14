<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The backing artifact storage could not be reached or read.
 *
 * Callers must answer with a retryable, sanitized failure and never expose
 * the internal driver error or physical paths.
 */
class ArtifactStorageUnavailableException extends RuntimeException {}
