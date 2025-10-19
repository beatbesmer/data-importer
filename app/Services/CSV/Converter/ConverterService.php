<?php

/*
 * ConverterService.php
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

namespace App\Services\CSV\Converter;

use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Class ConverterService
 */
class ConverterService
{
    /**
     * @param mixed $value
     */
    public static function convert(string $class, $value, ?string $configuration): mixed
    {
        if ('' === $class) {
            return $value;
        }
        if (self::exists($class)) {
            /** @var ConverterInterface $object */
            $object = app(self::fullName($class));
            Log::debug(sprintf('Created converter class %s', $class));
            if (null !== $configuration) {
                $object->setConfiguration($configuration);
            }

            return $object->convert($value);
        }

        throw new UnexpectedValueException(sprintf('No such converter: "%s"', $class));
    }

    public static function exists(string $class): bool
    {
        $name = self::fullName($class);

        return class_exists($name);
    }

    public static function fullName(string $class): string
    {
        return sprintf('App\Services\CSV\Converter\%s', $class);
    }
}
