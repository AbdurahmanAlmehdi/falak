<?php

namespace Falak\Identity\Application\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Laravel's verification mail, sent from the queue (no SMTP / Resend round trip in the request). */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
