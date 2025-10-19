<?php

/*
 * Transaction.php
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

// contains plain-text information as they have to be used for the API or wherever. no objects and stuff

namespace App\Services\Camt;

use Genkgo\Camt\DTO\DomainBankTransactionCode;
use Genkgo\Camt\DTO\Account;
use App\Exceptions\ImporterErrorException;
use Genkgo\Camt\Camt053\DTO\Statement;
use Genkgo\Camt\DTO\Address;
use Genkgo\Camt\DTO\BBANAccount;
use Genkgo\Camt\DTO\Creditor;
use Genkgo\Camt\DTO\Debtor;
use Genkgo\Camt\DTO\Entry;
use Genkgo\Camt\DTO\EntryTransactionDetail;
use Genkgo\Camt\DTO\IbanAccount;
use Genkgo\Camt\DTO\Message;
use Genkgo\Camt\DTO\OtherAccount;
use Genkgo\Camt\DTO\ProprietaryAccount;
use Genkgo\Camt\DTO\RelatedParty;
use Genkgo\Camt\DTO\UnstructuredRemittanceInformation;
use Genkgo\Camt\DTO\UPICAccount;
use Illuminate\Support\Facades\Log;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

class Transaction
{
    public const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Message       $levelA,
        private readonly Statement     $levelB,
        private readonly Entry         $levelC,
        private array         $levelD
    ) {
        Log::debug('Constructed a CAMT Transaction');
    }

    public function countSplits(): int
    {
        return count($this->levelD);
    }

    public function getCurrencyCode(int $index): string
    {
        // TODO loop level D for the date that belongs to the index
        return (string) $this->levelC->getAmount()->getCurrency()->getCode();
    }

    public function getAmount(int $index): string
    {
        // TODO loop level D for the date that belongs to the index
        return (string) $this->getDecimalAmount($this->levelC->getAmount());
    }

    private function getDecimalAmount(?Money $money): string
    {
        if (!$money instanceof Money) {
            return '';
        }
        $currencies            = new ISOCurrencies();
        $moneyDecimalFormatter = new DecimalMoneyFormatter($currencies);

        return $moneyDecimalFormatter->format($money);
    }

    public function getDate(int $index): string
    {
        // TODO loop level D for the date that belongs to the index
        return (string) $this->levelC->getValueDate()->format(self::TIME_FORMAT);
    }

    /**
     * @throws ImporterErrorException
     */
    public function getFieldByIndex(string $field, int $index): string
    {
        Log::debug(sprintf('getFieldByIndex("%s", %d)', $field, $index));

        switch ($field) {
            default:
                // temporary debug message:
                //                echo sprintf('Unknown field "%s" in getFieldByIndex(%d)', $field, $index);
                //                echo PHP_EOL;
                //                exit;
                // end temporary debug message
                throw new ImporterErrorException(sprintf('Unknown field "%s" in getFieldByIndex(%d)', $field, $index));

                // LEVEL A
            case 'messageId':
                // always the same, since its level A.
                return (string) $this->levelA->getGroupHeader()->getMessageId();

                // LEVEL B
            case 'statementId':
                // always the same, since its level B.
                return (string) $this->levelB->getId();

            case 'statementCreationDate':
                // always the same, since its level B.
                return (string) $this->levelB->getCreatedOn()->format(self::TIME_FORMAT);

            case 'CdtDbtInd':
                /** @var null|EntryTransactionDetail $set */
                $set             = $this->levelD[$index];

                return (string) $set?->getCreditDebitIndicator();

            case 'statementAccountIban':
                if (IbanAccount::class === $this->levelB->getAccount()::class) {
                    return $this->levelB->getAccount()->getIdentification();
                }

                return '';

            case 'statementAccountNumber':
                // always the same, since its level B.
                $list            = [OtherAccount::class, ProprietaryAccount::class, UPICAccount::class, BBANAccount::class];
                $class           = $this->levelB->getAccount()::class;
                if (in_array($class, $list, true)) {
                    return $this->levelB->getAccount()->getIdentification();
                }

                // LEVEL C
                return '';

            case 'entryAccountServicerReference':
                // always the same, since its level C.
                return (string) $this->levelC->getAccountServicerReference();

            case 'entryReference':
                // always the same, since its level C.
                return (string) $this->levelC->getReference();

            case 'entryAdditionalInfo':
                // always the same, since its level C.
                return (string) $this->levelC->getAdditionalInfo();

            case 'entryAmount':
                // always the same, since its level C.
                return (string) $this->getDecimalAmount($this->levelC->getAmount());

            case 'entryAmountCurrency':
                // always the same, since its level C.
                return (string) $this->levelC->getAmount()->getCurrency()->getCode();

            case 'entryValueDate':
                // always the same, since its level C.
                return (string) $this->levelC->getValueDate()?->format(self::TIME_FORMAT);

            case 'entryBookingDate':
                // always the same, since its level C.
                return (string) $this->levelC->getBookingDate()?->format(self::TIME_FORMAT);

            case 'entryBtcDomainCode':
                // always the same, since its level C.
                if ($this->levelC->getBankTransactionCode()->getDomain() instanceof DomainBankTransactionCode) {
                    return (string) $this->levelC->getBankTransactionCode()->getDomain()->getCode();
                }

                return '';

            case 'entryBtcFamilyCode':
                $return          = '';
                // always the same, since its level C.
                if ($this->levelC->getBankTransactionCode()->getDomain() instanceof DomainBankTransactionCode) {
                    $return = (string) $this->levelC->getBankTransactionCode()->getDomain()->getFamily()->getCode();
                }

                return '';

            case 'entryBtcSubFamilyCode':
                $return          = '';
                // always the same, since its level C.
                if ($this->levelC->getBankTransactionCode()->getDomain() instanceof DomainBankTransactionCode) {
                    return (string) $this->levelC->getBankTransactionCode()->getDomain()->getFamily()->getSubFamilyCode();
                }

                return $return;

                // LEVEL D
            case 'entryDetailAccountServicerReference':
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return '';
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];

                return (string) $info?->getReference()?->getAccountServicerReference();

            case 'entryDetailRemittanceInformationUnstructuredBlockMessage':
                $result          = '';

                // this is level D, so grab from level C or loop.
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    Log::debug('There is no info for this thing.');

                    // TODO return nothing?
                    return $result;
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];

                if (null !== $info->getRemittanceInformation()) {
                    $unstructured = $info->getRemittanceInformation()->getUnstructuredBlocks();

                    /** @var UnstructuredRemittanceInformation $block */
                    foreach ($unstructured as $block) {
                        $result .= sprintf('%s ', $block->getMessage());
                    }
                }

                return $result;

            case 'entryDetailRemittanceInformationStructuredBlockAdditionalRemittanceInformation':
                // this is level D, so grab from level C or loop.
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    // TODO return nothing?
                    return '';
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index]; // TODO, check if always readable or if we need some checks like with "unstructuredBlockMessage"

                // like the unstructured block, these could be multiple blocks, so loop:
                if (null !== $info->getRemittanceInformation() && count($info->getRemittanceInformation()->getStructuredBlocks()) > 0) {
                    $return = '';
                    foreach ($info->getRemittanceInformation()->getStructuredBlocks() as $block) {
                        $return .= sprintf('%s ', $block->getAdditionalRemittanceInformation());
                    }

                    // #8994 add info.
                    $string = (string) $info->getRemittanceInformation()?->getCreditorReferenceInformation()?->getRef();
                    if ('' !== $string) {
                        return sprintf('%s %s', $return, $string);
                    }

                    return $return;
                }

                return '';

                break;

            case 'entryDetailAmount':
                // this is level D, so grab from level C or loop.
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return ''; // config.-depending fallback handled in mapping
                    // return $this->getDecimalAmount($this->levelC->getAmount());
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];

                return $this->getDecimalAmount($info->getAmount());

            case 'entryDetailAmountCurrency':
                // this is level D, so grab from level C or loop.
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return ''; // config.-depending fallback handled in mapping
                    // return (string)$this->levelC->getAmount()->getCurrency()->getCode();
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];

                return (string) $info->getAmount()?->getCurrency()?->getCode();

            case 'entryDetailBtcDomainCode':
                // this is level D, so grab from level C or loop.
                $return          = '';
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    // return (string)$this->levelC->getBankTransactionCode()->getDomain()->getCode();
                    return $return; // config.-depending fallback handled in mapping
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];
                if (null !== $info->getBankTransactionCode()->getDomain()) {
                    return (string) $info->getBankTransactionCode()->getDomain()->getCode();
                }

                return $return;

            case 'entryDetailBtcFamilyCode':
                // this is level D, so grab from level C or loop.
                $return          = '';
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    // return (string)$this->levelC->getBankTransactionCode()->getDomain()->getFamily()->getCode();
                    return $return; // config.-depending fallback handled in mapping
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];
                if (null !== $info->getBankTransactionCode()->getDomain()) {
                    return (string) $info->getBankTransactionCode()->getDomain()->getFamily()->getCode();
                }

                return $return;

            case 'entryDetailBtcSubFamilyCode':
                // this is level D, so grab from level C or loop.
                $return          = '';
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    // return (string)$this->levelC->getBankTransactionCode()->getDomain()->getFamily()->getSubFamilyCode();
                    return $return; // config.-depending fallback handled in mapping
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];
                if (null !== $info->getBankTransactionCode()->getDomain()) {
                    return (string) $info->getBankTransactionCode()->getDomain()->getFamily()->getSubFamilyCode();
                }

                return $return;

            case 'entryDetailOpposingAccountIban':
                $result          = '';

                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return $result;
                }

                /** @var null|EntryTransactionDetail $info */
                $info            = $this->levelD[$index] ?? null;
                if (null !== $info) {
                    $opposingAccount = $this->getOpposingParty($info)?->getAccount();
                    if (is_object($opposingAccount) && IbanAccount::class === $opposingAccount::class) {
                        $result = (string) $opposingAccount->getIdentification();
                    }
                }

                return $result;

            case 'entryDetailOpposingAccountNumber':
                $result          = '';

                $list            = [OtherAccount::class, ProprietaryAccount::class, UPICAccount::class, BBANAccount::class];
                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return $result;
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];
                $opposingAccount = $this->getOpposingParty($info)?->getAccount();
                $class           = $opposingAccount instanceof Account ? $opposingAccount::class : '';
                if (in_array($class, $list, true)) {
                    return (string) $opposingAccount->getIdentification();
                }

                return $result;

            case 'entryDetailOpposingName':
                $result          = '';

                if (0 === count($this->levelD) || !array_key_exists($index, $this->levelD)) {
                    return $result;
                }

                /** @var EntryTransactionDetail $info */
                $info            = $this->levelD[$index];
                $opposingParty   = $this->getOpposingParty($info);
                if (!$opposingParty instanceof RelatedParty) {
                    Log::debug('In entryDetailOpposingName, opposing party is NULL, return "".');
                }
                if ($opposingParty instanceof RelatedParty) {
                    return $this->getOpposingName($opposingParty);
                }

                return $result;
        }
    }

    /**
     * @return null|Creditor|Debtor
     */
    private function getOpposingParty(EntryTransactionDetail $transactionDetail): ?RelatedParty
    {
        Log::debug('getOpposingParty(), interested in Creditor.');
        $relatedParties           = $transactionDetail->getRelatedParties();
        $targetRelatedPartyObject = Creditor::class;

        // get amount from "getAmount":
        $amount                   = $transactionDetail?->getAmount()?->getAmount();
        if (null !== $amount) {
            Log::debug(sprintf('Amount in getAmount() is "%s"', $amount));
        }
        if (null === $amount) {
            $amount = $transactionDetail->getAmountDetails()?->getAmount();
            Log::debug(sprintf('Amount in getAmountDetails() is "%s"', $amount));
        }

        if (null !== $amount && $amount > 0) { // which part in this array is the interesting one?
            Log::debug('getOpposingParty(), interested in Debtor!');
            $targetRelatedPartyObject = Debtor::class;
        }
        foreach ($relatedParties as $relatedParty) {
            Log::debug(sprintf('Found related party of type "%s"', $relatedParty->getRelatedPartyType()::class));
            if ($relatedParty->getRelatedPartyType()::class === $targetRelatedPartyObject) {
                Log::debug('This is the type we are looking for!');

                return $relatedParty;
            }
        }
        Log::debug('getOpposingParty(), no opposing party found, return NULL.');

        return null;
    }

    private function getOpposingName(RelatedParty $relatedParty): string
    {
        $opposingName = '';
        // TODO make depend on configuration
        if ('' === (string) $relatedParty->getRelatedPartyType()->getName()) {
            // there is no "name", so use the address instead
            $opposingName = $this->generateAddressLine($relatedParty->getRelatedPartyType()->getAddress());
        }
        if ('' !== (string) $relatedParty->getRelatedPartyType()->getName()) {
            // there is a name
            $opposingName = $relatedParty->getRelatedPartyType()->getName();

            // but maybe you want also the entire address
            // 2025-07-19: method is always uses $useEntireAddress=false, nobody uses this.
            //            if ($useEntireAddress && $addressLine = $this->generateAddressLine($relatedParty->getRelatedPartyType()->getAddress())) {
            //                $opposingName .= sprintf(', %s', $addressLine);
            //            }
        }

        return $opposingName;
    }

    private function generateAddressLine(?Address $address = null): string
    {
        return implode(', ', $address->getAddressLines());
    }
}
