<?php

declare(strict_types=1);

namespace Stewart\Support\Tests\Unit\Secret;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Support\Secret\SecretName;

#[CoversClass(SecretName::class)]
final class SecretNameTest extends TestCase
{
    public function testConfigKeysNamingSecretsAreSecret(): void
    {
        self::assertTrue(SecretName::looksSecret('token'));
        self::assertTrue(SecretName::looksSecret('API_KEY'));
        self::assertFalse(SecretName::looksSecret('design'), 'Config keys do not match the wider URL parameter names.');
    }

    public function testPassCountsOnlyAsAWordOfItsOwn(): void
    {
        self::assertTrue(SecretName::looksSecret('smtp_pass'));
        self::assertTrue(SecretName::looksLikeCredentialParameter('pass'));
        self::assertFalse(SecretName::looksSecret('bypass'));
        self::assertFalse(SecretName::looksLikeCredentialParameter('passive'));
    }

    public function testUrlParametersAlsoCoverSignatures(): void
    {
        self::assertTrue(SecretName::looksLikeCredentialParameter('sig'));
        self::assertTrue(SecretName::looksLikeCredentialParameter('access_token'));
        self::assertFalse(SecretName::looksLikeCredentialParameter('timeout'));
    }
}
