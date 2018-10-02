<?php declare(strict_types=1);

namespace Shopware\Core\Framework\ORM\Write\IdGenerator;

interface Generator
{
    public function create(): string;

    public function toStorageValue(string $value): string;
}
