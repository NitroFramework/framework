<?php

namespace Nitro\Notifications\Channels;

use Nitro\Mail\Attachment as MailAttachment;
use Nitro\Mail\Contracts\Mailer;
use Nitro\Mail\Mailable;
use Nitro\Mail\Markdown;
use Nitro\Mail\Message;
use Nitro\Notifications\Contracts\Channel;
use Nitro\Notifications\Messages\MailMessage;
use Nitro\Notifications\Notification;
use RuntimeException;

/**
 * Delivers a notification's `toMail()` through the mailer.
 *
 * A {@see MailMessage} is rendered here rather than by the notification, so a
 * notification describes what it means and every email in the application
 * comes out looking the same. A mailer {@see Message} is passed through as-is,
 * for the cases that need full control of the markup.
 */
class MailChannel implements Channel
{
    /** View rendering a MailMessage when it names none of its own. */
    protected const LAYOUT = 'nitro::email';

    public function __construct(protected Mailer $mailer) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toMail')) {
            throw new RuntimeException(
                get_class($notification) . ' must define toMail() to use the mail channel.'
            );
        }

        $message = $this->toMailerMessage($notification->toMail($notifiable), $notification);

        if ($message->to === [] && ($to = $notifiable->routeNotificationFor('mail'))) {
            $message->to((string) $to);
        }

        $this->mailer->send($message);
    }

    /**
     * Get a mailer message, rendering a described one if that is what we have.
     */
    protected function toMailerMessage(MailMessage|Mailable|Message $message, Notification $notification): Message
    {
        if ($message instanceof Message) {
            return $message;
        }

        // A notification whose mail is elaborate enough to be its own
        // class says so by returning one.
        if ($message instanceof Mailable) {
            return $message->buildMessage();
        }

        return $this->render($message, $notification);
    }

    /**
     * Turn a described message into one the mailer can send.
     */
    protected function render(MailMessage $message, Notification $notification): Message
    {
        $mail = new Message();

        $mail->subject($message->subject ?? $this->subjectFor($notification));
        $mail->html($this->renderBody($message));

        $text = $this->renderText($message);

        if ($text !== null) {
            $mail->text($text);
        }

        foreach ($message->to as $address) {
            $mail->to($address);
        }
        foreach ($message->cc as $address) {
            $mail->cc($address);
        }
        foreach ($message->bcc as $address) {
            $mail->bcc($address);
        }
        foreach ($message->replyTo as $address) {
            $mail->replyTo($address);
        }

        if ($message->from !== []) {
            $mail->from($message->from[0], $message->from[1] ?? null);
        }

        foreach ($message->attachments as $attachment) {
            $mail->attach(
                $attachment['path'],
                $attachment['options']['as'] ?? null,
                $attachment['options']['mime'] ?? null,
            );
        }
        foreach ($message->rawAttachments as $attachment) {
            $mail->attachData(
                $attachment['data'],
                $attachment['name'],
                $attachment['options']['mime'] ?? null,
            );
        }
        foreach ($message->storageAttachments as $attachment) {
            MailAttachment::fromStorageDisk($attachment['disk'], $attachment['path'])
                ->as($attachment['name'])
                ->withMime($attachment['options']['mime'] ?? null)
                ->attachTo($mail);
        }

        foreach ($message->headers as $name => $value) {
            $mail->header($name, $value);
        }

        foreach ($message->metadata as $key => $value) {
            $mail->header('X-Metadata-' . $key, (string) $value);
        }

        if ($message->tags !== []) {
            $mail->header('X-Tags', implode(',', $message->tags));
        }

        if ($message->priority !== null) {
            $mail->header('X-Priority', (string) $message->priority);
        }

        return $mail;
    }

    /**
     * Render the message body, through the caller's view or the built-in one.
     */
    protected function renderBody(MailMessage $message): string
    {
        if ($message->markdown !== null) {
            return $this->markdown($message)->render($message->markdown, $message->data());
        }

        return view($message->view ?? self::LAYOUT, $message->data())->render();
    }

    /**
     * The plain-text part, when the message names one.
     *
     * A markdown view is already the plain-text form of what it
     * describes, so it supplies both parts on its own.
     */
    protected function renderText(MailMessage $message): ?string
    {
        if ($message->textView !== null) {
            return view($message->textView, $message->data())->render();
        }

        if ($message->markdown !== null) {
            return $this->markdown($message)->renderText($message->markdown, $message->data());
        }

        return null;
    }

    protected function markdown(MailMessage $message): Markdown
    {
        $markdown = \app(Markdown::class);

        return $message->theme === null ? $markdown : $markdown->theme($message->theme);
    }

    /**
     * A subject derived from the notification's class name.
     *
     * `OrderShipped` becomes "Order Shipped", which is a better default than
     * the class name itself and is overridden by naming one.
     */
    protected function subjectFor(Notification $notification): string
    {
        $class = substr(strrchr('\\' . get_class($notification), '\\'), 1);

        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $class));
    }
}
