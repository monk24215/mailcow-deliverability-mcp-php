<?php

declare(strict_types=1);

namespace MailcowDeliverability\Analysis;

/**
 * Maps a recipient domain to the mailbox provider that scores its reputation.
 *
 * Sending reputation is kept per receiving provider, not per domain, so
 * hotmail.com and outlook.com are judged together. Unmapped domains group
 * under their own name.
 */
final class ProviderMap
{
    /** Same table as PROVIDER_MAP in the TypeScript project's src/constants.ts. */
    public const DEFAULTS = [
        'gmail.com' => 'Google',
        'googlemail.com' => 'Google',
        'google.com' => 'Google',
        'hotmail.com' => 'Microsoft',
        'outlook.com' => 'Microsoft',
        'live.com' => 'Microsoft',
        'msn.com' => 'Microsoft',
        'hotmail.co.uk' => 'Microsoft',
        'outlook.co.uk' => 'Microsoft',
        'yahoo.com' => 'Yahoo/AOL',
        'yahoo.co.uk' => 'Yahoo/AOL',
        'ymail.com' => 'Yahoo/AOL',
        'rocketmail.com' => 'Yahoo/AOL',
        'aol.com' => 'Yahoo/AOL',
        'verizon.net' => 'Yahoo/AOL',
        'att.net' => 'Yahoo/AOL',
        'sbcglobal.net' => 'Yahoo/AOL',
        'bellsouth.net' => 'Yahoo/AOL',
        'icloud.com' => 'Apple',
        'me.com' => 'Apple',
        'mac.com' => 'Apple',
        'comcast.net' => 'Comcast',
        'xfinity.com' => 'Comcast',
        'proton.me' => 'Proton',
        'protonmail.com' => 'Proton',
        'zoho.com' => 'Zoho',
        'gmx.com' => 'GMX/Web.de',
        'gmx.de' => 'GMX/Web.de',
        'web.de' => 'GMX/Web.de',
        'mail.com' => 'GMX/Web.de',
        'mail.ru' => 'Mail.ru',
        'yandex.ru' => 'Yandex',
        'cox.net' => 'Cox',
        'charter.net' => 'Spectrum',
        'spectrum.net' => 'Spectrum',
        'roadrunner.com' => 'Spectrum',
        'rr.com' => 'Spectrum',
        'earthlink.net' => 'Earthlink',
        'juno.com' => 'United Online',
        'netzero.net' => 'United Online',
    ];

    /** @var array<string, string> */
    private array $map;

    /**
     * @param array<string, string> $extra additional or overriding domain => provider entries
     */
    public function __construct(array $extra = [], private readonly ?string $source = null)
    {
        $map = self::DEFAULTS;
        foreach ($extra as $domain => $provider) {
            $map[strtolower((string) $domain)] = (string) $provider;
        }
        $this->map = $map;
    }

    /**
     * Load extra mappings from a JSON file: {"domain.tld": "Provider", ...}.
     */
    public static function fromFile(?string $path): self
    {
        if (null === $path || '' === $path) {
            return new self();
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Provider map file "%s" is not readable.', $path));
        }
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('Provider map file "%s" must contain a JSON object.', $path));
        }
        unset($data['$comment']);

        return new self($data, $path);
    }

    public function providerFor(string $domain): string
    {
        $d = strtolower($domain);

        return $this->map[$d] ?? $d;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function count(): int
    {
        return \count($this->map);
    }
}
