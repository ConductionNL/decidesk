<?php

/**
 * Delegated Decision Defaults
 *
 * Derives the schema-required `title` and `text` for a Decision raised by
 * another fleet app. A flow-raised decision arrives without a body (the
 * delegation event carries the ask in its context payload), and a decision
 * must be schema-valid from birth: OpenRegister enforces the required
 * properties on every PUT, so an object created without them can never be
 * updated again (observed live: decision 7f2dc8f4).
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IL10N;

/**
 * Pure derivation of the required Decision title/text from a create payload.
 *
 * Priority for `text`: the supplied text, then the first usable
 * delegation-context field (question / reasoning / motivation / description),
 * then a translated fallback naming the source app and subject. Priority for
 * `title`: the supplied title, then the subject label, then a translated
 * fallback naming the source app.
 *
 * @spec openspec/specs/decidesk-decision-events/spec.md
 */
class DelegatedDecisionDefaults {

	/**
	 * Delegation-context fields that can stand in for a missing decision
	 * `text`, in priority order. Dossiq's requestDecision flow node sends the
	 * ask as `context.question`; the delegation services send `reasoning` /
	 * `motivation` / `description` depending on the decision kind.
	 *
	 * @var list<string>
	 */
	private const CONTEXT_TEXT_FIELDS = [
		'question',
		'reasoning',
		'motivation',
		'description',
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translator for the fallback sentences
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Resolve the decision `text`, which the schema requires on every Decision.
	 *
	 * @param array<string, mixed> $decisionData Request body / event payload
	 * @param array<string, string> $provenance The extracted provenance fields
	 *
	 * @return string A non-empty decision text
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function text(array $decisionData, array $provenance): string {
		$text = trim((string)($decisionData['text'] ?? ''));
		if ($text !== '') {
			return $text;
		}

		$context = ($decisionData['context'] ?? []);
		if (is_array($context) === true) {
			foreach (self::CONTEXT_TEXT_FIELDS as $field) {
				$value = ($context[$field] ?? '');
				if (is_string($value) === true && trim($value) !== '') {
					return trim($value);
				}
			}
		}

		$source = $this->describeSource(provenance: $provenance);
		$subject = $this->describeSubject(provenance: $provenance);
		if ($subject !== '') {
			return $this->l10n->t('Decision requested by %1$s for %2$s.', [$source, $subject]);
		}

		return $this->l10n->t('Decision requested by %1$s.', [$source]);
	}//end text()

	/**
	 * Resolve the decision `title`, which the schema requires on every
	 * Decision.
	 *
	 * @param array<string, mixed> $decisionData Request body / event payload
	 * @param array<string, string> $provenance The extracted provenance fields
	 *
	 * @return string A non-empty decision title
	 *
	 * @spec openspec/specs/decidesk-decision-events/spec.md
	 */
	public function title(array $decisionData, array $provenance): string {
		$title = trim((string)($decisionData['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}

		$subjectLabel = trim((string)($provenance['subjectLabel'] ?? ''));
		if ($subjectLabel !== '') {
			return $subjectLabel;
		}

		return $this->l10n->t('Decision requested by %1$s', [$this->describeSource(provenance: $provenance)]);
	}//end title()

	/**
	 * Human-readable name of the app that raised the decision.
	 *
	 * @param array<string, string> $provenance The extracted provenance fields
	 *
	 * @return string The source app id, or a translated placeholder
	 */
	private function describeSource(array $provenance): string {
		$sourceApp = trim((string)($provenance['sourceApp'] ?? ''));
		if ($sourceApp !== '') {
			return $sourceApp;
		}

		return $this->l10n->t('another app');
	}//end describeSource()

	/**
	 * Human-readable reference to the subject the decision was raised for:
	 * the label when the delegating app sent one, otherwise the subject
	 * schema/id pair, otherwise the external reference.
	 *
	 * @param array<string, string> $provenance The extracted provenance fields
	 *
	 * @return string The subject description, or '' when nothing identifies it
	 */
	private function describeSubject(array $provenance): string {
		$subjectLabel = trim((string)($provenance['subjectLabel'] ?? ''));
		if ($subjectLabel !== '') {
			return $subjectLabel;
		}

		$subjectRef = trim(
			trim((string)($provenance['subjectSchema'] ?? ''))
			. ' '
			. trim((string)($provenance['subjectId'] ?? ''))
		);
		if ($subjectRef !== '') {
			return $subjectRef;
		}

		return trim((string)($provenance['externalReference'] ?? ''));
	}//end describeSubject()
}//end class
