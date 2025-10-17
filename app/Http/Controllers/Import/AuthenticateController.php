<?php

/*
 * AuthenticateController.php
 * Copyright (c) 2021 james@firefly-iii.org
 *
 * This file is part of the Firefly III Data Importer
 * (https://github.com/firefly-iii/data-importer).
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

namespace App\Http\Controllers\Import;

use App\Exceptions\ImporterErrorException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateControllerMiddleware;
use App\Services\Enums\AuthenticationStatus;
use App\Services\Nordigen\Authentication\SecretManager as NordigenSecretManager;
use App\Services\Nordigen\AuthenticationValidator as NordigenValidator;
use App\Services\LunchFlow\AuthenticationValidator as LunchFlowValidator;
use App\Services\Session\Constants;
use App\Services\Spectre\Authentication\SecretManager as SpectreSecretManager;
use App\Services\Spectre\AuthenticationValidator as SpectreValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Session;

/**
 * Class AuthenticateController
 */
class AuthenticateController extends Controller
{
    private const string AUTH_ROUTE = '002-authenticate.index';

    public function __construct()
    {
        parent::__construct();
        Log::debug('Now in AuthenticateController, calling middleware.');
        $this->middleware(AuthenticateControllerMiddleware::class);
    }

    /**
     * @return Application|Factory|Redirector|RedirectResponse|View
     *
     * @throws ImporterErrorException
     */
    public function index(Request $request)
    {
        // variables for page:
        $mainTitle = 'Authentication';
        $pageTitle = 'Authentication';
        $flow      = $request->cookie(Constants::FLOW_COOKIE);
        $subTitle  = ucfirst($flow);
        $error     = Session::get('error');
        Log::debug(sprintf('Now in AuthenticateController::index (/authenticate) with flow "%s"', $flow));

        if ('spectre' === $flow) {
            $validator = new SpectreValidator();
            $result    = $validator->validate();
            if (AuthenticationStatus::NODATA === $result) {
                // show for to enter data. save as cookie.
                Log::debug('Return view import.002-authenticate.index');

                return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'error'));
            }
            if (AuthenticationStatus::AUTHENTICATED === $result) {
                Log::debug(sprintf('Return redirect to %s', route('003-upload.index')));

                return redirect(route('003-upload.index'));
            }
        }

        if ('nordigen' === $flow) {
            $validator = new NordigenValidator();
            $result    = $validator->validate();
            if (AuthenticationStatus::NODATA === $result) {
                $key        = NordigenSecretManager::getKey();
                $identifier = NordigenSecretManager::getId();

                // show for to enter data. save as cookie.
                Log::debug('Return view import.002-authenticate.index');

                return view('import.002-authenticate.index')->with(compact('mainTitle', 'flow', 'subTitle', 'pageTitle', 'key', 'identifier'));
            }
            if (AuthenticationStatus::AUTHENTICATED === $result) {
                Log::debug(sprintf('Return redirect to %s', route('003-upload.index')));

                return redirect(route('003-upload.index'));
            }
        }

        if ('lunchflow' === $flow) {
            $validator = new LunchFlowValidator();
            $result    = $validator->validate();
            if (AuthenticationStatus::AUTHENTICATED === $result) {
                Log::debug(sprintf('Return redirect to %s', route('003-upload.index')));

                return redirect(route('003-upload.index'));
            }
        }

        if ('simplefin' === $flow) {
            // This case should ideally be handled by middleware redirecting to upload.
            // Adding explicit redirect here as a safeguard if middleware fails or is bypassed.
            Log::warning('AuthenticateController reached for simplefin flow; middleware redirect might have failed. Redirecting to upload.');
            Log::debug(sprintf('Return redirect to %s', route('003-upload.index')));

            return redirect(route('003-upload.index'));
        }
        Log::debug(sprintf('Throwing ImporterErrorException for flow "%s"', $flow ?? 'NULL'));

        throw new ImporterErrorException(sprintf('Impossible flow exception. Unexpected flow "%s" encountered.', $flow ?? 'NULL'));
    }

    /**
     * @return Application|Redirector|RedirectResponse
     *
     * @throws ImporterErrorException
     */
    public function postIndex(Request $request)
    {
        // variables for page:
        $flow = $request->cookie(Constants::FLOW_COOKIE);

        // set cookies and redirect, validator will pick it up.
        if ('spectre' === $flow) {
            $appId  = (string) $request->get('spectre_app_id');
            $secret = (string) $request->get('spectre_secret');
            if ('' === $appId || '' === $secret) {
                return redirect(route(self::AUTH_ROUTE))->with(['error' => 'Both fields must be filled in.']);
            }
            // give to secret manager to store:
            SpectreSecretManager::saveAppId($appId);
            SpectreSecretManager::saveSecret($secret);

            return redirect(route(self::AUTH_ROUTE));
        }
        if ('nordigen' === $flow) {
            $key        = $request->get('nordigen_key');
            $identifier = $request->get('nordigen_id');
            if ('' === $key || '' === $identifier) {
                return redirect(route(self::AUTH_ROUTE))->with(['error' => 'Both fields must be filled in.']);
            }
            // store ID and key in session:
            NordigenSecretManager::saveId($identifier);
            NordigenSecretManager::saveKey($key);

            return redirect(route(self::AUTH_ROUTE));
        }

        throw new ImporterErrorException('Impossible flow exception [b].');
    }
}
