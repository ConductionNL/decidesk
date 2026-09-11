<?php

/**
 * Decidiq ParticipationWindowClosedException
 *
 * Thrown when a citizen participation action arrives outside the window that
 * accepts it: a consultation past its submission deadline, a budget round no
 * longer taking proposals, or a round that is not in its voting phase.
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
 * A participation action outside its window. ParticipationResponder answers it
 * with HTTP 400, as the p3-citizen-participation scenarios require.
 *
 * It extends \RuntimeException on purpose. The ANONYMOUS reaction endpoint
 * answers every non-validation refusal with one coarse 409, so a closed
 * consultation cannot be told apart from a missing one by someone probing
 * without an account (ParticipationAnonymousReactionTest). That endpoint
 * catches \Throwable after \InvalidArgumentException, so a \RuntimeException
 * subclass keeps it coarse, while the authenticated endpoints, which go
 * through ParticipationResponder, get the specific 400.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */
class ParticipationWindowClosedException extends \RuntimeException {
}//end class
