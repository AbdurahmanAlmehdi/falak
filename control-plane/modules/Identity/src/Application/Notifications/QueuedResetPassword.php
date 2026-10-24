<?php

namespace Falak\Identity\Application\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Laravel's password reset mail, sent from the queue (no SMTP / Resend round trip in the request). */
final class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
