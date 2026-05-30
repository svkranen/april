<?php

namespace App\Intelligence\Application;

interface EventDetailsProvider
{
    public function find(int $eventId): ?EventDetails;
}
