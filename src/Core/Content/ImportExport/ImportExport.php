<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport;

use League\Flysystem\FilesystemInterface;
use Shopware\Core\Content\ImportExport\Aggregate\ImportExportLog\ImportExportLogEntity;
use Shopware\Core\Content\ImportExport\Event\EnrichExportCriteriaEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportAfterImportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeExportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeImportRecordEvent;
use Shopware\Core\Content\ImportExport\Event\ImportExportExceptionImportRecordEvent;
use Shopware\Core\Content\ImportExport\Exception\ProcessingException;
use Shopware\Core\Content\ImportExport\Processing\Mapping\CriteriaBuilder;
use Shopware\Core\Content\ImportExport\Processing\Pipe\AbstractPipe;
use Shopware\Core\Content\ImportExport\Processing\Reader\AbstractReader;
use Shopware\Core\Content\ImportExport\Processing\Writer\AbstractWriter;
use Shopware\Core\Content\ImportExport\Service\ImportExportService;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ImportExport
{
    private const PART_FILE_SUFFIX = '.offset_';

    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * @var AbstractPipe
     */
    private $pipe;

    /**
     * @var AbstractReader
     */
    private $reader;

    /**
     * @var AbstractWriter
     */
    private $writer;

    /**
     * @var ImportExportLogEntity
     */
    private $logEntity;

    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @var int
     */
    private $importLimit;

    /**
     * @var int
     */
    private $exportLimit;

    /**
     * @var int|null
     */
    private $total;

    /**
     * @var ImportExportService
     */
    private $importExportService;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(
        ImportExportService $importExportService,
        ImportExportLogEntity $logEntity,
        FilesystemInterface $filesystem,
        EventDispatcherInterface $eventDispatcher,
        EntityRepositoryInterface $repository,
        AbstractPipe $pipe,
        AbstractReader $reader,
        AbstractWriter $writer,
        int $importLimit = 250,
        int $exportLimit = 250
    ) {
        $this->logEntity = $logEntity;
        $this->filesystem = $filesystem;
        $this->repository = $repository;
        $this->writer = $writer;
        $this->pipe = $pipe;
        $this->reader = $reader;
        $this->importExportService = $importExportService;
        $this->eventDispatcher = $eventDispatcher;
        $this->importLimit = $importLimit;
        $this->exportLimit = $exportLimit;
    }

    public function import(Context $context, int $offset = 0): Progress
    {
        $progress = $this->importExportService->getProgress($this->logEntity->getId(), $offset);
        $progress->setTotal($this->logEntity->getFile()->getSize());

        if ($progress->isFinished()) {
            return $progress;
        }

        $processed = 0;

        $path = $this->logEntity->getFile()->getPath();
        $progress->setTotal($this->filesystem->getSize($path));
        $invalidRecordsProgress = null;

        $failedRecords = [];

        $resource = $this->filesystem->readStream($path);
        $config = Config::fromLog($this->logEntity);

        foreach ($this->reader->read($config, $resource, $offset) as $row) {
            $record = [];

            foreach ($this->pipe->out($config, $row) as $key => $value) {
                $record[$key] = $value;
            }

            if (empty($record)) {
                continue;
            }

            try {
                $record = $this->ensurePrimaryKeys($record);

                $event = new ImportExportBeforeImportRecordEvent($record, $row, $config, $context);
                $this->eventDispatcher->dispatch($event);

                $record = $event->getRecord();

                $this->ensureRequiredFields($record, $config);

                $result = $this->repository->upsert([$record], $context);
                $progress->addProcessedRecords(1);

                $afterRecord = new ImportExportAfterImportRecordEvent($result, $record, $row, $config, $context);
                $this->eventDispatcher->dispatch($afterRecord);
            } catch (\Throwable $exception) {
                $event = new ImportExportExceptionImportRecordEvent($exception, $record, $row, $config, $context);
                $this->eventDispatcher->dispatch($event);

                $exception = $event->getException();

                if ($exception) {
                    $record['_error'] = mb_convert_encoding($exception->getMessage(), 'UTF-8', 'UTF-8');
                    $failedRecords[] = $record;
                }
            }
            $this->importExportService->saveProgress($progress);

            ++$processed;
            if ($this->importLimit > 0 && $processed >= $this->importLimit) {
                break;
            }
        }
        $progress->setOffset($this->reader->getOffset());

        if (!empty($failedRecords)) {
            $invalidRecordsProgress = $this->exportInvalid($context, $failedRecords);
            $progress->setInvalidRecordsLogId($invalidRecordsProgress->getLogId());
        }

        // importing the file is complete
        if ($this->reader->getOffset() === $this->filesystem->getSize($path)) {
            if ($this->logEntity->getInvalidRecordsLog() !== null) {
                $invalidLog = $this->logEntity->getInvalidRecordsLog();
                $invalidRecordsProgress = $invalidRecordsProgress
                    ?? $this->importExportService->getProgress($invalidLog->getId(), $invalidLog->getRecords());

                // complete invalid records export
                $this->mergePartFiles($this->logEntity->getInvalidRecordsLog(), $invalidRecordsProgress);

                $invalidRecordsProgress->setState(Progress::STATE_SUCCEEDED);
                $this->importExportService->saveProgress($invalidRecordsProgress);
            }

            $progress->setState($invalidRecordsProgress === null ? Progress::STATE_SUCCEEDED : Progress::STATE_FAILED);
        }
        $this->importExportService->saveProgress($progress);

        return $progress;
    }

    public function export(Context $context, ?Criteria $criteria = null, int $offset = 0): Progress
    {
        $progress = $this->importExportService->getProgress($this->logEntity->getId(), $offset);

        if ($progress->isFinished()) {
            return $progress;
        }

        $config = Config::fromLog($this->logEntity);
        $criteriaBuilder = new CriteriaBuilder($this->repository->getDefinition());

        $criteria = $criteria === null ? new Criteria() : clone $criteria;
        $criteriaBuilder->enrichCriteria($config, $criteria);

        $enrichEvent = new EnrichExportCriteriaEvent($criteria, $this->logEntity);
        $this->eventDispatcher->dispatch($enrichEvent);

        if ($criteria->getSorting() === []) {
            // default sorting
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        }

        $criteria->setOffset($offset);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        $criteria->setLimit($this->exportLimit <= 0 ? 250 : $this->exportLimit);
        $fullExport = $this->exportLimit === null || $this->exportLimit <= 0;

        $targetFile = $this->getPartFilePath($this->logEntity->getFile()->getPath(), $offset);

        do {
            $result = $this->repository->search($criteria, $context);
            if ($this->total === null) {
                $this->total = $result->getTotal();
                $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_NONE);
            }

            $entities = $result->getEntities();
            if (\count($entities) === 0) {
                // this can happen if entities are delete while we export
                $progress->setTotal($progress->getOffset());

                break;
            }

            $progress = $this->exportChunk($config, $entities, $progress, $targetFile);

            $criteria->setOffset($criteria->getOffset() + $criteria->getLimit());
        } while ($fullExport && $progress->getOffset() < $progress->getTotal());

        if ($progress->getTotal() > $progress->getOffset()) {
            return $progress;
        }

        $this->writer->finish($config, $targetFile);

        return $this->mergePartFiles($this->logEntity, $progress);
    }

    public function getLogEntity(): ImportExportLogEntity
    {
        return $this->logEntity;
    }

    private function getPartFilePath(string $targetPath, int $offset): string
    {
        return $targetPath . self::PART_FILE_SUFFIX . $offset;
    }

    /**
     * flysystem does not support appending to existing files. Therefore we need to export multiple files and merge them
     * into the complete export file at the end.
     */
    private function mergePartFiles(ImportExportLogEntity $logEntity, Progress $progress): Progress
    {
        $progress->setState(Progress::STATE_MERGING_FILES);
        $this->importExportService->saveProgress($progress);

        $tmpFile = tempnam(sys_get_temp_dir(), '');
        $tmp = fopen($tmpFile, 'w+b');

        $target = $logEntity->getFile()->getPath();

        $dir = \dirname($target);

        $partFilePrefix = $target . self::PART_FILE_SUFFIX;

        $partFiles = [];

        foreach ($this->filesystem->listContents($dir) as $meta) {
            if ($meta['type'] !== 'file'
                || $meta['path'] === $target
                || strpos($meta['path'], $partFilePrefix) !== 0) {
                continue;
            }

            $partFiles[] = $meta['path'];
        }

        // sort by offset
        natsort($partFiles);

        // concatenate all part files into a temporary file
        foreach ($partFiles as $partFile) {
            if (stream_copy_to_stream($this->filesystem->readStream($partFile), $tmp) === false) {
                throw new ProcessingException('Failed to merge files');
            }
        }

        // copy final file into filesystem
        $this->filesystem->putStream($target, $tmp);

        if (\is_resource($tmp)) {
            fclose($tmp);
        }
        unlink($tmpFile);

        foreach ($partFiles as $p) {
            $this->filesystem->delete($p);
        }

        $progress->setState(Progress::STATE_SUCCEEDED);
        $this->importExportService->saveProgress($progress);

        $this->importExportService->updateFile(
            Context::createDefaultContext(),
            $logEntity->getFileId(),
            ['size' => $this->filesystem->getSize($target)]
        );

        return $progress;
    }

    private function exportChunk(Config $config, iterable $records, Progress $progress, string $targetFile): Progress
    {
        $exportedRecords = 0;
        $offset = $progress->getOffset();
        /** @var Entity|array $originalRecord */
        foreach ($records as $originalRecord) {
            $originalRecord = $originalRecord instanceof Entity
                ? $originalRecord->jsonSerialize()
                : $originalRecord;

            $record = [];
            foreach ($this->pipe->in($config, $originalRecord) as $key => $value) {
                $record[$key] = $value;
            }

            if ($record !== []) {
                $event = new ImportExportBeforeExportRecordEvent($config, $record, $originalRecord);
                $this->eventDispatcher->dispatch($event);

                $record = $event->getRecord();

                $this->writer->append($config, $record, $offset);
                ++$exportedRecords;
            }

            ++$offset;
        }

        $this->writer->flush($config, $targetFile);

        $progress->setState(Progress::STATE_PROGRESS);
        $progress->setOffset($offset);
        $progress->setTotal($this->total);
        $progress->addProcessedRecords($exportedRecords);

        $this->importExportService->saveProgress($progress);

        return $progress;
    }

    /**
     * In case we failed to import some invalid records, we export them as a new csv with the same format and
     * an additional _error column.
     */
    private function exportInvalid(Context $context, array $failedRecords): Progress
    {
        // created a invalid records export if it doesn't exist
        if (!$this->logEntity->getInvalidRecordsLogId()) {
            $pathInfo = pathinfo($this->logEntity->getFile()->getOriginalName());
            $newName = $pathInfo['filename'] . '_failed.' . $pathInfo['extension'];

            $newPath = $this->logEntity->getFile()->getPath() . '_invalid';

            $config = $this->logEntity->getConfig();
            $config['mapping'][] = [
                'key' => '_error',
                'mappedKey' => '_error',
            ];
            $config = new Config($config['mapping'], $config['parameters'] ?? []);

            $failedImportLogEntity = $this->importExportService->prepareExport(
                $context,
                $this->logEntity->getProfileId(),
                $this->logEntity->getFile()->getExpireDate(),
                $newName,
                $config->jsonSerialize(),
                $newPath,
                ImportExportLogEntity::ACTIVITY_INVALID_RECORDS_EXPORT
            );

            $this->logEntity->setInvalidRecordsLog($failedImportLogEntity);
            $this->logEntity->setInvalidRecordsLogId($failedImportLogEntity->getId());
        }

        $failedImportLogEntity = $this->logEntity->getInvalidRecordsLog();
        $config = Config::fromLog($failedImportLogEntity);

        $offset = $failedImportLogEntity->getRecords();

        $targetFile = $this->getPartFilePath($failedImportLogEntity->getFile()->getPath(), $offset);

        $progress = $this->importExportService->getProgress($failedImportLogEntity->getId(), $offset);

        $progress = $this->exportChunk(
            $config,
            $failedRecords,
            $progress,
            $targetFile
        );

        return $progress;
    }

    private function ensurePrimaryKeys(array $data): array
    {
        foreach ($this->repository->getDefinition()->getPrimaryKeys() as $primaryKey) {
            if (!($primaryKey instanceof IdField)) {
                continue;
            }

            if (!isset($data[$primaryKey->getPropertyName()])) {
                $data[$primaryKey->getPropertyName()] = Uuid::randomHex();
            }
        }

        return $data;
    }

    private function ensureRequiredFields(array $record, Config $config): void
    {
        $mappings = $config->getMapping()->getElements();

        foreach ($mappings as $mapping) {
            $transformedMappingName = $this->transformMappingName();
        }
    }

    private function transformMappingName(string $name): string
    {
        $nameWithoutDEFAULT = preg_replace('/DEFAULT/g', '', $name);
    }
}
