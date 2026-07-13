<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use DateTimeImmutable;

class Wallet
{
    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        private string $balance,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: '0.0000',
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setBalance(string $balance): void
    {
        $this->balance = $balance;
    }

    public function setIsBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }
}
