<?php declare(strict_types=1);

namespace Shopware\Core\Content\ProductExport\DataAbstractionLayer;

use Shopware\Core\Content\ProductExport\Exception\DuplicateFileNameException;
use Shopware\Core\Content\ProductExport\ProductExportDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Feature;

class ProductExportExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @internal (flag:FEATURE_NEXT_16640) - second parameter WriteCommand $command will be removed
     */
    public function matchException(\Exception $e, ?WriteCommand $command = null): ?\Exception
    {
        if ($e->getCode() !== 0) {
            return null;
        }
        if (!Feature::isActive('FEATURE_NEXT_16640') && $command->getDefinition()->getEntityName() !== ProductExportDefinition::ENTITY_NAME) {
            return null;
        }

        if (preg_match('/SQLSTATE\[23000\]:.*1062 Duplicate.*file_name\'/', $e->getMessage())) {
            $fileName = '';
            if (!Feature::isActive('FEATURE_NEXT_16640')) {
                $payload = $command->getPayload();
                $fileName = $payload['file_name'] ?? '';
            }

            return new DuplicateFileNameException($fileName, $e);
        }

        return null;
    }

    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }
}
