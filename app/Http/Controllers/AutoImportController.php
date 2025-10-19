<?php

/*
 * AutoImportController.php
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

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Console\AutoImports;
use App\Console\HaveAccess;
use App\Console\VerifyJSON;
use App\Exceptions\ImporterErrorException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class AutoImportController extends Controller
{
    use AutoImports;
    use HaveAccess;
    use VerifyJSON;

    /**
     * @throws ImporterErrorException
     */
    public function index(Request $request): Response
    {
        if (false === config('importer.can_post_autoimport')) {
            throw new ImporterErrorException('Disabled, not allowed to import.');
        }

        $secret       = (string) ($request->get('secret') ?? '');
        $systemSecret = (string) config('importer.auto_import_secret');
        if ('' === $secret || '' === $systemSecret || $secret !== config('importer.auto_import_secret') || strlen($systemSecret) < 16) {
            throw new ImporterErrorException('Bad secret, not allowed to import.');
        }

        $argument     = (string) ($request->get('directory') ?? './');
        $directory    = realpath($argument);
        if (false === $directory) {
            throw new ImporterErrorException(sprintf('"%s" does not resolve to an existing real directory.', $argument));
        }

        if (!$this->isAllowedPath($directory)) {
            throw new ImporterErrorException('Not allowed to import from this path.');
        }

        $access       = $this->haveAccess();
        if (false === $access) {
            throw new ImporterErrorException(sprintf('Cannot connect, or denied access to your local Firefly III instance at %s.', config('importer.url')));
        }

        // take code from auto importer.
        Log::info(sprintf('[%s] Going to automatically import everything found in %s (%s)', config('importer.version'), $directory, $argument));

        $files        = $this->getFiles($directory);
        if (0 === count($files)) {
            return response('');
        }
        Log::info(sprintf('[%s] Found %d (importable +) JSON file sets in %s', config('importer.version'), count($files), $directory));

        try {
            $this->importFiles($directory, $files);
        } catch (ImporterErrorException $e) {
            Log::error(sprintf('[%s]: %s', config('importer.version'), $e->getMessage()));

            throw new ImporterErrorException(sprintf('Import exception (see the logs): %s', $e->getMessage()), 0, $e);
        }

        return response('');
    }

    public function info(mixed $string): void
    {
        $this->line($string);
    }

    public function line(string $string): void
    {
        echo sprintf("%s: %s\n", Carbon::now()->format('Y-m-d H:i:s'), $string);
    }

    public function error($string, $verbosity = null): void
    {
        Log::error($string);
        $this->line($string);
    }

    public function warn(mixed $string): void
    {
        $this->line($string);
    }
}
