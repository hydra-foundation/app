<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Config\LogConfig;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Log\LogReader;
use Hydra\Log\LogRecord;

/**
 * The end of the log file, as rows. Only the last LOG_READ_BYTES are read, and
 * the filtering and paging happen over those, so the list never costs more
 * than that however large the file grows. A row's id is its byte offset.
 */
final class LogSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface
{
    private const COLUMNS = ['id', 'time', 'level', 'message', 'detail', 'context', 'request_id'];

    public function __construct(private readonly LogConfig $config) {}

    public function page(Criteria $criteria): Page
    {
        if (!$this->config->isFile()) {
            return new Page([], 0, $criteria, 'Logs go to stderr. Set LOG_PATH to a file to read them here.');
        }

        $rows = array_values(array_filter(
            array_map(self::row(...), $this->reader()->latest($this->config->readBytes)),
            static fn (array $row): bool => self::matches($row, $criteria),
        ));

        return new Page(
            array_slice($rows, $criteria->offset(), $criteria->perPage),
            count($rows),
            $criteria,
            'Reading the last ' . self::size($this->config->readBytes) . ' of the log.',
        );
    }

    public function find(string $id): ?array
    {
        if (!$this->config->isFile() || !ctype_digit($id)) {
            return null;
        }

        $record = $this->reader()->at((int) $id);

        return $record === null ? null : self::row($record);
    }

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: $this->config->path,
            columns: self::COLUMNS,
            sortable: [],
            searchable: ['message'],
            filterable: ['level', 'request_id'],
            defaultSort: 'id',
        );
    }

    private function reader(): LogReader
    {
        return new LogReader($this->config->path);
    }

    /** @return array<string, mixed> */
    private static function row(LogRecord $record): array
    {
        return [
            'id' => (string) $record->offset,
            'time' => (string) $record->time->getTimestamp(),
            'level' => $record->level,
            'message' => $record->message,
            'detail' => $record->detail,
            'context' => $record->context === []
                ? null
                : json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'request_id' => $record->requestId,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function matches(array $row, Criteria $criteria): bool
    {
        foreach ($criteria->filters as $column => $value) {
            if ((string) ($row[$column] ?? '') !== $value) {
                return false;
            }
        }

        return $criteria->search === null || stripos((string) $row['message'], $criteria->search) !== false;
    }

    private static function size(int $bytes): string
    {
        $mb = $bytes / 1_048_576;

        return $mb >= 1
            ? ($mb == floor($mb) ? (int) $mb : number_format($mb, 1)) . ' MB'
            : (int) round($bytes / 1024) . ' KB';
    }
}
