<?php

namespace Depot\Testing\Unit\AggregateRoot;

use DateTimeImmutable;
use Depot\AggregateRoot\ChangeManipulation\ChangeManipulator;
use Depot\AggregateRoot\AggregateRootManipulation\AggregateRootManipulator;
use Depot\AggregateRoot\ChangeManipulation\Reading\PublicMethodsChangeReader;
use Depot\AggregateRoot\AggregateRootManipulation\ChangesClearing\PublicMethodChangesClearor;
use Depot\AggregateRoot\AggregateRootManipulation\ChangesExtraction\PublicMethodChangesExtractor;
use Depot\AggregateRoot\ChangeManipulation\Writing\NamedConstructorChangeWriter;
use Depot\AggregateRoot\AggregateRootManipulation\Identification\PublicMethodIdentifier;
use Depot\AggregateRoot\AggregateRootManipulation\Instantiation\NamedConstructorInstantiator;
use Depot\AggregateRoot\AggregateRootManipulation\Reconstitution\PublicMethodReconstituter;
use Depot\AggregateRoot\UnitOfWork;
use Depot\AggregateRoot\AggregateRootManipulation\VersionReading\PublicMethodVersionReader;
use Depot\Contract\SimplePhpFqcnContractResolver;
use Depot\EventStore\EventEnvelope;
use Depot\EventStore\EventStore;
use Depot\EventStore\Persistence\Adapter\InMemory\InMemoryPersistence;
use Depot\EventStore\Persistence\Persistence;
use Depot\EventStore\Serialization\Adapter\MoniiReflectionPropertiesSerializer\MoniiReflectionPropertiesSerializer;
use Depot\EventStore\Transaction\CommitId;
use Depot\EventStore\Transaction\CommitIdGenerator;
use Depot\Testing\Fixtures\Banking\Account\Account;
use Depot\Testing\Fixtures\Banking\Account\AccountBalanceDecreased;
use Depot\Testing\Fixtures\Banking\Account\AccountBalanceIncreased;
use Depot\Testing\Fixtures\Banking\Account\AccountWasOpened;
use Depot\Testing\Fixtures\Banking\Common\BankingEventEnvelope;
use PHPUnit\Framework\TestCase;

class UnitOfWorkTest extends TestCase
{
    /**
     * @var UnitOfWork
     */
    private $unitOfWork;

    public function setUp()
    {
        parent::setUp();

        $serializer = new MoniiReflectionPropertiesSerializer(
            new SimplePhpFqcnContractResolver()
        );

        $persistence = new InMemoryPersistence($serializer, $serializer);

        $this->loadFixtures($persistence);

        $eventStore = new EventStore($persistence);

        $commitIdGenerator = $this->getMockBuilder(CommitIdGenerator::class)
            ->getMock();

        $commitIdGenerator
            ->expects($this->any())
            ->method('generateCommitId')
            ->will($this->onConsecutiveCalls(
                CommitId::fromString('8126175E-854F-4D9F-A56B-EB3C57C6D8DF'),
                CommitId::fromString('6C13F32A-18F5-44B9-A55D-BFB3B4ED0607'),
                CommitId::fromString('F9310D1A-7D43-47EA-9F70-175D4CDE04F0')
            ));

        $this->unitOfWork = new UnitOfWork(
            $eventStore,
            new AggregateRootManipulator(
                new NamedConstructorInstantiator(),
                new PublicMethodReconstituter(),
                new PublicMethodIdentifier(),
                new PublicMethodVersionReader(),
                new PublicMethodChangesExtractor(),
                new PublicMethodChangesClearor()
            ),
            new ChangeManipulator(
                new PublicMethodsChangeReader(),
                new NamedConstructorChangeWriter(BankingEventEnvelope::class)
            ),
            new SimplePhpFqcnContractResolver(),
            new SimplePhpFqcnContractResolver(),
            null,
            $commitIdGenerator
        );
    }

    public function testTrack()
    {
        $account = Account::open(5, 'fixture-account-201', 25);
        $contract = (new SimplePhpFqcnContractResolver())->resolveFromClassName(Account::class);

        $account->increaseBalance(6006, 100);

        $this->assertcount(2, $account->getAggregateRootChanges());
        $this->assertCount(0, $account->getCommittedEvents());
        $this->assertCount(2, $account->getHandledEvents());

        $this->unitOfWork->track($contract, 'fixture-account-201', $account);

        $this->assertcount(2, $account->getAggregateRootChanges());
        $this->assertCount(0, $account->getCommittedEvents());
        $this->assertCount(2, $account->getHandledEvents());

        $account->decreaseBalance(6007, 50);

        $this->assertcount(3, $account->getAggregateRootChanges());
        $this->assertCount(0, $account->getCommittedEvents());
        $this->assertCount(3, $account->getHandledEvents());

        $this->unitOfWork->commit();
    }

    /**
     * @expectedException \Depot\AggregateRoot\Error\AggregateRootIsAlreadyTracked
     */
    public function testTrackAgainRightAway()
    {
        $account = Account::open(5, 'fixture-account-201', 25);
        $contract = (new SimplePhpFqcnContractResolver())->resolveFromClassName(Account::class);

        $this->unitOfWork->track($contract, 'fixture-account-201', $account);
        $this->unitOfWork->track($contract, 'fixture-account-201', $account);
    }

    /**
     * @expectedException \Depot\EventStore\Persistence\OptimisticConcurrencyFailed
     */
    public function testTrackAgainExistingFixture()
    {
        $account = Account::open(5, 'fixture-account-001', 25);
        $contract = (new SimplePhpFqcnContractResolver())->resolveFromClassName(Account::class);

        $this->unitOfWork->track($contract, 'fixture-account-001', $account);

        $this->unitOfWork->commit();
    }

    /**
     * @param $accountId
     * @param $expectedCommittedEvents
     * @dataProvider provideGetData
     */
    public function testGet($accountId, $expectedCommittedEvents)
    {
        $contract = (new SimplePhpFqcnContractResolver())->resolveFromClassName(Account::class);

        /** @var Account $account */
        $account = $this->unitOfWork->get($contract, $accountId);

        $this->assertEquals($expectedCommittedEvents, $account->getCommittedEvents());
    }

    public function testGetAndCommit()
    {
        $contract = (new SimplePhpFqcnContractResolver())->resolveFromClassName(Account::class);

        /** @var Account $account */
        $account = $this->unitOfWork->get($contract, 'fixture-account-000');

        $this->assertcount(0, $account->getAggregateRootChanges());
        $this->assertCount(2, $account->getCommittedEvents());
        $this->assertCount(2, $account->getHandledEvents());

        $account->increaseBalance(1001, 302);

        $this->assertcount(1, $account->getAggregateRootChanges());
        $this->assertCount(2, $account->getCommittedEvents());
        $this->assertCount(3, $account->getHandledEvents());

        $account->decreaseBalance(1001, 301);

        $this->assertcount(2, $account->getAggregateRootChanges());
        $this->assertCount(2, $account->getCommittedEvents());
        $this->assertCount(4, $account->getHandledEvents());

        $account->decreaseBalance(1001, 201);

        $this->assertcount(3, $account->getAggregateRootChanges());
        $this->assertCount(2, $account->getCommittedEvents());
        $this->assertCount(5, $account->getHandledEvents());

        $this->unitOfWork->commit();

        $this->assertcount(0, $account->getAggregateRootChanges());
        $this->assertCount(5, $account->getCommittedEvents());
        $this->assertCount(5, $account->getHandledEvents());
    }


    protected function createEventEnvelope($eventId, $event, $version, $when = null)
    {
        return new EventEnvelope(
            (new SimplePhpFqcnContractResolver())->resolveFromObject($event),
            $eventId,
            $event,
            $version,
            $when ?: new DateTimeImmutable('2016-01-01 14:55:00')
        );
    }

    protected function createBankingEventEnvelope($eventId, $event, $metadata = null, $when = null)
    {
        return BankingEventEnvelope::create(
            $eventId,
            $event,
            $when ?: new DateTimeImmutable('2016-01-01 14:55:00'),
            $metadata
        );
    }

    protected function createCommit(
        Persistence $persistence,
        $commitId,
        $aggregateClassName,
        $aggregateId,
        $expectedAggregateVersion,
        array $eventEnvelopes
    ) {
        $persistence->commit(
            CommitId::fromString($commitId),
            (new SimplePhpFqcnContractResolver())->resolveFromClassName($aggregateClassName),
            $aggregateId,
            $expectedAggregateVersion,
            $eventEnvelopes
        );
    }

    protected function loadFixtures(Persistence $persistence)
    {
        $this->createCommit(
            $persistence,
            '4A9F269C-27D5-46C2-9FDF-F7A7D61C55D4',
            Account::class,
            'fixture-account-000',
            -1,
            [
                $this->createEventEnvelope(
                    123,
                    new AccountWasOpened('fixture-account-000', 25),
                    0
                ),
            ]
        );

        $this->createCommit(
            $persistence,
            '75BCD437-F184-4305-AB61-784761536783',
            Account::class,
            'fixture-account-001',
            -1,
            [
                $this->createEventEnvelope(
                    124,
                    new AccountWasOpened('fixture-account-001', 10),
                    0
                ),
                $this->createEventEnvelope(
                    125,
                    new AccountBalanceIncreased('fixture-account-001', 15),
                    1
                ),
                $this->createEventEnvelope(
                    126,
                    new AccountBalanceDecreased('fixture-account-001', 5),
                    2
                ),
                $this->createEventEnvelope(
                    127,
                    new AccountBalanceIncreased('fixture-account-001', 45),
                    3
                ),
            ]
        );

        $this->createCommit(
            $persistence,
            '1264416A-7465-4241-A810-B5EFBD1988E2',
            Account::class,
            'fixture-account-000',
            0,
            [
                $this->createEventEnvelope(
                    128,
                    new AccountBalanceIncreased('fixture-account-000', 30),
                    1
                ),
            ]
        );

        $this->createCommit(
            $persistence,
            'D68A5BFD-6A61-44A7-BF10-ECEFE776A141',
            Account::class,
            'fixture-account-001',
            3,
            [
                $this->createEventEnvelope(
                    129,
                    new AccountBalanceDecreased('fixture-account-001', 75),
                    4
                ),
                $this->createEventEnvelope(
                    130,
                    new AccountBalanceIncreased('fixture-account-001', 90),
                    5
                ),
            ]
        );

        $this->createCommit(
            $persistence,
            'A8DA72AB-1405-463A-AF16-BF170A5D304E',
            Account::class,
            'fixture-account-001',
            5,
            [
                $this->createEventEnvelope(
                    131,
                    new AccountBalanceIncreased('fixture-account-001', 125),
                    6
                ),
                $this->createEventEnvelope(
                    132,
                    new AccountBalanceDecreased('fixture-account-001', 15),
                    7
                ),
            ]
        );
    }

    public function provideGetData()
    {
        return [
            [
                'fixture-account-000',
                [
                    $this->createBankingEventEnvelope(
                        123,
                        new AccountWasOpened('fixture-account-000', 25)
                    ),

                    $this->createBankingEventEnvelope(
                        128,
                        new AccountBalanceIncreased('fixture-account-000', 30)
                    ),
                ]
            ],
            [
                'fixture-account-001',
                [
                    $this->createBankingEventEnvelope(
                        124,
                        new AccountWasOpened('fixture-account-001', 10)
                    ),
                    $this->createBankingEventEnvelope(
                        125,
                        new AccountBalanceIncreased('fixture-account-001', 15)
                    ),
                    $this->createBankingEventEnvelope(
                        126,
                        new AccountBalanceDecreased('fixture-account-001', 5)
                    ),
                    $this->createBankingEventEnvelope(
                        127,
                        new AccountBalanceIncreased('fixture-account-001', 45)
                    ),
                    $this->createBankingEventEnvelope(
                        129,
                        new AccountBalanceDecreased('fixture-account-001', 75)
                    ),
                    $this->createBankingEventEnvelope(
                        130,
                        new AccountBalanceIncreased('fixture-account-001', 90)
                    ),
                    $this->createBankingEventEnvelope(
                        131,
                        new AccountBalanceIncreased('fixture-account-001', 125)
                    ),
                    $this->createBankingEventEnvelope(
                        132,
                        new AccountBalanceDecreased('fixture-account-001', 15)
                    ),
                ]
            ]
        ];
    }
}
