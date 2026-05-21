<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
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

    public function content(): Content
    {
        return new Content(
            htmlString: '
            <div dir="rtl" style="font-family:sans-serif;padding:20px;">
                <h2 style="color:#1e3a5f;">Curve — نظام إدارة التكاليف</h2>
                <p>النسخة الاحتياطية لقاعدة البيانات مرفقة مع هذا البريد.</p>
                <p style="color:#666;font-size:12px;">تم الإنشاء: ' . now()->format('Y-m-d H:i:s') . '</p>
            </div>',
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
