<?php

/*
 * ApiSubmitterHasDeletedDuplicateTest.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\Unit\Services\Shared\Import\Routine;

use App\Services\Shared\Import\Routine\ApiSubmitter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers ApiSubmitter::hasDeletedDuplicate(), the pure tri-state decision behind
 * deletedTransactionExistsElsewhere(): a deleted duplicate exists elsewhere / it does not /
 * the API calls needed to tell could not be completed. Pulled out of the method that makes
 * the live API calls specifically so this logic can be tested without one.
 *
 * @internal
 *
 * @coversNothing
 */
final class ApiSubmitterHasDeletedDuplicateTest extends TestCase
{
    private function invokeHasDeletedDuplicate(?int $totalCount, ?int $liveCount): ?bool
    {
        $submitter = new ApiSubmitter();
        $method    = new ReflectionMethod($submitter, 'hasDeletedDuplicate');
        $method->setAccessible(true);

        return $method->invoke($submitter, $totalCount, $liveCount);
    }

    public function testMoreTotalThanLiveMeansADeletedDuplicateExists(): void
    {
        $this->assertTrue($this->invokeHasDeletedDuplicate(2, 1));
    }

    public function testTotalEqualToLiveMeansNoDeletedDuplicate(): void
    {
        $this->assertFalse($this->invokeHasDeletedDuplicate(1, 1));
    }

    public function testZeroTotalMeansNoDeletedDuplicate(): void
    {
        $this->assertFalse($this->invokeHasDeletedDuplicate(0, 0));
    }

    public function testNullTotalCountMeansUnknownNotNo(): void
    {
        // The ledger-wide count call failed: the answer is unknown, never "false".
        $this->assertNull($this->invokeHasDeletedDuplicate(null, 1));
    }

    public function testNullLiveCountMeansUnknownNotNo(): void
    {
        // The ledger-wide live-count call failed: same rule, same reason.
        $this->assertNull($this->invokeHasDeletedDuplicate(1, null));
    }

    public function testBothNullMeansUnknown(): void
    {
        $this->assertNull($this->invokeHasDeletedDuplicate(null, null));
    }
}
