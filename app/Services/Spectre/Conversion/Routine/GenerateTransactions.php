<?php

/*
 * GenerateTransactions.php
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

namespace App\Services\Spectre\Conversion\Routine;

use Carbon\Carbon;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Conversion\ProgressInformation;
use App\Services\Spectre\Model\Transaction;
use App\Support\Http\CollectsAccounts;
use App\Support\Internal\DuplicateSafetyCatch;
use GrumpyDictator\FFIIIApiSupport\Exceptions\ApiHttpException;
use Illuminate\Support\Facades\Log;

/**
 * Class GenerateTransactions.
 */
class GenerateTransactions
{
    use CollectsAccounts;
    use DuplicateSafetyCatch;
    use ProgressInformation;

    private array         $accounts;
    private Configuration $configuration;
    private array         $specialSubTypes = ['REVERSAL', 'REQUEST', 'BILLING', 'SCT', 'SDD', 'NLO'];
    private array         $targetAccounts;
    private array         $targetTypes;

    /**
     * GenerateTransactions constructor.
     */
    public function __construct()
    {
        $this->targetAccounts = [];
        $this->targetTypes    = [];
        bcscale(12);
    }

    /**
     * @throws ApiHttpException
     */
    public function collectTargetAccounts(): void
    {
        Log::debug('Spectre: Defer account search to trait.');
        // defer to trait:
        $array = $this->collectAllTargetAccounts();
        foreach ($array as $number => $info) {
            $this->targetAccounts[$number] = $info['id'];
            $this->targetTypes[$number]    = $info['type'];
        }
        Log::debug(sprintf('Spectre: Collected %d accounts.', count($this->targetAccounts)));
    }

    public function getTransactions(array $spectre): array
    {
        $return = [];

        /** @var Transaction $entry */
        foreach ($spectre as $entry) {
            $return[] = $this->generateTransaction($entry);
            // TODO error handling at this point.
        }

        // $this->addMessage(0, sprintf('Parsed %d Spectre transactions for further processing.', count($return)));

        return $return;
    }

    private function generateTransaction(Transaction $entry): array
    {
        Log::debug('Original Spectre transaction', $entry->toArray());
        $description              = $entry->getDescription();
        $spectreAccountId         = $entry->getAccountId();
        $madeOn                   = $entry->getMadeOn()->toW3cString();
        $amount                   = $entry->getAmount();

        // extra information from the "extra" array. May be NULL.
        $notes                    = trim(sprintf('%s %s', $entry->extra->getInformation(), $entry->extra->getAdditional()));

        $transaction              = [
            'type'              => 'withdrawal', // reverse
            'date'              => str_replace('T', ' ', substr($madeOn, 0, 19)),
            'datetime'          => $madeOn, // not used in API, only for transaction filtering.
            'amount'            => 0,
            'description'       => $description,
            'order'             => 0,
            'currency_code'     => $entry->getCurrencyCode(),
            'tags'              => [$entry->getMode(), $entry->getStatus(), $entry->getCategory()],
            'category_name'     => $entry->getCategory(),
            'category_id'       => $this->configuration->getMapping()['categories'][$entry->getCategory()] ?? null,
            'external_id'       => $entry->getId(),
            'interal_reference' => $entry->getAccountId(),
            'notes'             => $notes,
        ];

        // add time, post_date and post_time to transaction
        if ($entry->extra->getPostingDate() instanceof Carbon) {
            $transaction['book_date'] = $entry->extra->getPostingDate()->toW3cString();
        }
        if ($entry->extra->getPostingTime() instanceof Carbon) {
            $transaction['notes'] .= sprintf("\n\npost_time: %s", $entry->extra->getPostingTime());
        }
        if ($entry->extra->getTime() instanceof Carbon) {
            $transaction['notes'] .= sprintf("\n\ntime: %s", $entry->extra->getTime());
        }

        $return                   = [
            'apply_rules'             => $this->configuration->isRules(),
            'error_if_duplicate_hash' => $this->configuration->isIgnoreDuplicateTransactions(),
            'transactions'            => [],
        ];

        if ($this->configuration->isIgnoreSpectreCategories()) {
            Log::debug('Remove Spectre categories and tags.');
            unset($transaction['tags'], $transaction['category_name'], $transaction['category_id']);
        }

        // amount is positive?
        if (1 === bccomp($amount, '0')) {
            Log::debug('Amount is positive: assume transfer or deposit.');
            $transaction = $this->processPositiveTransaction($entry, $transaction, $amount, $spectreAccountId);
        }

        if (-1 === bccomp($amount, '0')) {
            Log::debug('Amount is negative: assume transfer or withdrawal.');
            $transaction = $this->processNegativeTransaction($entry, $transaction, $amount, $spectreAccountId);
        }

        $return['transactions'][] = $transaction;

        Log::debug(sprintf('Parsed Spectre transaction #%d', $entry->getId()));

        return $return;
    }

    private function processPositiveTransaction(Transaction $entry, array $transaction, string $amount, string $spectreAccountId): array
    {
        // amount is positive: deposit or transfer. Spectre account is destination
        $transaction['type']           = 'deposit';
        $transaction['amount']         = $amount;

        // destination is Spectre
        $transaction['destination_id'] = (int) $this->accounts[$spectreAccountId];

        // source is the other side (name!)
        // payee is the destination, payer is the source.
        // since we know the destination already, we're looking for the payer here:
        $transaction['source_name']    = $entry->getPayer() ?? '(unknown source account)';
        $transaction['source_iban']    = $entry->getPayerIban() ?? '';

        Log::debug(sprintf('processPositiveTransaction: source_name = "%s", source_iban = "%s"', $transaction['source_name'], $transaction['source_iban']));

        // check if the source IBAN is a known account and what type it has: perhaps the
        // transaction type needs to be changed:
        $iban                          = $transaction['source_iban'];
        $accountType                   = $this->targetTypes[$iban] ?? 'unknown';
        $accountId                     = $this->targetAccounts[$iban] ?? 0;
        Log::debug(sprintf('Found account type "%s" for IBAN "%s"', $accountType, $iban));

        if ('unknown' !== $accountType) {
            if ('asset' === $accountType) {
                Log::debug('Changing transaction type to "transfer"');
                $transaction['type'] = 'transfer';
            }
        }
        if (0 !== $accountId) {
            Log::debug(sprintf('Found account ID #%d for IBAN "%s"', $accountId, $iban));
            $transaction['source_id'] = (int) $accountId;
            unset($transaction['source_name'], $transaction['source_iban']);
        }
        $transaction                   = $this->positiveTransactionSafetyCatch($transaction, (string) $entry->getPayer(), (string) $entry->getPayerIban());

        Log::debug(sprintf('destination_id = %d, source_name = "%s", source_iban = "%s", source_id = "%s"', $transaction['destination_id'] ?? '', $transaction['source_name'] ?? '', $transaction['source_iban'] ?? '', $transaction['source_id'] ?? ''));

        return $transaction;
    }

    private function processNegativeTransaction(Transaction $entry, array $transaction, string $amount, string $spectreAccountId): array
    {
        // amount is negative: withdrawal or transfer.
        $transaction['amount']           = bcmul($amount, '-1');

        // source is Spectre:
        $transaction['source_id']        = (int) $this->accounts[$spectreAccountId];

        // dest is shop. Payee / payer is reverse from the other one.
        $transaction['destination_name'] = $entry->getPayee() ?? '(unknown destination account)';
        $transaction['destination_iban'] = $entry->getPayeeIban() ?? '';

        Log::debug(sprintf('processNegativeTransaction: destination_name = "%s", destination_iban = "%s"', $transaction['destination_name'], $transaction['destination_iban']));

        // check if the destination IBAN is a known account and what type it has: perhaps the
        // transaction type needs to be changed:
        $iban                            = $transaction['destination_iban'];
        $accountType                     = $this->targetTypes[$iban] ?? 'unknown';
        $accountId                       = $this->targetAccounts[$iban] ?? 0;
        Log::debug(sprintf('Found account type "%s" for IBAN "%s"', $accountType, $iban));
        if ('unknown' !== $accountType) {
            if ('asset' === $accountType) {
                Log::debug('Changing transaction type to "transfer"');
                $transaction['type'] = 'transfer';
            }
        }
        if (0 !== $accountId) {
            Log::debug(sprintf('Found account ID #%d for IBAN "%s"', $accountId, $iban));
            $transaction['destination_id'] = $accountId;
            unset($transaction['destination_name'], $transaction['destination_iban']);
        }

        $transaction                     = $this->negativeTransactionSafetyCatch($transaction, (string) $entry->getPayee(), (string) $entry->getPayeeIban());

        Log::debug(sprintf('source_id = %d, destination_id = "%s", destination_name = "%s", destination_iban = "%s"', $transaction['source_id'], $transaction['destination_id'] ?? '', $transaction['destination_name'] ?? '', $transaction['destination_iban'] ?? ''));

        return $transaction;
    }

    public function setConfiguration(Configuration $configuration): void
    {
        $this->configuration = $configuration;
        $this->accounts      = $configuration->getAccounts();
    }
}
