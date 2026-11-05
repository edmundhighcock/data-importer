<?php

/*
 * ApiSubmitterOwnAccountIdTest.php
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
 * Covers ApiSubmitter::ownAccountId(), which decides which asset account a line's
 * own leg belongs to, so account-scoped "cell" duplicate detection can be scoped to it.
 *
 * @internal
 *
 * @coversNothing
 */
final class ApiSubmitterOwnAccountIdTest extends TestCase
{
    private function callOwnAccountId(array $transaction): ?int
    {
        $submitter = new ApiSubmitter();
        $method    = new ReflectionMethod($submitter, 'ownAccountId');
        $method->setAccessible(true);

        return $method->invoke($submitter, $transaction);
    }

    public function testWithdrawalUsesSourceId(): void
    {
        $transaction = ['type' => 'withdrawal', 'source_id' => '7', 'destination_id' => null];
        $this->assertSame(7, $this->callOwnAccountId($transaction));
    }

    public function testTransferUsesSourceId(): void
    {
        // A transfer between two of the user's own asset accounts: the account whose
        // feed generated the line is consistently the FROM side (source_id), for both
        // the sending and the receiving account's own copy of the same event.
        $transaction = ['type' => 'transfer', 'source_id' => '5', 'destination_id' => '6'];
        $this->assertSame(5, $this->callOwnAccountId($transaction));
    }

    public function testDepositUsesDestinationId(): void
    {
        $transaction = ['type' => 'deposit', 'source_id' => null, 'destination_id' => '42'];
        $this->assertSame(42, $this->callOwnAccountId($transaction));
    }

    public function testUnknownTypeReturnsNull(): void
    {
        $transaction = ['type' => 'opening-balance', 'source_id' => '1', 'destination_id' => '2'];
        $this->assertNull($this->callOwnAccountId($transaction));
    }

    public function testMissingTypeReturnsNull(): void
    {
        $this->assertNull($this->callOwnAccountId(['source_id' => '1']));
    }

    public function testMissingIdReturnsNull(): void
    {
        // The id is not known yet (name-only account, resolved later in cleanupLine()).
        $transaction = ['type' => 'withdrawal', 'source_name' => 'Some Shop'];
        $this->assertNull($this->callOwnAccountId($transaction));
    }

    public function testNonNumericIdReturnsNull(): void
    {
        $transaction = ['type' => 'deposit', 'destination_id' => 'not-a-number'];
        $this->assertNull($this->callOwnAccountId($transaction));
    }

    public function testEmptyStringIdReturnsNull(): void
    {
        $transaction = ['type' => 'withdrawal', 'source_id' => ''];
        $this->assertNull($this->callOwnAccountId($transaction));
    }
}
