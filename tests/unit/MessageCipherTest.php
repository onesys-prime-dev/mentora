<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Messaging\MessageCipher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Messages;

/**
 * @internal
 */
final class MessageCipherTest extends CIUnitTestCase
{
    private const KEY_A = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';
    private const KEY_B = 'hex2bin:6262626262626262626262626262626262626262626262626262626262626262';

    private function cipher(string $key = self::KEY_A, string $previous = ''): MessageCipher
    {
        $config                = new Messages();
        $config->encryptionKey = $key;
        $config->previousKeys  = $previous;

        return new MessageCipher($config);
    }

    public function testRoundTrip(): void
    {
        $cipher = $this->cipher();

        $this->assertSame('hello', $cipher->decrypt($cipher->encrypt('hello', 5, 9), 5, 9));
    }

    public function testSameTextEncryptsDifferentlyEachTime(): void
    {
        $cipher = $this->cipher();

        $this->assertNotSame($cipher->encrypt('hello', 5, 9), $cipher->encrypt('hello', 5, 9));
    }

    public function testStoredStringContainsNoPlaintext(): void
    {
        $this->assertStringNotContainsString('hello', $this->cipher()->encrypt('hello', 5, 9));
    }

    public function testTamperedCiphertextIsUnreadable(): void
    {
        $cipher         = $this->cipher();
        $payload        = base64_decode($cipher->encrypt('hello', 5, 9), true);
        $last           = strlen($payload) - 1;
        $payload[$last] = chr(ord($payload[$last]) ^ 1);

        $this->assertNull($cipher->decrypt(base64_encode($payload), 5, 9));
    }

    public function testCiphertextMovedToAnotherConversationOrSenderIsUnreadable(): void
    {
        $cipher = $this->cipher();
        $stored = $cipher->encrypt('hello', 5, 9);

        $this->assertNull($cipher->decrypt($stored, 6, 9));
        $this->assertNull($cipher->decrypt($stored, 5, 8));
    }

    public function testAPreviousKeyStillDecrypts(): void
    {
        $old    = $this->cipher(self::KEY_A)->encrypt('hello', 5, 9);
        $rolled = $this->cipher(self::KEY_B, self::KEY_A);

        $this->assertSame('hello', $rolled->decrypt($old, 5, 9));

        $fresh = $rolled->encrypt('hello', 5, 9);
        $this->assertSame('hello', $this->cipher(self::KEY_B)->decrypt($fresh, 5, 9));
        $this->assertNull($this->cipher(self::KEY_A)->decrypt($fresh, 5, 9));
    }

    public function testShortOrMalformedPreviousKeysAreDroppedAndCounted(): void
    {
        $short = 'hex2bin:' . bin2hex(str_repeat('a', 16));
        $cipher = $this->cipher(self::KEY_B, $short . ', hex2bin:zz,' . self::KEY_A . ',');

        $this->assertSame(2, $cipher->droppedPreviousKeys());

        $old = $this->cipher(self::KEY_A)->encrypt('hello', 5, 9);
        $this->assertSame('hello', $cipher->decrypt($old, 5, 9));

        $this->assertSame(0, $this->cipher(self::KEY_B, '')->droppedPreviousKeys());
    }

    public function testUsesCurrentKeyOnlyForValuesTheCurrentKeyOpens(): void
    {
        $old     = $this->cipher(self::KEY_A)->encrypt('hello', 5, 9);
        $rotated = $this->cipher(self::KEY_B, self::KEY_A);
        $fresh   = $rotated->encrypt('hello', 5, 9);

        $this->assertTrue($rotated->usesCurrentKey($fresh));
        $this->assertSame('hello', $rotated->decrypt($old, 5, 9), 'the previous key still opens it');
        $this->assertFalse($rotated->usesCurrentKey($old), 'but it is not on the current key');
        $this->assertFalse($rotated->usesCurrentKey('not base64 at all !!'));
        $this->assertFalse($rotated->usesCurrentKey(base64_encode('short')));
        $this->assertFalse($this->cipher('')->usesCurrentKey($fresh), 'no usable key');
    }

    public function testNoKeyFailsClosed(): void
    {
        $cipher = $this->cipher('');

        $this->assertFalse($cipher->isUsable());
        $this->assertNull($cipher->decrypt('anything', 5, 9));

        $this->expectException(\RuntimeException::class);
        $cipher->encrypt('hello', 5, 9);
    }

    public function testShortKeyIsNotUsable(): void
    {
        foreach (['hex2bin:' . bin2hex(str_repeat('a', 16)), 'hex2bin:zz'] as $key) {
            $cipher = $this->cipher($key);
            $this->assertFalse($cipher->isUsable());

            try {
                $cipher->encrypt('hello', 5, 9);
                $this->fail('encrypt should throw for an unusable key');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @dataProvider unusualBodies
     */
    public function testUnusualBodiesRoundTrip(string $body): void
    {
        $cipher = $this->cipher();

        $this->assertSame($body, $cipher->decrypt($cipher->encrypt($body, 1, 2), 1, 2));
    }

    public static function unusualBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'long accented' => [str_repeat('é', 4000)];
        yield 'emoji' => ['👩‍🏫 ok'];
        yield 'hebrew' => ['שלום עולם'];
        yield 'newline and script' => ["line\n</script>"];
    }
}
