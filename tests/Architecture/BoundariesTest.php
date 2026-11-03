<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BoundariesTest extends TestCase
{
    public function testDomainDoesNotDependOnFrameworkPersistenceOrHttp(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));
        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || !str_contains($file->getPathname(), '/Domain/') || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertStringContainsString('declare(strict_types=1);', $source, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/(?:Doctrine\\\\|Symfony\\\\|App\\\\Shared\\\\(?:Store|ApiError|Input)|App\\\\\\w+\\\\(?:Infrastructure|Presentation)|\\$_(?:ENV|SERVER|GET|POST))/', $source, $file->getPathname());
            ++$checked;
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testTransactionalUseCasesDependOnPorts(): void
    {
        foreach (['Appointment/Bookings.php', 'Billing/Billing.php'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 2).'/src/'.$path);
            self::assertDoesNotMatchRegularExpression('/(?:Doctrine\\\\|Symfony\\\\|App\\\\Shared\\\\Store|->db\\b|SELECT |INSERT INTO|UPDATE .* SET)/', $source, $path);
        }
    }
}
