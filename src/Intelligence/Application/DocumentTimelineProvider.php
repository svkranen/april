<?php

namespace App\Intelligence\Application;

interface DocumentTimelineProvider
{
    public function build(string $documentUuid, EventTimelineOrder $order = EventTimelineOrder::DEFAULT): DocumentTimelineReport;
}
