<?php

/*
 * ApiSubmitter.php
 * Copyright (c) 2025 james@firefly-iii.org
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

namespace App\Services\Shared\Import\Routine;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Shared\Authentication\SecretManager;
use App\Services\Shared\Configuration\Configuration;
use Carbon\Carbon;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use GrumpyDictator\FFIIIApiSupport\Model\Transaction;
use GrumpyDictator\FFIIIApiSupport\Model\TransactionGroup;
use GrumpyDictator\FFIIIApiSupport\Request\GetCountTransactionsRequest;
use GrumpyDictator\FFIIIApiSupport\Request\GetSearchTransactionsRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PostFinishBatchRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PostTagRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PostTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PutTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\GetCountTransactionsResponse;
use GrumpyDictator\FFIIIApiSupport\Response\GetTransactionsResponse;
use GrumpyDictator\FFIIIApiSupport\Response\PostFinishBatchResponse;
use GrumpyDictator\FFIIIApiSupport\Response\PostTagResponse;
use GrumpyDictator\FFIIIApiSupport\Response\PostTransactionResponse;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;
use Illuminate\Support\Facades\Log;

/**
 * Class ApiSubmitter
 */
final class ApiSubmitter
{
    private array $accountInfo;
    private bool $addTag;
    private Configuration $configuration;
    private bool $createdTag;
    private array $mapping;
    private string $tag;
    private array $roles;
    private string $tagDate;
    private string $vanityURL;
    private ImportJob $importJob;
    private ImportJobRepository $repository;

    /**
     * Set whenever performSearch() or countFieldMatches() catches an ApiHttpException, so
     * that the resulting "could not check for duplicates" error message (see
     * addDuplicateCheckFailedError()) can quote what actually went wrong. Read only
     * immediately after a call that is known, from its return value, to have just failed.
     */
    private ?string $lastApiError = null;

    public function setImportJob(ImportJob $importJob): void
    {
        $importJob->refreshInstanceIdentifier();
        $this->configuration = $importJob->getConfiguration();
        $this->roles         = $this->configuration->getRoles();
        $this->mapping       = $this->configuration->getMapping();
        $this->repository    = new ImportJobRepository();

        // FIXME remove this line to crash the submission routine without the user getting an error,
        $this->addTag        = $this->configuration->isAddImportTag();
        $this->importJob     = $importJob;
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    /**
     * @throws ImporterErrorException
     */
    public function processTransactions(): void
    {
        $lines            = $this->importJob->getConvertedTransactions();
        $this->createdTag = false;
        $this->tag        = $this->parseTag();
        $this->tagDate    = Carbon::now()->format('Y-m-d');
        $count            = count($lines);
        $uniqueCount      = 0;
        Log::info(sprintf('Going to submit %d transactions to your Firefly III instance.', $count));

        if (0 === $count) {
            $this->importJob->submissionStatus->addWarning(0, 'There are no transactions to be imported. Perhaps all your accounts are empty?');
        }

        $this->vanityURL  = SecretManager::getVanityUrl();

        Log::debug(sprintf('Vanity URL: "%s"', $this->vanityURL));

        /**
         * @var int   $index
         * @var array $line
         */
        foreach ($lines as $index => $line) {
            Log::debug(sprintf('Now submitting transaction %d/%d', $index + 1, $count));

            // Update progress tracking
            $this->importJob->submissionStatus->updateProgress($index + 1, $count);
            // first do local duplicate transaction check (the "cell" method):
            $unique          = $this->uniqueTransaction($index, $line);
            if (null === $unique) {
                Log::debug(sprintf('Transaction #%d is not checked beforehand on uniqueness.', $index + 1));
                ++$uniqueCount;
            }
            if (true === $unique) {
                Log::debug(sprintf('Transaction #%d is unique.', $index + 1));
                ++$uniqueCount;
            }

            // if this is the last transaction, make sure recalculate running balance is set to true.
            $lastTransaction = $index === (count($lines) - 1);

            if (false === $unique) {
                Log::debug(sprintf('Transaction #%d is NOT unique.', $index + 1));
                $this->repository->saveToDisk($this->importJob);

                continue;
            }
            $groupInfo       = $this->processTransaction($index, $line, $lastTransaction);
            $this->addTagToGroups($groupInfo);
            $this->repository->saveToDisk($this->importJob);
        }

        Log::info(sprintf('Done submitting %d transactions to your Firefly III instance.', $count));
        Log::info(sprintf('Actually imported and not duplicate: %d.', $uniqueCount));
    }

    private function parseTag(): string
    {
        // $this->tag        = sprintf('Data Import on %s', date('Y-m-d \@ H:i'));
        $customTag = $this->configuration->getCustomTag();
        if ('' === $customTag) {
            // return default tag:
            return sprintf('Data Import on %s', Carbon::now()->format('Y-m-d \@ H:i'));
        }
        $items     = [
            // '%filename%' => $this->configuration->f
            '%year%'        => Carbon::now()->format('Y'),
            '%month%'       => Carbon::now()->format('m'),
            '%month_full%'  => Carbon::now()->format('F'),
            '%day%'         => Carbon::now()->format('d'),
            '%day_of_week%' => Carbon::now()->format('l'),
            '%hour%'        => Carbon::now()->format('H'),
            '%minute%'      => Carbon::now()->format('i'),
            '%second%'      => Carbon::now()->format('s'),
            '%date%'        => Carbon::now()->format('Y-m-d'),
            '%time%'        => Carbon::now()->format('H:i'),
            '%datetime%'    => Carbon::now()->format('Y-m-d \@ H:i'),
            '%version%'     => config('importer.version'),
        ];
        $result    = str_replace(array_keys($items), array_values($items), $customTag);
        Log::debug(sprintf('Custom tag is "%s", parsed into "%s"', $customTag, $result));

        return $result;
    }

    /**
     * Verify if the transaction is unique, based on the configuration
     * and the content of the transaction. Returns a boolean.
     */
    private function uniqueTransaction(int $index, array $line): ?bool
    {
        if ('cell' !== $this->configuration->getDuplicateDetectionMethod()) {
            Log::debug(sprintf(
                'Duplicate detection method is "%s", so this method is skipped (return true).',
                $this->configuration->getDuplicateDetectionMethod()
            ));

            return null;
        }
        // do a search for the value and the field:
        $transactions = $line['transactions'] ?? [];
        $field        = $this->configuration->getUniqueColumnType();
        $field        = 'external-id' === $field ? 'external_id' : $field;
        $field        = 'note' === $field ? 'notes' : $field;
        if ('' === $field) {
            $field = 'external_id';
        }
        $value        = '';
        $scopeToAccount = (bool) config('importer.duplicate_detection_scope_to_account');
        foreach ($transactions as $transactionIndex => $transaction) {
            $value        = (string) ($transaction[$field] ?? '');
            if ('' === $value) {
                Log::debug(sprintf(
                    'Identifier-based duplicate detection found no value ("") for field "%s" in transaction #%d (index #%d).',
                    $field,
                    $index,
                    $transactionIndex
                ));

                continue;
            }
            $accountId    = $scopeToAccount ? $this->ownAccountId($transaction) : null;
            if (null !== $accountId) {
                Log::debug(sprintf('Duplicate detection for this transaction is scoped to account #%d.', $accountId));
            }
            $searchResult = $this->searchField($field, $value, $accountId);
            if (false === $searchResult) {
                // The search itself could not be performed (API error): we do not know
                // whether this is a duplicate, so it must NOT be imported on the strength
                // of a guess. Distinct from "not found" (null) and from "found" (array).
                $this->addDuplicateCheckFailedError($index, $field, $value);

                return false;
            }
            if (null !== $searchResult) {
                Log::debug(sprintf(
                    'Looks like field "%s" with value "%s" is not unique, found in group #%d. Return false',
                    $field,
                    $value,
                    $searchResult['id']
                ));
                $message = sprintf(
                    '[a115]: There is already a transaction with %s "%s" (<a href="%s/transactions/show/%d">%s</a>, %s %s).',
                    $field,
                    $value,
                    $this->vanityURL,
                    $searchResult['id'],
                    e($searchResult['description']),
                    $searchResult['currency_code'],
                    bcround($searchResult['amount'], $searchResult['decimal_places'])
                );
                if (false === config('importer.ignore_duplicate_errors')) {
                    $this->importJob->submissionStatus->addError($index, $message);
                }

                return false;
            }
            // could still be not unique because of deleted values. When the search above was
            // scoped to an account, a miss there only rules out a LIVE duplicate on that one
            // account; the count endpoint has no account parameter, so the deleted-transaction
            // check below falls back to a ledger-wide comparison instead, to stay exactly as
            // conservative as the unscoped path.
            $exists       = null !== $accountId
                ? $this->deletedTransactionExistsElsewhere($field, $value)
                : $this->searchFieldUsingCount($field, $value);
            if (null === $exists) {
                // Same tri-state rule as above: a failed count check is "unknown", not
                // "no duplicate" and not "duplicate" - it must not be guessed either way.
                $this->addDuplicateCheckFailedError($index, $field, $value);

                return false;
            }
            if ($exists) {
                Log::debug(sprintf('Looks like field "%s" with value "%s" is not unique, found in a deleted transaction group. Return false', $field, $value));
                $message = sprintf('[a115]: There is already a (deleted) transaction with %s "%s", so this transaction will be skipped.', e($field), e($value));
                if (false === config('importer.ignore_duplicate_errors')) {
                    $this->importJob->submissionStatus->addError($index, $message);
                }

                return false;
            }
        }
        Log::debug(sprintf('Looks like field "%s" with value "%s" is unique, return false.', $field, $value));

        return true;
    }

    /**
     * Work out which Firefly III asset account this line's OWN leg belongs to, so the
     * duplicate search can be scoped to it. Returns null (= fall back to the unscoped,
     * ledger-wide search) when the type is not one we recognise, or no usable numeric
     * account id is present yet (name-only accounts are resolved later, in cleanupLine()).
     */
    private function ownAccountId(array $transaction): ?int
    {
        $type = (string) ($transaction['type'] ?? '');
        $key  = match ($type) {
            'withdrawal', 'transfer' => 'source_id',
            'deposit'                => 'destination_id',
            default                  => null,
        };
        if (null === $key) {
            return null;
        }
        $value = $transaction[$key] ?? null;
        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Do a search at Firefly III and return the ID of the group found. Three distinct
     * outcomes, all explicit in the return value: an array means a match was found; null
     * means the search succeeded and found nothing; false means the search could not be
     * performed at all (API error) and uniqueness is therefore UNKNOWN, not "no match".
     */
    private function searchField(string $field, string $value, ?int $accountId = null): array|false|null
    {
        $response       = $this->performSearch($field, $value, $accountId);
        if (null === $response) {
            return false;
        }
        if (0 === $response->count()) {
            return null;
        }
        $first          = $response->current();
        $array          = [
            'id'             => $first->id,
            'description'    => $first->transactions[0]->description ?? '(no description)',
            'currency_code'  => $first->transactions[0]->currencyCode ?? '(no currency)',
            'decimal_places' => $first->transactions[0]->currencyDecimalPlaces ?? 2,
            'amount'         => $first->transactions[0]->amount ?? '(unknown)',
        ];
        Log::debug(sprintf('Found %d transaction(s). Return group ID #%d.', $response->count(), $first->id));

        return $array;
    }

    /**
     * Run the search/transactions query underlying searchField(), optionally scoped to
     * a single account id (Firefly III's search language is AND-only, so this just adds
     * an extra "account_id:X" term). Returns null on an HTTP error.
     */
    private function performSearch(string $field, string $value, ?int $accountId): ?GetTransactionsResponse
    {
        // search for the exact description and not just a part of it:
        $searchModifier = config(sprintf('csv.search_modifier.%s', $field));
        $query          = sprintf('%s:"%s"', $searchModifier, $value);
        if (null !== $accountId) {
            $query .= sprintf(' account_id:%d', $accountId);
        }

        Log::debug(sprintf('Going to search for %s:%s using query %s', $field, $value, $query));

        $url            = SecretManager::getBaseUrl();
        $token          = SecretManager::getAccessToken();
        $request        = new GetSearchTransactionsRequest($url, $token);
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setVerify(config('importer.connection.verify'));
        $request->setQuery($query);

        try {
            /** @var GetTransactionsResponse $response */
            return $request->get();
        } catch (ApiHttpException $e) {
            $this->lastApiError = $e->getMessage();
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

            return null;
        }
    }

    /**
     * Builds and records the distinct "could not check for duplicates" error (a116). Unlike
     * the a115 duplicate messages, this is always added regardless of
     * importer.ignore_duplicate_errors: it is not reporting a duplicate, it is reporting that
     * uniqueness could not be determined at all, which the user needs to know about either way.
     */
    private function addDuplicateCheckFailedError(int $index, string $field, string $value): void
    {
        $message = sprintf(
            '[a116]: Could not check for duplicates of transaction with %s "%s" (Firefly III API error: %s); it was not imported. Run the import again to retry.',
            e($field),
            e($value),
            e($this->lastApiError ?? 'unknown error')
        );
        Log::warning($message);
        $this->importJob->submissionStatus->addError($index, $message);
    }

    /**
     * Scoped duplicate detection cannot ask the count endpoint to filter by account (it
     * has no such parameter), so when scoping is in effect this asks instead whether a
     * deleted transaction with this value exists ANYWHERE in the ledger: compare the
     * ledger-wide count (live + deleted) against a ledger-wide LIVE count. If the former
     * is larger, a deleted duplicate exists somewhere and the transaction is skipped,
     * exactly as the unscoped path already does today - scoping never gives a deleted
     * duplicate on the same account a new way to slip through. Returns null, rather than
     * guessing either way, if either API call needed to answer the question fails.
     */
    private function deletedTransactionExistsElsewhere(string $field, string $value): ?bool
    {
        $totalCount = $this->countFieldMatches($field, $value);
        if (null === $totalCount) {
            return null;
        }
        if (0 === $totalCount) {
            return false;
        }
        $liveResponse = $this->performSearch($field, $value, null);
        $liveCount    = $liveResponse?->count();
        Log::debug(sprintf(
            'Scoped duplicate detection: ledger-wide count (incl. deleted) for %s:%s is %d, ledger-wide live count is %s.',
            $field,
            $value,
            $totalCount,
            null === $liveCount ? 'unknown (API error)' : (string) $liveCount
        ));

        return $this->hasDeletedDuplicate($totalCount, $liveCount);
    }

    /**
     * Pure decision logic behind deletedTransactionExistsElsewhere(), pulled out so it can be
     * unit-tested without a live API: a deleted duplicate exists elsewhere if the ledger-wide
     * count (live + deleted) exceeds the ledger-wide live count. Either count being null means
     * the API call that would have produced it failed, so the answer is unknown, not "no".
     */
    private function hasDeletedDuplicate(?int $totalCount, ?int $liveCount): ?bool
    {
        if (null === $totalCount || null === $liveCount) {
            return null;
        }

        return $totalCount > $liveCount;
    }

    /**
     * Do a search at Firefly III and return the ID of the group found. Returns null, rather
     * than assuming a duplicate, if the underlying count check fails.
     */
    private function searchFieldUsingCount(string $field, string $value): ?bool
    {
        $count = $this->countFieldMatches($field, $value);
        if (null === $count) {
            return null;
        }
        Log::debug(sprintf('The search returned count: %d', $count));

        return $count > 0;
    }

    /**
     * Query the search/transactions/count endpoint (ledger-wide, live + deleted, there is
     * no account parameter) and return the count, or null on an HTTP error.
     */
    private function countFieldMatches(string $field, string $value): ?int
    {
        Log::debug(sprintf('Going to searchFieldUsingCount field "%s" with value "%s".', $field, $value));

        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new GetCountTransactionsRequest($url, $token);
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setVerify(config('importer.connection.verify'));
        $set     = false;
        if ('note' === $field || 'notes' === $field) {
            Log::debug('Search for notes');
            $request->setNotes($value);
            $set = true;
        }
        if ('external-id' === $field || 'external_id' === $field) {
            $request->setExternalIdentifier($value);
            Log::debug('Search for external identifier');
            $set = true;
        }
        if ('description' === $field) {
            Log::debug('Search for description');
            $request->setDescription($value);
            $set = true;
        }
        if ('internal-reference' === $field || 'internal_reference' === $field) {
            Log::debug('Search for internal reference');
            $request->setInternalReference($value);
            $set = true;
        }
        if (false === $set) {
            throw new ImporterErrorException(sprintf('Could not search for field "%s"', $field));
        }

        try {
            /** @var GetCountTransactionsResponse $response */
            $response = $request->get();
        } catch (ApiHttpException $e) {
            $this->lastApiError = $e->getMessage();
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

            return null;
        }

        return $response->getCount();
    }

    private function processTransaction(int $index, array $line, bool $lastTransaction): array
    {
        ++$index;
        $line                     = $this->cleanupLine($line);
        $return                   = [];
        $url                      = SecretManager::getBaseUrl();
        $token                    = SecretManager::getAccessToken();
        $request                  = new PostTransactionRequest($url, $token);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));

        // add line so the last transaction is no longer a batch and triggers some stuff.
        $line['batch_submission'] = !$lastTransaction;

        Log::debug(sprintf('Submitting to Firefly III: %s', json_encode($line)));
        $request->setBody($line);

        try {
            $response = $request->post();
        } catch (ApiHttpException $e) {
            $isDeleted = false;
            $body      = $request->getResponseBody();
            $json      = json_decode($body, true);
            // before we complain, first check what the error is:
            if (is_array($json) && array_key_exists('message', $json)) {
                if (str_contains((string) $json['message'], '200032')) {
                    $isDeleted = true;
                }
            }
            if (true === $isDeleted && false === config('importer.ignore_not_found_transactions')) {
                $this->importJob->submissionStatus->addWarning($index, 'The transaction was created, but deleted by a rule.');
                Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

                return $return;
            }
            if (true === $isDeleted && true === config('importer.ignore_not_found_transactions')) {
                Log::info('The transaction was deleted by a rule, but this is ignored by the importer.');

                return $return;
            }
            $message   = sprintf('[a116]: Submission HTTP error: %s', e($e->getMessage()));
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
            $this->importJob->submissionStatus->addError($index, $message);

            return $return;
        }

        if ($response instanceof ValidationErrorResponse) {
            foreach ($response->errors->messages() as $key => $errors) {
                Log::error(sprintf('Submission error: %d', $key), $errors);
                foreach ($errors as $error) {
                    $msg = sprintf('[a117]: %s: %s (original value: "%s")', $key, $error, e($this->getOriginalValue($key, $line)));
                    if (false === $this->isDuplicationError($key, $error) || false === config('importer.ignore_duplicate_errors')) {
                        $this->importJob->submissionStatus->addError($index, $msg);
                    }
                    Log::error(sprintf('[%s]: %s', config('importer.version'), $msg));
                }
            }

            return $return;
        }

        if ($response instanceof PostTransactionResponse) {
            /** @var TransactionGroup $group */
            $group  = $response->getTransactionGroup();
            if (null === $group) {
                $message = '[a118]: Could not create transaction. Unexpected empty response from Firefly III. Check the logs.';
                Log::error(sprintf('[%s] %s', config('importer.version'), $message), $response->getRawData());
                $this->importJob->submissionStatus->addError($index, $message);

                return $return;
            }

            // perhaps zero transactions in the array.
            if (0 === count($group->transactions)) {
                $message = '[a119]: Could not create transaction. Transaction-count from Firefly III is zero. Check the logs.';
                Log::error(sprintf('[%s] %s', config('importer.version'), $message), $response->getRawData());
                $this->importJob->submissionStatus->addError($index, $message);

                return $return;
            }

            $return = ['group_id' => $group->id, 'journals' => []];
            foreach ($group->transactions as $transaction) {
                $message                              = sprintf(
                    'Created %s <a target="_blank" href="%s">#%d "%s"</a> (%s %s)',
                    $transaction->type,
                    sprintf('%s/transactions/show/%d', $this->vanityURL, $group->id),
                    $group->id,
                    e($transaction->description),
                    $transaction->currencyCode,
                    round((float) $transaction->amount, (int) $transaction->currencyDecimalPlaces) // float but only for display purposes
                );
                // plus 1 to keep the count.
                $this->importJob->submissionStatus->addMessage($index, $message);
                $this->compareArrays($index, $line, $group);
                Log::info(sprintf('[%s] %s', config('importer.version'), $message));
                $return['journals'][$transaction->id] = $transaction->tags;
            }
        }

        return $return;
    }

    private function cleanupLine(array $line): array
    {
        Log::debug('Going to map data for this line.');
        // OK so array 0 does not have to be the mapping, this is a bad assumption.
        // it looks like the CSV importer already DOES the mapping somewhere so this cleanup
        // is for OTHER import types ONLY.

        if (array_key_exists(0, $this->mapping)) {
            Log::debug('Configuration has mapping for opposing account name!');

            /**
             * @var int   $index
             * @var array $transaction
             */
            foreach ($line['transactions'] as $index => $transaction) {
                if ('withdrawal' === $transaction['type']) {
                    // replace destination_name with destination_id
                    $destination = $transaction['destination_name'] ?? '';
                    if (array_key_exists($destination, $this->mapping[0])) {
                        unset($transaction['destination_name'], $transaction['destination_iban']);

                        $transaction['destination_id'] = $this->mapping[0][$destination];
                        Log::debug(sprintf('Replaced destination name "%s" with a reference to account id #%d', $destination, $this->mapping[0][$destination]));
                    }
                }
                if ('deposit' === $transaction['type']) {
                    // replace source_name with source_id
                    $source = $transaction['source_name'] ?? '';
                    if (array_key_exists($source, $this->mapping[0])) {
                        unset($transaction['source_name'], $transaction['source_iban']);

                        $transaction['source_id'] = $this->mapping[0][$source];
                        Log::debug(sprintf('Replaced source name "%s" with a reference to account id #%d', $source, $this->mapping[0][$source]));
                    }
                }
                if ('' === trim((string) ($transaction['description'] ?? ''))) {
                    $transaction['description'] = '(no description)';
                }
                $line['transactions'][$index] = $this->updateTransactionType($transaction);
            }
        }

        return $line;
    }

    private function updateTransactionType(array $transaction): array
    {
        if (array_key_exists('source_id', $transaction) && array_key_exists('destination_id', $transaction)) {
            Log::debug('Transaction has source_id/destination_id');
            $sourceId        = (int) $transaction['source_id'];
            $destinationId   = (int) $transaction['destination_id'];
            $sourceType      = $this->accountInfo[$sourceId] ?? 'unknown';
            $destinationType = $this->accountInfo[$destinationId] ?? 'unknown';
            $combi           = sprintf('%s-%s', $sourceType, $destinationType);
            Log::debug(sprintf('Account type combination is "%s"', $combi));
            if ('asset-asset' === $combi) {
                Log::debug('Both accounts are assets, so this transaction is a transfer.');
                $transaction['type'] = 'transfer';
            }
        }

        return $transaction;
    }

    private function getOriginalValue(string $key, array $transaction): string
    {
        $parts = explode('.', $key);
        if (1 === count($parts)) {
            return $transaction[$key] ?? '(not found)';
        }
        if (3 !== count($parts)) {
            return '(unknown)';
        }
        $index = (int) $parts[1];

        return (string) ($transaction['transactions'][$index][$parts[2]] ?? '(not found)');
    }

    private function isDuplicationError(string $key, string $error): bool
    {
        if ('transactions.0.description' === $key && str_contains($error, 'Duplicate of transaction #')) {
            Log::debug('This is a duplicate transaction error');

            return true;
        }
        Log::debug('This is not a duplicate transaction error');

        return false;
    }

    private function compareArrays(int $lineIndex, array $line, TransactionGroup $group): void
    {
        // some fields may not have survived. Be sure to warn the user about this.
        /** @var Transaction $transaction */
        foreach ($group->transactions as $index => $transaction) {
            // compare currency ID
            if (
                array_key_exists('currency_id', $line['transactions'][$index])
                && null !== $line['transactions'][$index]['currency_id']
                && (int) $line['transactions'][$index]['currency_id'] !== (int) $transaction->currencyId
            ) {
                $this->importJob->submissionStatus->addWarning($lineIndex, sprintf(
                    'Line #%d may have had its currency changed (from ID #%d to ID #%d). This happens because the associated asset account overrules the currency of the transaction.',
                    $lineIndex,
                    $line['transactions'][$index]['currency_id'],
                    (int) $transaction->currencyId
                ));
            }
            // compare currency code:
            if (
                array_key_exists('currency_code', $line['transactions'][$index])
                && null !== $line['transactions'][$index]['currency_code']
                && $line['transactions'][$index]['currency_code'] !== $transaction->currencyCode
            ) {
                $this->importJob->submissionStatus->addWarning($lineIndex, sprintf(
                    'Line #%d may have had its currency changed (from "%s" to "%s"). This happens because the associated asset account overrules the currency of the transaction.',
                    $lineIndex,
                    $line['transactions'][$index]['currency_code'],
                    $transaction->currencyCode
                ));
            }
        }
    }

    private function addTagToGroups(array $groupInfo): void
    {
        if ([] === $groupInfo) {
            Log::debug('Group is empty, may not have been stored correctly.');

            return;
        }
        if (false === $this->addTag) {
            Log::debug('Will not add import tag.');

            return;
        }
        if (false === $this->createdTag) {
            $this->createTag();
            $this->createdTag = true;
        }

        $groupId = (int) $groupInfo['group_id'];
        Log::debug(sprintf('Going to add import tag to transaction group #%d', $groupId));
        $body    = ['fire_webhooks' => false, 'batch_submission' => true, 'apply_rules' => false, 'transactions' => []];

        /**
         * @var int   $journalId
         * @var array $currentTags
         */
        foreach ($groupInfo['journals'] as $journalId => $currentTags) {
            $currentTags[]          = $this->tag;
            $body['transactions'][] = ['transaction_journal_id' => $journalId, 'tags' => $currentTags];
        }
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PutTransactionRequest($url, $token, $groupId);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        $request->setBody($body);

        try {
            $request->put();
        } catch (ApiHttpException $e) {
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));
            //            Log::error($e->getTraceAsString());
            $this->importJob->submissionStatus->addError(0, '[a120]: Could not store transaction: see the log files.');
        }
        Log::debug(sprintf('Added import tag to transaction group #%d', $groupId));
    }

    private function createTag(): void
    {
        if (false === $this->addTag) {
            Log::debug('Not instructed to add a tag, so will not create one.');

            return;
        }
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PostTagRequest($url, $token);
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));
        $body    = ['tag' => $this->tag, 'date' => $this->tagDate];
        $request->setBody($body);

        try {
            /** @var PostTagResponse|ValidationErrorResponse $response */
            $response = $request->post();
        } catch (ApiHttpException $e) {
            $message = sprintf('[a121]: Could not create tag. %s', e($e->getMessage()));
            Log::error(sprintf('[%s] %s', config('importer.version'), $message));
            $this->importJob->submissionStatus->addError(0, $message);

            return;
        }
        if ($response instanceof ValidationErrorResponse) {
            Log::error(json_encode($response->errors->toArray()));

            return;
        }
        if (null !== $response->getTag()) {
            Log::info(sprintf('Created tag #%d "%s"', $response->getTag()->id, $response->getTag()->tag));
        }
    }

    public function setAccountInfo(array $accountInfo): void
    {
        $this->accountInfo = $accountInfo;
    }

    public function finishBatch(): void
    {
        Log::debug('Will now instruct Firefly III to finish the batch.');
        $url     = SecretManager::getBaseUrl();
        $token   = SecretManager::getAccessToken();
        $request = new PostFinishBatchRequest($url, $token, $this->configuration->isRules());
        $request->setVerify(config('importer.connection.verify'));
        $request->setTimeOut(config('importer.connection.timeout'));

        try {
            /** @var PostFinishBatchResponse $response */
            $response = $request->post();
        } catch (ApiHttpException $e) {
            Log::error(sprintf('Could not finish batch: %s', $e->getMessage()));
            $this->importJob->submissionStatus->addWarning(0, 'Could not finish batch, but that\'s OK.');

            return;
        }
        Log::debug('Done instructing Firefly III to finish the batch.');
    }
}
