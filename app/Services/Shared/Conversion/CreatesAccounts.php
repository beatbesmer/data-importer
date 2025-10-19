<?php
/*
 * CreatesAccounts.php
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

namespace App\Services\Shared\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Services\Shared\Model\ImportServiceAccount;
use App\Services\SimpleFIN\Model\Account as SimpleFINAccount;
use Carbon\Carbon;
use GrumpyDictator\FFIIIApiSupport\Model\Account;
use Illuminate\Support\Facades\Log;

trait CreatesAccounts
{
    protected array $existingServiceAccounts = [];

    protected function createOrFindExistingAccount(string $importServiceId): Account
    {
        Log::debug('Starting account creation process.');
        $newAccountData = $this->configuration->getNewAccounts()[$importServiceId] ?? null;
        $createdAccount = null;
        // here is a check to see if account to be created is part of the import process.
        // so, existing service accounts contains all the accounts present at the import service with all of their meta-data.
        $existingAccount = array_find($this->existingServiceAccounts, function (array|object $entry) use ($importServiceId) {
            if (is_array($entry)) {
                return (string)$entry['id'] === $importServiceId;
            }
            return (string)$entry->id === $importServiceId;
        });

        $continue = true;
        if (null === $newAccountData) {
            Log::error(sprintf('No new account data found for account "%s"', $importServiceId));
            $continue = false;
        }

        // Validate required fields for account creation
        if (true === $continue && '' === (string)$newAccountData['name']) {
            Log::error(sprintf('Account name is required for creating account "%s"', $importServiceId));
            $continue = false;
        }

        if (true === $continue && null === $existingAccount) {
            Log::error(sprintf('Existing account data not found for account "%s"', $importServiceId));
            $continue = false;
        }

        if (true === $continue) {
            // Prepare account creation configuration with defaults
            $configuration = [
                'name'     => $newAccountData['name'],
                'type'     => $newAccountData['type'] ?? 'asset',
                'currency' => $newAccountData['currency'] ?? 'EUR',
            ];

            // Add opening balance if provided
            if ('' !== (string)$newAccountData['opening_balance'] && is_numeric($newAccountData['opening_balance'])) {
                $configuration['opening_balance']      = $newAccountData['opening_balance'];
                $configuration['opening_balance_date'] = Carbon::now()->format('Y-m-d');
            }
            Log::info('Creating new Firefly III account', ['existing_account_id' => $importServiceId, 'configuration' => $configuration]);

            // Create SimpleFIN Account object and create Firefly III account
            // $existingAccountObject = SimpleFINAccount::fromArray($existingAccount);
            $existingAccountObject = ImportServiceAccount::convertSingleAccount($existingAccount);
            $accountMapper         = new AccountMapper();
            $createdAccount        = $accountMapper->createFireflyIIIAccount($existingAccountObject, $configuration);

            // overrule the name with what we actually want to search for.
            $existingAccountObject->name = $newAccountData['name'];

            if (null === $createdAccount) {
                Log::warning('Failed to create Firefly III account. May not be able to proceed with transaction import for this account.', $configuration);
                $createdAccount = $accountMapper->findMatchingFireflyIIIAccount($existingAccountObject);
            }
        }

        if (null === $createdAccount) {
            $message = sprintf('Creation failed, and could not find a matching account for SimpleFIN account "%s"', $importServiceId);
            Log::error($message);
            throw new ImporterErrorException($message);
        }
        Log::info('Successfully created or found new Firefly III account', ['simplefin_account_id' => $importServiceId, 'firefly_account_id' => $createdAccount->id, 'account_name' => $createdAccount->name, 'account_type' => $configuration['type'], 'currency' => $configuration['currency']]);
        return $createdAccount;
    }


}
