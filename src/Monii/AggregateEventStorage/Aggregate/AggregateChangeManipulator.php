<?php

namespace Monii\AggregateEventStorage\Aggregate;

use Monii\AggregateEventStorage\Aggregate\ChangeReading\ChangeReader;
use Monii\AggregateEventStorage\Aggregate\ChangeWriting\ChangeWriter;

class AggregateChangeManipulator implements ChangeReader, ChangeWriter
{
    /**
     * @var ChangeReader
     */
    private $changeReader;

    /**
     * @var ChangeWriter
     */
    private $changeWriter;

    public function __construct(
        ChangeReader $changeReader,
        ChangeWriter $changeWriter
    ) {
        $this->changeReader = $changeReader;
        $this->changeWriter = $changeWriter;
    }

    /**
     * {@inheritdoc}
     */
    public function readEvent($change)
    {
        return $this->changeReader->readEvent($change);
    }

    /**
     * {@inheritdoc}
     */
    public function readMetadata($change)
    {
        return $this->changeReader->readMetadata($change);
    }

    /**
     * {@inheritdoc}
     */
    public function writeChange($event, $metadata = null)
    {
        return $this->changeWriter->writeChange($event, $metadata);
    }
}