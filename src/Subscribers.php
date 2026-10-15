<?php

declare(strict_types=1);

namespace Modulento\Newsletter;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * Double opt-in addresses: a row exists from the moment someone asks to be
 * added, confirmed_at stays NULL until the confirmation link is used, and
 * unsubscribing removes the row outright - there is no status to carry
 * once an address is gone. Only a token's hash is ever stored; the
 * plaintext is returned once, to put straight into a mail link.
 */
final class Subscribers
{
    private const UNCONFIRMED_MAX_AGE_DAYS = 7;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Asks to be added, or re-sends the confirmation of an address that
     * never confirmed. An already-confirmed address is left untouched - the
     * caller shows the same message either way, so a signup form never
     * reveals which addresses are already on the list.
     *
     * @return string|null the confirmation token, or null when nothing was sent
     */
    public function subscribe(string $email): ?string
    {
        $stmt = $this->db->prepare('SELECT id, confirmed_at FROM x_newsletter_subscriber WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if ($row !== false && $row['confirmed_at'] !== null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        if ($row === false) {
            $stmt = $this->db->prepare(
                'INSERT INTO x_newsletter_subscriber (email, token_hash, created_at) VALUES (:email, :hash, :now)'
            );
            $stmt->execute(['email' => $email, 'hash' => $hash, 'now' => Clock::now()]);
        } else {
            $stmt = $this->db->prepare('UPDATE x_newsletter_subscriber SET token_hash = :hash WHERE id = :id');
            $stmt->execute(['hash' => $hash, 'id' => $row['id']]);
        }

        return $token;
    }

    /** Confirms the address the token was sent to. Returns false for an unknown or already-confirmed token. */
    public function confirm(string $token): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE x_newsletter_subscriber SET confirmed_at = :now WHERE token_hash = :hash AND confirmed_at IS NULL'
        );
        $stmt->execute(['now' => Clock::now(), 'hash' => hash('sha256', $token)]);

        return $stmt->rowCount() === 1;
    }

    /** Removes the subscriber the token belongs to, confirmed or not. Returns false for an unknown token. */
    public function unsubscribeByToken(string $token): bool
    {
        $stmt = $this->db->prepare('DELETE FROM x_newsletter_subscriber WHERE token_hash = :hash');
        $stmt->execute(['hash' => hash('sha256', $token)]);

        return $stmt->rowCount() === 1;
    }

    /**
     * A fresh unsubscribe token for a confirmed subscriber, to put in a
     * campaign mail - rotated on every call (and so on every campaign a
     * subscriber receives), which keeps the token store the one place that
     * ever sees the plaintext.
     */
    public function freshUnsubscribeToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('UPDATE x_newsletter_subscriber SET token_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => hash('sha256', $token), 'id' => $id]);

        return $token;
    }

    public function confirmedCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM x_newsletter_subscriber WHERE confirmed_at IS NOT NULL')->fetchColumn();
    }

    /** @return list<array{id: int, email: string}> every confirmed address, for a send */
    public function confirmed(): array
    {
        return $this->db->query('SELECT id, email FROM x_newsletter_subscriber WHERE confirmed_at IS NOT NULL ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** An address that asked to be added but never confirmed, long enough ago that the ask is stale. */
    public function pruneUnconfirmed(): void
    {
        $stmt = $this->db->prepare('DELETE FROM x_newsletter_subscriber WHERE confirmed_at IS NULL AND created_at < :before');
        $stmt->execute(['before' => Clock::now(-self::UNCONFIRMED_MAX_AGE_DAYS * 86400)]);
    }
}
