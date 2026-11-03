<?php

declare(strict_types=1);

namespace App\Shared;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

#[AsMonologProcessor]
final class LogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'request_id' => RequestContext::$id]);
    }
}
