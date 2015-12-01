<?php

namespace Depot\AggregateRoot\ChangeReading;

use DateTimeImmutable;

interface ChangeReader
{
    /**
     * @param object $change
     *
     * @return object
     */
    public function readEvent($change);

    /**
     * @param object $change
     *
     * @return object|null
     */
    public function readMetadata($change);

    /**
     * @param object $change
     *
     * @return bool
     */
    public function canReadEventId($change);

    /**
     * @param object $change
     *
     * @return string|null
     */
    public function readEventId($change);

    /**
     * @param object $change
     *
     * @return bool
     */
    public function canReadEventVersion($change);

    /**
     * @param object $change
     *
     * @return int|null
     */
    public function readEventVersion($change);

    /**
     * @param object $change
     *
     * @return DateTimeImmutable
     */
    public function readWhen($change);
}
