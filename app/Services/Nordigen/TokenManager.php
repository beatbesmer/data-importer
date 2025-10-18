<?php

/*
 * TokenManager.php
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

namespace App\Services\Nordigen;

use Carbon\Carbon;
use App\Exceptions\ImporterErrorException;
use App\Exceptions\ImporterHttpException;
use App\Services\Nordigen\Authentication\SecretManager;
use App\Services\Nordigen\Request\PostNewTokenRequest;
use App\Services\Nordigen\Response\TokenSetResponse;
use App\Services\Session\Constants;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\NoReturn;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class TokenManager
 */
class TokenManager
{
    /**
     * @throws ImporterErrorException
     */
    public static function getAccessToken(): string
    {
        // Log::debug(sprintf('Now at %s', __METHOD__));
        self::validateAllTokens();

        try {
            $token = session()->get(Constants::NORDIGEN_ACCESS_TOKEN);
        } catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
            throw new ImporterErrorException($e->getMessage(), 0, $e);
        }

        return $token;
    }

    /**
     * @throws ImporterErrorException
     */
    public static function validateAllTokens(): void
    {
        // Log::debug(sprintf('Now at %s', __METHOD__));
        // is there a valid access and refresh token?
        if (self::hasValidRefreshToken() && self::hasValidAccessToken()) {
            return;
        }

        if (self::hasExpiredRefreshToken()) {
            // refresh!
            self::getFreshAccessToken();
        }

        // get complete set!
        try {
            $identifier = SecretManager::getId();
            $key        = SecretManager::getKey();
            self::getNewTokenSet($identifier, $key);
        } catch (ImporterHttpException $e) {
            throw new ImporterErrorException($e->getMessage(), 0, $e);
        }
    }

    public static function hasValidRefreshToken(): bool
    {
        $hasToken = session()->has(Constants::NORDIGEN_REFRESH_TOKEN);
        if (false === $hasToken) {
            Log::debug(sprintf('Now at %s', __METHOD__));
            Log::debug('No Nordigen refresh token, so return false.');

            return false;
        }

        try {
            $tokenValidity = session()->get(Constants::NORDIGEN_REFRESH_EXPIRY_TIME) ?? 0;
        } catch (ContainerExceptionInterface|NotFoundExceptionInterface) {
            $tokenValidity = 0;
        }

        return Carbon::now()->getTimestamp() < $tokenValidity;
    }

    public static function hasValidAccessToken(): bool
    {
        $hasAccessToken = session()->has(Constants::NORDIGEN_ACCESS_TOKEN);
        if (false === $hasAccessToken) {
            Log::debug(sprintf('Now at %s', __METHOD__));
            Log::debug('No Nordigen token is present, so no valid access token');

            return false;
        }

        try {
            $tokenValidity = session()->get(Constants::NORDIGEN_ACCESS_EXPIRY_TIME) ?? 0;
        } catch (ContainerExceptionInterface|NotFoundExceptionInterface) {
            $tokenValidity = 0;
        }
        // Log::debug(sprintf('Nordigen token is valid until %s', date('Y-m-d H:i:s', $tokenValidity)));
        $result         = Carbon::now()->getTimestamp() < $tokenValidity;
        if (false === $result) {
            Log::debug('Nordigen token is no longer valid');

            return false;
        }

        // Log::debug('Nordigen token is valid.');

        return true;
    }

    public static function hasExpiredRefreshToken(): bool
    {
        // Log::debug(sprintf('Now at %s', __METHOD__));
        $hasToken = session()->has(Constants::NORDIGEN_REFRESH_TOKEN);
        if (false === $hasToken) {
            Log::debug('No refresh token, so return false.');

            return false;
        }

        exit(__METHOD__);
    }

    #[NoReturn]
    public static function getFreshAccessToken(): void
    {
        exit(__METHOD__);
    }

    /**
     * get new token set and store in session
     *
     * @throws ImporterHttpException
     */
    public static function getNewTokenSet(string $identifier, string $key): void
    {
        Log::debug(sprintf('Now at %s', __METHOD__));
        $client = new PostNewTokenRequest($identifier, $key);
        $client->setTimeOut(config('importer.connection.timeout'));

        /** @var TokenSetResponse $result */
        $result = $client->post();

        // store in session:
        session()->put(Constants::NORDIGEN_ACCESS_TOKEN, $result->accessToken);
        session()->put(Constants::NORDIGEN_REFRESH_TOKEN, $result->refreshToken);

        session()->put(Constants::NORDIGEN_ACCESS_EXPIRY_TIME, $result->accessExpires);
        session()->put(Constants::NORDIGEN_REFRESH_EXPIRY_TIME, $result->refreshExpires);
    }
}
