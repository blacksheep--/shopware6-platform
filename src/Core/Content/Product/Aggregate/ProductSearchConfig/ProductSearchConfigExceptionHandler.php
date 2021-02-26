<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\Aggregate\ProductSearchConfig;

use Shopware\Core\Content\Product\Exception\DuplicateProductSearchConfigLanguageException;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\ExceptionHandlerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\Feature;

class ProductSearchConfigExceptionHandler implements ExceptionHandlerInterface
{
    public function getPriority(): int
    {
        return ExceptionHandlerInterface::PRIORITY_DEFAULT;
    }

    /**
     * @internal (flag:FEATURE_NEXT_16640) - second parameter WriteCommand $command will be removed
     */
    public function matchException(\Exception $e, ?WriteCommand $command = null): ?\Exception
    {
        if ($e->getCode() !== 0) {
            return null;
        }
        if (!Feature::isActive('FEATURE_NEXT_16640') && $command->getDefinition()->getEntityName() !== ProductSearchConfigDefinition::ENTITY_NAME) {
            return null;
        }

        if (preg_match('/SQLSTATE\[23000\]:.*1062 Duplicate.*uniq.product_search_config.language_id\'/', $e->getMessage())) {
            $languageId = '';
            if (!Feature::isActive('FEATURE_NEXT_16640')) {
                $payload = $command->getPayload();
                $languageId = $payload['language_id'] ?? '';
            }

            return new DuplicateProductSearchConfigLanguageException($languageId, $e);
        }

        return null;
    }
}
