<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\support;

use pocketmine\form\Form;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use pocketmine\world\Position;
use PHPUnit\Framework\TestCase;

/** @phpstan-require-extends TestCase */
trait CreatesFakeClients
{
    /** A mocked player whose packets and forms go to a FakeClient. */
    protected function fakeClient(): FakeClient
    {
        return new FakeClient(fn(FakeClient $client): Player => $this->mockPlayer($client));
    }

    private function mockPlayer(FakeClient $client): Player
    {
        $session = $this->getMockBuilder(NetworkSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendDataPacket'])
            ->getMock();
        $session->method('sendDataPacket')->willReturnCallback(static function (ClientboundPacket $packet) use ($client): bool {
            $client->receive($packet);
            return true;
        });
        $player = $this->getMockBuilder(TestPlayer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getNetworkSession', 'getPosition', 'isConnected', 'sendForm', 'closeAllForms', 'getName'])
            ->getMock();
        $player->method('getNetworkSession')->willReturn($session);
        $player->method('getPosition')->willReturn(new Position(0, 0, 0, null));
        $player->method('isConnected')->willReturnCallback(static fn(): bool => $client->connected);
        $player->method('getName')->willReturn('Tester');
        $player->method('sendForm')->willReturnCallback(static function (Form $form) use ($client): void {
            $client->forms[] = $form;
        });
        $player->method('closeAllForms')->willReturnCallback(static function () use ($client): void {
            ++$client->formsClosed;
        });
        return $player;
    }

    protected static function assertCleanProtocol(FakeClient $client): void
    {
        TestCase::assertSame([], $client->errors, 'Client saw protocol errors');
    }
}
