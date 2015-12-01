<?php

namespace Depot\AggregateRoot;

use Depot\AggregateRoot\Error\AggregateRootIsAlreadyTracked;
use Depot\Contract\Contract;
use Depot\Contract\ContractResolver;
use Depot\EventStore\EventEnvelope;
use Depot\EventStore\EventIdentity\EventIdGenerator;
use Depot\EventStore\EventStore;
use Depot\EventStore\Transaction\CommitIdGenerator;

class UnitOfWork
{
    /**
     * @var EventStore
     */
    private $eventStore;

    /**
     * @var AggregateRootManipulator
     */
    private $aggregateManipulator;

    /**
     * @var AggregateRootChangeManipulator
     */
    private $aggregateChangeManipulator;

    /**
     * @var ContractResolver
     */
    private $eventContractResolver;

    /**
     * @var EventIdGenerator
     */
    private $eventIdGenerator;

    /**
     * @var CommitIdGenerator
     */
    private $commitIdGenerator;

    /**
     * @var object[]
     */
    private $trackedAggregates = [];

    public function __construct(
        EventStore $eventStore,
        AggregateRootManipulator $aggregateManipulator,
        AggregateRootChangeManipulator $aggregateChangeManipulator,
        ContractResolver $eventContractResolver,
        ContractResolver $metadataContractResolver,
        EventIdGenerator $eventIdGenerator = null,
        CommitIdGenerator $commitIdGenerator = null
    ) {
        $this->eventStore = $eventStore;
        $this->aggregateManipulator = $aggregateManipulator;
        $this->aggregateChangeManipulator = $aggregateChangeManipulator;
        $this->eventContractResolver = $eventContractResolver;
        $this->metadataContractResolver = $metadataContractResolver;
        $this->eventIdGenerator = $eventIdGenerator;
        $this->commitIdGenerator = $commitIdGenerator;
    }

    /**
     * @param Contract $aggregateType
     * @param string $aggregateId
     * @param object $aggregate
     */
    public function track(Contract $aggregateType, $aggregateId, $aggregate)
    {
        $trackedAggregate = $this->findTrackedAggregate($aggregateType, $aggregateId);

        if (! is_null($trackedAggregate)) {
            throw new AggregateRootIsAlreadyTracked();
        }

        $this->ensureTrackedAggregateTypeIsPrepared($aggregateType);

        $this->trackedAggregates[$aggregateType->getContractName()]['aggregates'][] = $aggregate;
    }

    private function ensureTrackedAggregateTypeIsPrepared(Contract $aggregateType)
    {
        if ($this->isTrackedAggregateTypeIsPrepared($aggregateType)) {
            return;
        }

        $this->trackedAggregates[$aggregateType->getContractName()] = [
            'contract' => $aggregateType,
            'aggregates' => [],
        ];
    }

    private function isTrackedAggregateTypeIsPrepared(Contract $aggregateType)
    {
        return array_key_exists($aggregateType->getContractName(), $this->trackedAggregates);
    }

    public function commit()
    {
        foreach ($this->trackedAggregates as $trackedAggregateTypes) {
            $aggregateType = $trackedAggregateTypes['contract'];
            foreach ($trackedAggregateTypes['aggregates'] as $aggregate) {
                $this->persist(
                    $aggregateType,
                    $this->aggregateManipulator->identify($aggregate),
                    $aggregate
                );
            }
        }
    }

    private function persist(Contract $aggregateType, $aggregateId, $aggregate)
    {
        $this->ensureTrackedAggregateTypeIsPrepared($aggregateType);

        $changes = $this->aggregateManipulator->extractChanges($aggregate);

        if (empty($changes)) {
            return;
        }

        $initialAggregateVersion = $this->aggregateManipulator->readVersion($aggregate) - count($changes);
        $aggregateVersion = $initialAggregateVersion;

        $eventEnvelopes = [];
        foreach ($changes as $change) {
            $aggregateVersion++;

            $eventId = $this->aggregateChangeManipulator->canReadEventId($change)
                ? $this->aggregateChangeManipulator->readEventId($change)
                : $this->eventIdGenerator->generateEventId();
            $event = $this->aggregateChangeManipulator->readEvent($change);
            $metadata = $this->aggregateChangeManipulator->readMetadata($change);
            $version = $this->aggregateChangeManipulator->canReadEventVersion($change)
                ? $this->aggregateChangeManipulator->readEventVersion($change)
                : $aggregateVersion;
            $when = $this->aggregateChangeManipulator->readWhen($change);

            $eventEnvelopes[] = new EventEnvelope(
                $this->eventContractResolver->resolveFromObject($event),
                $eventId,
                $event,
                $version,
                $when,
                $this->metadataContractResolver->resolveFromObject($metadata),
                $metadata
            );
        }

        $eventStream = $initialAggregateVersion === -1
            ? $this->eventStore->create($aggregateType, $aggregateId)
            : $this->eventStore->open($aggregateType, $aggregateId);

        $eventStream->appendAll($eventEnvelopes);
        $eventStream->commit($this->commitIdGenerator->generateCommitId());

        $this->aggregateManipulator->clearChanges($aggregate);
    }

    /**
     * @param Contract $aggregateType
     * @param string $aggregateId
     *
     * @return null|object
     */
    public function get(Contract $aggregateType, $aggregateId)
    {
        $trackedAggregate = $this->findTrackedAggregate($aggregateType, $aggregateId);

        if (! is_null($trackedAggregate)) {
            return $trackedAggregate;
        }

        $aggregate = $this->findAndTrackPersistedAggregate($aggregateType, $aggregateId);

        return $aggregate;
    }

    /**
     * @param Contract $aggregateType
     * @param string $aggregateId
     *
     * @return mixed
     */
    private function findTrackedAggregate(Contract $aggregateType, $aggregateId)
    {
        $contractName = $aggregateType->getContractName();

        if (! array_key_exists($contractName, $this->trackedAggregates)) {
            return null;
        }

        foreach ($this->trackedAggregates[$contractName]['aggregates'] as $trackedAggregate) {
            $trackedAggregateId = $this->aggregateManipulator->identify($trackedAggregate);
            if ($trackedAggregateId == $aggregateId) {
                return $trackedAggregate;
            }
        }

        return null;
    }

    /**
     * @param Contract $aggregateType
     * @param string $aggregateId
     *
     * @return object
     */
    private function findAndTrackPersistedAggregate(Contract $aggregateType, $aggregateId)
    {
        $aggregate = $this->findPersistedAggregate($aggregateType, $aggregateId);

        return $aggregate;
    }

    /**
     * @param Contract $aggregateType
     * @param string $aggregateId
     *
     * @return object
     */
    private function findPersistedAggregate(Contract $aggregateType, $aggregateId)
    {
        $eventStream = $this->eventStore->open(
            $aggregateType,
            $aggregateId
        );

        $events = array_map(function (EventEnvelope $eventEnvelope) {
            return $this->aggregateChangeManipulator->writeChange(
                $eventEnvelope->getEventId(),
                $eventEnvelope->getEvent(),
                $eventEnvelope->getWhen(),
                $eventEnvelope->getMetadata(),
                $eventEnvelope->getVersion()
            );
        }, $eventStream->all());

        if (! count($events)) {
            return null;
        }

        $aggregate = $this->instantiateAggregate($aggregateType);
        $this->reconstituteAggregate($aggregate, $events);
        $this->track($aggregateType, $aggregateId, $aggregate);

        return $aggregate;
    }

    private function instantiateAggregate(Contract $aggregateType)
    {
        return $this->aggregateManipulator
            ->instantiateForReconstitution($aggregateType->getClassName())
        ;
    }

    private function reconstituteAggregate($aggregate, array $events)
    {
        $this->aggregateManipulator
            ->reconstitute($aggregate, $events)
        ;
    }
}
