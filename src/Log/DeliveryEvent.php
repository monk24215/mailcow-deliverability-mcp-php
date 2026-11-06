<?php

declare(strict_types=1);

namespace MailcowDeliverability\Log;

/**
 * One Postfix delivery attempt for one recipient.
 */
final class DeliveryEvent
{
    public const STATUSES = ['sent', 'deferred', 'bounced', 'expired'];

    public function __construct(
        public readonly ?\DateTimeImmutable $time,
        public readonly string $queueId,
        public readonly string $recipient,
        public readonly string $recipientDomain,
        public readonly string $provider,
        /** sent | deferred | bounced | expired | other */
        public readonly string $status,
        public readonly string $dsn,
        public readonly string $reason,
        public readonly string $relay,
    ) {
    }

    public function isHardFailure(): bool
    {
        return 'bounced' === $this->status || 'expired' === $this->status;
    }
}
