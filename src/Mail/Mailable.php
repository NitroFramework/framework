<?php

namespace Nitro\Mail;

use Closure;
use Nitro\Mail\Contracts\Mailer as MailerContract;
use Nitro\Mail\Mailables\Address;
use Nitro\Mail\Mailables\Content;
use Nitro\Mail\Mailables\Envelope;
use Nitro\Mail\Mailables\Headers;
use Nitro\Queue\Contracts\ShouldQueue;
use ReflectionClass;

/**
 * One kind of email, as a class.
 *
 *     class OrderShipped extends Mailable
 *     {
 *         public function __construct(public Order $order) {}
 *
 *         public function envelope(): Envelope
 *         {
 *             return new Envelope(subject: 'Your order is on its way');
 *         }
 *
 *         public function content(): Content
 *         {
 *             return new Content(view: 'mail.orders.shipped');
 *         }
 *     }
 *
 *     Mail::to($user)->send(new OrderShipped($order));
 *
 * The four methods — envelope, content, attachments, headers — are the
 * whole declaration; each is optional, and the fluent setters below do
 * the same thing for a message assembled at the call site. A mailable
 * that also implements ShouldQueue is queued rather than sent.
 *
 * Public properties are available to the view by name, the same as a
 * component's, so a view reads $order rather than $data['order'].
 */
abstract class Mailable
{
    /** @var array<int, Address> */
    public array $to = [];

    /** @var array<int, Address> */
    public array $cc = [];

    /** @var array<int, Address> */
    public array $bcc = [];

    /** @var array<int, Address> */
    public array $replyTo = [];

    public ?Address $from = null;

    public ?string $subject = null;

    /** The HTML view to render. */
    public ?string $view = null;

    /** The plain-text view, sent alongside the HTML one. */
    public ?string $textView = null;

    /** A markdown view, rendered to both an HTML and a text part. */
    public ?string $markdown = null;

    /** HTML given directly rather than as a view to render. */
    public ?string $html = null;

    /** @var array<string, mixed> Data passed to the view. */
    public array $viewData = [];

    /** @var array<int, Attachment> */
    public array $attachments = [];

    /** @var array<int, string> */
    public array $tags = [];

    /** @var array<string, string|int> */
    public array $metadata = [];

    /** @var array<int, Closure> Run against the built message. */
    public array $callbacks = [];

    /** The mailer this is sent through, or null for the default. */
    public ?string $mailer = null;

    /** The queue connection, queue and delay, when this is queued. */
    public ?string $connection = null;

    public ?string $queue = null;

    public int $delay = 0;

    /** The locale the message is rendered in. */
    public ?string $locale = null;

    public ?Headers $messageHeaders = null;

    // ── What a subclass declares ──────────────────────────────────────

    /** Who the message is from and to, and what it is called. */
    public function envelope(): Envelope
    {
        return new Envelope();
    }

    /** The view that renders the message, and its data. */
    public function content(): Content
    {
        return new Content();
    }

    /**
     * Files to send with it.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /** Headers beyond the ones the envelope implies. */
    public function headers(): Headers
    {
        return new Headers();
    }

    // ── Sending ───────────────────────────────────────────────────────

    /**
     * Build the message and hand it to the mailer.
     */
    public function send(MailerContract|MailManager $mailer): void
    {
        $mailer = $mailer instanceof MailManager
            ? $mailer->mailer($this->mailer)
            : $mailer;

        $mailer->send($this->buildMessage());
    }

    /**
     * The message this mailable describes, ready to be sent.
     */
    public function buildMessage(): Message
    {
        $this->prepareForDelivery();

        $message = new Message();

        $this->buildAddresses($message)
            ->buildSubject($message)
            ->buildBody($message)
            ->buildHeaders($message)
            ->buildAttachments($message);

        foreach ($this->callbacks as $callback) {
            $callback($message);
        }

        return $message;
    }

    /** The rendered HTML, without sending anything. */
    public function render(): string
    {
        $this->prepareForDelivery();

        return $this->renderHtml() ?? '';
    }

    /**
     * Fold what the four declarations say into this object.
     *
     * A fluent setter has already written to the same properties, so a
     * declaration only fills in what the call site did not say.
     */
    protected function prepareForDelivery(): void
    {
        $this->applyEnvelope($this->envelope());
        $this->applyContent($this->content());

        $this->attachments = array_merge($this->attachments, $this->attachments());

        $declared = $this->headers();

        $this->messageHeaders = new Headers(
            messageId: $this->messageHeaders?->messageId ?? $declared->messageId,
            references: array_merge($declared->references, $this->messageHeaders?->references ?? []),
            text: array_merge($declared->text, $this->messageHeaders?->text ?? []),
        );
    }

    private function applyEnvelope(Envelope $envelope): void
    {
        $this->from ??= $envelope->from;
        $this->subject ??= $envelope->subject;

        $this->to = array_merge($envelope->to, $this->to);
        $this->cc = array_merge($envelope->cc, $this->cc);
        $this->bcc = array_merge($envelope->bcc, $this->bcc);
        $this->replyTo = array_merge($envelope->replyTo, $this->replyTo);

        $this->tags = array_merge($envelope->tags, $this->tags);
        $this->metadata = array_merge($envelope->metadata, $this->metadata);
        $this->callbacks = array_merge($envelope->using, $this->callbacks);
    }

    private function applyContent(Content $content): void
    {
        $this->view ??= $content->view;
        $this->textView ??= $content->text;
        $this->markdown ??= $content->markdown;
        $this->html ??= $content->htmlString;

        $this->viewData = array_merge($content->with, $this->viewData);
    }

    // ── Building the message ──────────────────────────────────────────

    private function buildAddresses(Message $message): static
    {
        if ($this->from !== null) {
            $message->from($this->from->address, $this->from->name);
        }

        foreach ($this->to as $address) {
            $message->to($address->address, $address->name);
        }

        foreach ($this->cc as $address) {
            $message->cc($address->address, $address->name);
        }

        foreach ($this->bcc as $address) {
            $message->bcc($address->address, $address->name);
        }

        foreach ($this->replyTo as $address) {
            $message->replyTo($address->address, $address->name);
        }

        return $this;
    }

    /**
     * Name the message, falling back to the class name.
     *
     * A mailable with no subject is far more likely to be an omission
     * than a deliberately blank one, and "Order Shipped" is a better
     * answer than nothing at all.
     */
    private function buildSubject(Message $message): static
    {
        $message->subject($this->subject ?? $this->subjectFromClassName());

        return $this;
    }

    private function subjectFromClassName(): string
    {
        $name = (new ReflectionClass($this))->getShortName();

        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name);
    }

    private function buildBody(Message $message): static
    {
        $html = $this->renderHtml();

        if ($html !== null) {
            $message->html($html);
        }

        $text = $this->renderText();

        if ($text !== null) {
            $message->text($text);
        }

        return $this;
    }

    /** The HTML part, from whichever source was named. */
    private function renderHtml(): ?string
    {
        if ($this->html !== null) {
            return $this->html;
        }

        if ($this->markdown !== null) {
            return $this->markdownRenderer()->render($this->markdown, $this->buildViewData());
        }

        if ($this->view !== null) {
            return $this->renderView($this->view);
        }

        return null;
    }

    /** The plain-text part, when there is one. */
    private function renderText(): ?string
    {
        if ($this->textView !== null) {
            return $this->renderView($this->textView);
        }

        if ($this->markdown !== null) {
            return $this->markdownRenderer()->renderText($this->markdown, $this->buildViewData());
        }

        return null;
    }

    private function renderView(string $view): string
    {
        return \app('view')->render($view, $this->buildViewData());
    }

    private function markdownRenderer(): Markdown
    {
        return \app(Markdown::class);
    }

    /**
     * The data the view is rendered with.
     *
     * Public properties are included by name, so a view reads $order
     * rather than reaching into an array.
     *
     * @return array<string, mixed>
     */
    public function buildViewData(): array
    {
        $data = $this->viewData;

        foreach ((new ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== self::class) {
                $data[$property->getName()] = $property->getValue($this);
            }
        }

        return $data;
    }

    private function buildHeaders(Message $message): static
    {
        $headers = $this->messageHeaders;

        if ($headers === null) {
            return $this;
        }

        if ($headers->messageId !== null) {
            $message->header('Message-Id', '<' . trim($headers->messageId, '<>') . '>');
        }

        if ($headers->references !== []) {
            $message->header('References', $headers->referencesString());
        }

        $message->withHeaders($headers->text);

        foreach ($this->metadata as $key => $value) {
            $message->header('X-Metadata-' . $key, (string) $value);
        }

        if ($this->tags !== []) {
            $message->header('X-Tags', implode(',', $this->tags));
        }

        return $this;
    }

    private function buildAttachments(Message $message): static
    {
        foreach ($this->attachments as $attachment) {
            $attachment->attachTo($message);
        }

        return $this;
    }

    // ── The fluent API ────────────────────────────────────────────────

    public function from(Address|string $address, ?string $name = null): static
    {
        $this->from = is_string($address) ? new Address($address, $name) : $address;

        return $this;
    }

    public function to(mixed $address, ?string $name = null): static
    {
        $this->to = array_merge($this->to, $this->addressesFrom($address, $name));

        return $this;
    }

    public function cc(mixed $address, ?string $name = null): static
    {
        $this->cc = array_merge($this->cc, $this->addressesFrom($address, $name));

        return $this;
    }

    public function bcc(mixed $address, ?string $name = null): static
    {
        $this->bcc = array_merge($this->bcc, $this->addressesFrom($address, $name));

        return $this;
    }

    public function replyTo(mixed $address, ?string $name = null): static
    {
        $this->replyTo = array_merge($this->replyTo, $this->addressesFrom($address, $name));

        return $this;
    }

    /**
     * Read whatever names a recipient into addresses.
     *
     * A user object is accepted as well as a string, because that is
     * what a call site has in hand — it is read for an email and a
     * name rather than being required to be converted first.
     *
     * @return array<int, Address>
     */
    private function addressesFrom(mixed $address, ?string $name): array
    {
        if (is_string($address)) {
            return [new Address($address, $name)];
        }

        if ($address instanceof Address) {
            return [$address];
        }

        if (is_object($address)) {
            return [$this->addressFromObject($address)];
        }

        $addresses = [];

        foreach ((array) $address as $each) {
            $addresses = array_merge($addresses, $this->addressesFrom($each, null));
        }

        return $addresses;
    }

    /** An address read off a model or any object carrying an email. */
    private function addressFromObject(object $recipient): Address
    {
        $email = $recipient->email ?? $recipient->address ?? null;

        if (! is_string($email)) {
            throw new \InvalidArgumentException(
                'A recipient of type [' . $recipient::class . '] has no email address to read.'
            );
        }

        $name = $recipient->name ?? null;

        return new Address($email, is_string($name) ? $name : null);
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function view(string $view, array $data = []): static
    {
        $this->view = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    public function text(string $view, array $data = []): static
    {
        $this->textView = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    public function markdown(string $view, array $data = []): static
    {
        $this->markdown = $view;
        $this->viewData = array_merge($this->viewData, $data);

        return $this;
    }

    /** Give the HTML body directly, rendering no view. */
    public function html(string $html): static
    {
        $this->html = $html;

        return $this;
    }

    /** @param string|array<string, mixed> $key */
    public function with(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    public function attach(Attachment|string $file, array $options = []): static
    {
        $attachment = is_string($file) ? Attachment::fromPath($file) : $file;

        if (isset($options['as'])) {
            $attachment->as($options['as']);
        }

        if (isset($options['mime'])) {
            $attachment->withMime($options['mime']);
        }

        $this->attachments[] = $attachment;

        return $this;
    }

    /** @param array<int, Attachment|string> $files */
    public function attachMany(array $files): static
    {
        foreach ($files as $file => $options) {
            is_int($file)
                ? $this->attach($options)
                : $this->attach($file, (array) $options);
        }

        return $this;
    }

    public function attachData(string $data, string $name, array $options = []): static
    {
        return $this->attach(
            Attachment::fromData(static fn (): string => $data, $name),
            $options,
        );
    }

    public function attachFromStorage(string $path, ?string $name = null, array $options = []): static
    {
        return $this->attach(Attachment::fromStorage($path)->as($name), $options);
    }

    public function attachFromStorageDisk(string $disk, string $path, ?string $name = null, array $options = []): static
    {
        return $this->attach(Attachment::fromStorageDisk($disk, $path)->as($name), $options);
    }

    public function tag(string $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    public function metadata(string $key, string|int $value): static
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /** Run a callback against the built message, for anything unmodelled. */
    public function withMessage(Closure $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    /** Send through a named mailer rather than the default. */
    public function mailer(string $mailer): static
    {
        $this->mailer = $mailer;

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    // ── Queueing ──────────────────────────────────────────────────────

    public function onConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): static
    {
        $this->queue = $queue;

        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = max(0, $seconds);

        return $this;
    }

    /** Whether this mailable asks to be queued rather than sent now. */
    public function shouldQueue(): bool
    {
        return $this instanceof ShouldQueue;
    }

    // ── Asking, for a test ────────────────────────────────────────────

    public function hasTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->to, $address, $name);
    }

    public function hasCc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->cc, $address, $name);
    }

    public function hasBcc(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->bcc, $address, $name);
    }

    public function hasReplyTo(string $address, ?string $name = null): bool
    {
        return $this->hasRecipient($this->replyTo, $address, $name);
    }

    public function hasFrom(string $address, ?string $name = null): bool
    {
        return $this->from !== null
            && $this->from->address === $address
            && ($name === null || $this->from->name === $name);
    }

    public function hasSubject(string $subject): bool
    {
        return $this->subject === $subject;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function hasMetadata(string $key, string|int $value): bool
    {
        return ($this->metadata[$key] ?? null) === $value;
    }

    /** @param array<int, Address> $recipients */
    private function hasRecipient(array $recipients, string $address, ?string $name = null): bool
    {
        foreach ($recipients as $recipient) {
            if ($recipient->address === $address && ($name === null || $recipient->name === $name)) {
                return true;
            }
        }

        return false;
    }
}
