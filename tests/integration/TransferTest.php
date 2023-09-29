<?php

declare(strict_types=1);

namespace RefRing\MoneroRpcPhp\Tests\integration;

use Http\Discovery\Psr18ClientDiscovery;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use RefRing\MoneroRpcPhp\DaemonRpcClient;
use RefRing\MoneroRpcPhp\Exception\InvalidAddressException;
use RefRing\MoneroRpcPhp\Exception\InvalidDestinationException;
use RefRing\MoneroRpcPhp\Model\Recipient;
use RefRing\MoneroRpcPhp\Tests\TestHelper;
use RefRing\MoneroRpcPhp\WalletRpc\TransferResponse;
use RefRing\MoneroRpcPhp\WalletRpcClient;

final class TransferTest extends TestCase
{
    /**
     * @var int
     */
    public const BLOCKS_TO_GENERATE = 10;

    public static array $seeds = [TestHelper::WALLET_1_MNEMONIC];

    public static array $wallets = [];

    private static DaemonRpcClient $daemonRpcClient;
    private static WalletRpcClient $walletRpcClient;

    public static function tearDownAfterClass(): void
    {
        // Reset the blockchain
        $height = self::$daemonRpcClient->getHeight();
        self::$daemonRpcClient->popBlocks($height->height - 1);
        self::$daemonRpcClient->flushTxpool();
    }

    public static function setUpBeforeClass(): void
    {
        $httpClient = Psr18ClientDiscovery::find();
        self::$daemonRpcClient = new DaemonRpcClient($httpClient, TestHelper::DAEMON_RPC_URL);
        self::$walletRpcClient = new WalletRpcClient($httpClient, TestHelper::WALLET_RPC_URL);
        foreach (self::$seeds as $seed) {
            self::$wallets[] = self::$walletRpcClient->restoreDeterministicWallet('', '', $seed);
        }

        self::$daemonRpcClient->generateBlocks(100, TestHelper::MAINNET_ADDRESS_1);
    }

    public function testWallet(): void
    {
        $this->assertSame(self::$seeds[0], self::$wallets[0]->seed);
        self::$walletRpcClient->refresh();

        $result = self::$walletRpcClient->getBalance(0);
        $this->assertSame(59, $result->blocksToUnlock);
    }

    public function testTransferEmptyDestination(): void
    {
        $this->expectException(InvalidDestinationException::class);
        self::$walletRpcClient->transfer([]);
    }

    public function testTransferInvalidDestination(): void
    {
        $this->expectException(InvalidAddressException::class);
        self::$walletRpcClient->transfer(new Recipient(TestHelper::TESTNET_ADDRESS_1, 100));
    }

    public function testTransfer(): TransferResponse
    {
        $result = self::$walletRpcClient->transfer(new Recipient(TestHelper::MAINNET_ADDRESS_1, 50000), getTxKey: false, getTxHex: true);

        $this->assertSame(64, strlen($result->txHash));
        $this->assertSame(0, strlen($result->txKey));
        $this->assertGreaterThan(0, $result->amount);
        $this->assertGreaterThan(0, $result->fee);
        $this->assertGreaterThan(0, strlen($result->txBlob));
        $this->assertSame(0, strlen($result->txMetadata));
        $this->assertSame(0, strlen($result->multisigTxset));
        $this->assertSame(0, strlen($result->unsignedTxset));

        return $result;
    }

    #[Depends('testTransfer')]
    public function testFee(TransferResponse $transferResponse): void
    {
        $fee = $transferResponse->fee;
        $txWeight = $transferResponse->weight;

        $result = self::$daemonRpcClient->getFeeEstimate(10);
        $this->assertGreaterThan(0, $result->fee);
        $this->assertGreaterThan(0, $result->quantizationMask);

        $expectedFee = ($result->fee * 1 * $txWeight + $result->quantizationMask - 1);
        $this->assertLessThan(0.01, abs(1 - $fee / $expectedFee));
    }

    #[Depends('testTransfer')]
    public function testGetTransfers(TransferResponse $transferResponse): void
    {
        self::$walletRpcClient->refresh();

        $height = self::$daemonRpcClient->getHeight()->height;

        $result = self::$walletRpcClient->getTransfers(true, true, true, true, true);

        $this->assertSame($height - 1, count($result->in));
    }
}
