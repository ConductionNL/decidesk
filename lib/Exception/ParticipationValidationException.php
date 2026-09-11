<?php

/**
 * Decidiq ParticipationValidationException
 *
 * Thrown when a citizen participation payload is well-formed but its values
 * fail validation, such as a budget proposal asking for more than the round
 * holds.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A participation value that fails validation. ParticipationResponder answers
 * it with HTTP 422. It extends \InvalidArgumentException so every caller that
 * already treats that as a client error keeps doing so.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */
class ParticipationValidationException extends \InvalidArgumentException {
}//end class
