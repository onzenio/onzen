<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Internal signal: a run was superseded while its result was being
 * completed (the enrollment version advanced inside the completion window).
 *
 * Thrown to roll the completion transaction back so nothing is projected;
 * the executor catches it and records the factual `discarded` outcome. It
 * never carries payload contents.
 */
final class SupersededRunException extends RuntimeException {}
