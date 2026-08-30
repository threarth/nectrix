<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * INV-EID-05: every scheme declares a versioned normalisation and case-sensitivity policy.
 * There is no universal normalisation: a scheme that is not declared here falls back to the
 * default policy, which only trims and collapses whitespace and is case insensitive.
 */
final class IdentifierNormalizer
{
    private const SCHEME_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const MAX_VALUE_LENGTH = 200;

    /** @var array<string, array<string, mixed>> */
    private const DEFAULT_POLICY = [
        'version' => 1,
        'caseSensitive' => false,
        'removeSpaces' => false,
        'requiresAuthority' => false,
        'padDigits' => 0,
    ];

    /** @var array<string, array<string, mixed>> */
    private const POLICIES = [
        // Un ticker è interpretabile solo dentro il suo exchange, che partecipa all'identità.
        'ticker' => ['version' => 1, 'caseSensitive' => false, 'removeSpaces' => true, 'requiresAuthority' => true, 'padDigits' => 0],
        'lei' => ['version' => 1, 'caseSensitive' => false, 'removeSpaces' => true, 'requiresAuthority' => false, 'padDigits' => 0],
        'isin' => ['version' => 1, 'caseSensitive' => false, 'removeSpaces' => true, 'requiresAuthority' => false, 'padDigits' => 0],
        // Il CIK è numerico e la forma canonica SEC è a dieci cifre con zeri iniziali.
        'cik' => ['version' => 1, 'caseSensitive' => false, 'removeSpaces' => true, 'requiresAuthority' => false, 'padDigits' => 10],
    ];

    /** @return array<string, mixed> */
    public function policy(string $scheme): array
    {
        return self::POLICIES[$scheme] ?? self::DEFAULT_POLICY;
    }

    public function requiresAuthority(string $scheme): bool
    {
        return $this->policy($scheme)['requiresAuthority'] === true;
    }

    public function version(string $scheme): int
    {
        return (int) $this->policy($scheme)['version'];
    }

    public function assertScheme(string $scheme): void
    {
        if (preg_match(self::SCHEME_PATTERN, $scheme) !== 1) {
            throw new ApiException(422, 'invalid_identifier_scheme', 'Lo scheme deve essere una chiave lowercase stabile.');
        }
    }

    /** Value as stored for deduplication and lookup, following the policy of the scheme. */
    public function normalize(string $scheme, string $value): string
    {
        $policy = $this->policy($scheme);
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($normalized === '' || Text::length($normalized) > self::MAX_VALUE_LENGTH) {
            throw new ApiException(422, 'invalid_identifier_value', 'Il valore dell’identificatore non è utilizzabile.');
        }
        if ($policy['removeSpaces'] === true) {
            $normalized = str_replace(' ', '', $normalized);
        }
        if ($policy['caseSensitive'] !== true) {
            $normalized = Text::lower($normalized);
        }
        $padDigits = (int) $policy['padDigits'];
        if ($padDigits > 0 && ctype_digit($normalized)) {
            $normalized = str_pad($normalized, $padDigits, '0', STR_PAD_LEFT);
        }
        return $normalized;
    }
}
