<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private string $filePath,
        private string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'نسخة احتياطية - Curve | ' . now()->format('Y-m-d H:i'),
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->filePath)
                ->as($this->filename)
                ->withMime('application/gzip'),
        ];
    }
}
