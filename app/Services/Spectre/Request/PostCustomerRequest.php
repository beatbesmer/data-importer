<?php

/*
 * PostCustomerRequest.php
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

namespace App\Services\Spectre\Request;

use App\Exceptions\ImporterErrorException;
use App\Services\Shared\Response\Response;
use App\Services\Spectre\Response\PostCustomerResponse;

/**
 * Class PostCustomerRequest
 */
class PostCustomerRequest extends Request
{
    public string $identifier;

    /**
     * PostCustomerRequest constructor.
     */
    public function __construct(string $url, string $appId, string $secret)
    {
        $this->setBase($url);
        $this->setAppId($appId);
        $this->setSecret($secret);
        $this->setUrl('customers');
    }

    public function get(): Response
    {
        // Implement get() method.
    }

    /**
     * @throws ImporterErrorException
     * @throws ImporterErrorException
     */
    public function post(): Response
    {
        if ('' === $this->identifier) {
            throw new ImporterErrorException('No identifier for PostCustomerRequest');
        }
        $data     = [
            'data' => [
                'identifier' => $this->identifier,
            ],
        ];

        $response = $this->sendSignedSpectrePost($data);

        return new PostCustomerResponse($response['data']);
    }

    public function put(): Response
    {
        // Implement put() method.
    }
}
