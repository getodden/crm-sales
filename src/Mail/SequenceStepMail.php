<?php

declare(strict_types=1);

namespace Odden\Sales\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A rendered sales email template sent by a sequence email step.
 */
class SequenceStepMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use ConfiguresSalesMail, Queueable, SerializesModels;

    /**
     * @param  string|null  $fromAddress  Null uses odden-sales.mail.from, then the app's mail.from.
     */
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
    ) {
        if ($this->fromAddress === null && ($configured = self::configuredFrom()) !== null) {
            [$this->fromAddress, $this->fromName] = $configured;
        }

        $this->configureSalesMail();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress !== null ? new Address($this->fromAddress, $this->fromName) : null,
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress, $this->replyToName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }
}
