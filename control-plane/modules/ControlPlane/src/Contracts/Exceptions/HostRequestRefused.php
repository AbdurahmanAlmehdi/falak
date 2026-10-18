<?php

namespace Falak\ControlPlane\Contracts\Exceptions;

use RuntimeException;

/**
 * The host channel can't take a request: not installed, another request pending or running, or invalid input. The
 * message is for people.
 */
final class HostRequestRefused extends RuntimeException {}
