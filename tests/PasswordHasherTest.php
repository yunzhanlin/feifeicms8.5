<?php
declare(strict_types=1);

namespace tests;

use app\service\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testItAcceptsLegacyMd5AndRequestsUpgrade(): void
    {
        $hasher = new PasswordHasher();
        self::assertTrue($hasher->verify(md5('admin888'), 'admin888'));
        self::assertFalse($hasher->verify(md5('admin888'), 'wrong'));
        self::assertTrue($hasher->needsRehash(md5('admin888')));
    }

    public function testItCreatesModernPasswordHashes(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('a-long-password');
        self::assertTrue($hasher->verify($hash, 'a-long-password'));
        self::assertFalse($hasher->verify($hash, 'wrong'));
        self::assertFalse($hasher->needsRehash($hash));
    }
}
